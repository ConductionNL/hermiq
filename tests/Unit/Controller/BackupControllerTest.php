<?php

/**
 * Unit tests for BackupController.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-backup-and-restore-are-for-administrators-only-req-bkup-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\BackgroundJob\RestoreJob;
use OCA\Hermiq\Controller\BackupController;
use OCA\Hermiq\Service\Backup\BackupFiles;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCA\Hermiq\Service\Backup\BackupSettingsStore;
use OCA\Hermiq\Service\Backup\RestorePlanner;
use OCA\Hermiq\Service\Backup\RestorePoint;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Admin-only restore routes: list, preview, queue.
 *
 * @covers \OCA\Hermiq\Controller\BackupController
 * @uses   \OCA\Hermiq\Service\Backup\RestorePoint
 * @uses   \OCA\Hermiq\Service\Backup\BackupSettings
 * @uses   \OCA\Hermiq\Service\Backup\BackupFiles
 */
final class BackupControllerTest extends TestCase {

	private const FILE = 'hermiq-backup-tilburg-2026-09-28-0200.json.gz';

	private IRequest&MockObject $request;

	private RestorePlanner&MockObject $planner;

	private BackupFiles&MockObject $files;

	private IJobList&MockObject $jobs;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->planner = $this->createMock(RestorePlanner::class);
		$this->files = $this->createMock(BackupFiles::class);
		$this->jobs = $this->createMock(IJobList::class);
	}//end setUp()

	/**
	 * The controller with an administrator signed in.
	 *
	 * @return BackupController
	 */
	private function controller(): BackupController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$store = $this->createMock(BackupSettingsStore::class);
		$store->method('get')->willReturn(new BackupSettings(ownerUid: 'admin'));

		return new BackupController(
			request: $this->request,
			settings: $store,
			files: $this->files,
			planner: $this->planner,
			jobList: $this->jobs,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A preview of Tilburg's backup.
	 *
	 * @param string|null $refused A refusal.
	 *
	 * @return array<string, mixed>
	 */
	private static function preview(?string $refused=null): array {
		return [
			'point'        => ['kind' => 'backup', 'organisation' => 'tilburg', 'file' => self::FILE, 'at' => null, 'removeCreatedAfter' => true],
			'organisation' => 'tilburg',
			'refused'      => $refused,
			'schemas'      => ['agent' => ['changed' => 12, 'createdAfter' => 1]],
			'writes'       => 12,
			'createdAfter' => 1,
		];
	}//end preview()

	/**
	 * Every action requires the admin setting, and none is opened to non-admins or the public.
	 * Nextcloud's middleware answers 403 to anyone else before the action runs, so no job is queued.
	 *
	 * @return void
	 */
	public function testEveryActionIsForAdministratorsOnly(): void {
		$actions = array_filter(
			(new ReflectionClass(BackupController::class))->getMethods(ReflectionMethod::IS_PUBLIC),
			static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === BackupController::class && $m->isConstructor() === false
		);
		$this->assertSame(['files', 'preview', 'restore'], array_values(array_map(static fn (ReflectionMethod $m): string => $m->getName(), $actions)));

		foreach ($actions as $action) {
			$admin = $action->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $admin, $action->getName());
			$this->assertSame(AdminSettings::class, $admin[0]->getArguments()[0], $action->getName());
			$this->assertSame([], $action->getAttributes(NoAdminRequired::class), $action->getName());
			$this->assertSame([], $action->getAttributes(PublicPage::class), $action->getName());
		}
	}//end testEveryActionIsForAdministratorsOnly()

	/**
	 * The list answers the backups in the folder.
	 *
	 * @return void
	 */
	public function testTheBackupsAreListed(): void {
		$this->files->method('list')->willReturn([['name' => self::FILE, 'manifest' => ['organisation' => 'tilburg']]]);

		$response = $this->controller()->files();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(self::FILE, $response->getData()['backups'][0]['name']);
	}//end testTheBackupsAreListed()

	/**
	 * The preview answers the counts and queues nothing.
	 *
	 * @return void
	 */
	public function testThePreviewAnswersTheCountsAndQueuesNothing(): void {
		$this->request->method('getParams')->willReturn(['kind' => 'backup', 'file' => self::FILE]);
		$this->planner->expects($this->once())->method('preview')->with(
			$this->callback(static fn (RestorePoint $p): bool => $p->kind === 'backup' && $p->file === self::FILE)
		)->willReturn(self::preview());
		$this->jobs->expects($this->never())->method('add');

		$response = $this->controller()->preview();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(12, $response->getData()['schemas']['agent']['changed']);
	}//end testThePreviewAnswersTheCountsAndQueuesNothing()

	/**
	 * A point that cannot be understood is 422; a backup that is not there is 404.
	 *
	 * @return void
	 */
	public function testABadPointOrAMissingBackupIsRefused(): void {
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(['kind' => 'tomorrow'], ['kind' => 'backup', 'file' => self::FILE]);
		$this->planner->method('preview')->willThrowException(new RuntimeException('The backup is not in the backup folder.'));
		$this->jobs->expects($this->never())->method('add');

		$this->assertSame(422, $this->controller()->preview()->getStatus());
		$this->assertSame(404, $this->controller()->restore()->getStatus());
	}//end testABadPointOrAMissingBackupIsRefused()

	/**
	 * A restore queues the job with the point and the administrator, and answers at once.
	 *
	 * @return void
	 */
	public function testARestoreIsQueued(): void {
		$this->request->method('getParams')->willReturn(['kind' => 'backup', 'file' => self::FILE, 'removeCreatedAfter' => true]);
		$this->planner->method('preview')->willReturn(self::preview());
		$this->jobs->expects($this->once())->method('add')->with(
			RestoreJob::class,
			[
				'point'    => ['kind' => 'backup', 'organisation' => 'tilburg', 'file' => self::FILE, 'at' => null, 'removeCreatedAfter' => true],
				'adminUid' => 'admin',
			]
		);

		$response = $this->controller()->restore();

		$this->assertSame(202, $response->getStatus());
		$this->assertSame(['queued' => true, 'organisation' => 'tilburg', 'writes' => 12], $response->getData());
	}//end testARestoreIsQueued()

	/**
	 * A refused point (a newer register version) is 409 and nothing is queued.
	 *
	 * @return void
	 */
	public function testARefusedRestoreIsNotQueued(): void {
		$this->request->method('getParams')->willReturn(['kind' => 'backup', 'file' => self::FILE]);
		$this->planner->method('preview')->willReturn(self::preview(refused: 'newer register version'));
		$this->jobs->expects($this->never())->method('add');

		$response = $this->controller()->restore();

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('newer register version', $response->getData()['refused']);
	}//end testARefusedRestoreIsNotQueued()
}//end class
