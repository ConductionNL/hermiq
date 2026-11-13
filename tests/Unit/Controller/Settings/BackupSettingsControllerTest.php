<?php

/**
 * Unit tests for BackupSettingsController.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
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

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\BackgroundJob\BackupNowJob;
use OCA\Hermiq\Controller\Settings\BackupSettingsController;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCA\Hermiq\Service\Backup\BackupSettingsStore;
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

/**
 * Backup settings and "Back up now" are for administrators only.
 *
 * @covers \OCA\Hermiq\Controller\Settings\BackupSettingsController
 * @uses   \OCA\Hermiq\Service\Backup\BackupSettings
 */
final class BackupSettingsControllerTest extends TestCase {

	private IRequest&MockObject $request;

	private BackupSettingsStore&MockObject $store;

	private IJobList&MockObject $jobs;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->store = $this->createMock(BackupSettingsStore::class);
		$this->jobs = $this->createMock(IJobList::class);
	}//end setUp()

	/**
	 * The controller with an administrator signed in.
	 *
	 * @return BackupSettingsController
	 */
	private function controller(): BackupSettingsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new BackupSettingsController(
			request: $this->request,
			store: $this->store,
			jobList: $this->jobs,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * Every action requires the admin setting, and none is opened to non-admins or the public.
	 * Nextcloud's middleware answers 403 to anyone else before the action runs.
	 *
	 * @return void
	 */
	public function testEveryActionIsForAdministratorsOnly(): void {
		$actions = array_filter(
			(new ReflectionClass(BackupSettingsController::class))->getMethods(ReflectionMethod::IS_PUBLIC),
			static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === BackupSettingsController::class && $m->isConstructor() === false
		);
		$this->assertSame(['get', 'update', 'backupNow'], array_values(array_map(static fn (ReflectionMethod $m): string => $m->getName(), $actions)));

		foreach ($actions as $action) {
			$admin = $action->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $admin, $action->getName());
			$this->assertSame(AdminSettings::class, $admin[0]->getArguments()[0], $action->getName());
			$this->assertSame([], $action->getAttributes(NoAdminRequired::class), $action->getName());
			$this->assertSame([], $action->getAttributes(PublicPage::class), $action->getName());
			$this->assertStringNotContainsString('@NoAdminRequired', (string)$action->getDocComment(), $action->getName());
		}
	}//end testEveryActionIsForAdministratorsOnly()

	/**
	 * Saving stores the folder, hour, keep and sessions choice, in the saving administrator's Files.
	 *
	 * @return void
	 */
	public function testSavingStoresTheChoicesWithTheAdministratorAsOwner(): void {
		$this->request->method('getParams')->willReturn(['enabled' => true, 'folder' => 'Back-ups', 'hour' => 3, 'keep' => 7, 'includeSessions' => false, 'ownerUid' => 'someone-else']);
		$this->store->expects($this->once())->method('save')->with(
			$this->callback(static fn (BackupSettings $s): bool => $s->toArray() === ['enabled' => true, 'folder' => 'Back-ups', 'hour' => 3, 'keep' => 7, 'includeSessions' => false, 'ownerUid' => 'admin'])
		)->willReturnArgument(0);

		$response = $this->controller()->update();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('admin', $response->getData()['ownerUid']);
	}//end testSavingStoresTheChoicesWithTheAdministratorAsOwner()

	/**
	 * A value out of range is 422 and nothing is stored.
	 *
	 * @return void
	 */
	public function testAValueOutOfRangeIsRefused(): void {
		$this->request->method('getParams')->willReturn(['hour' => 25]);
		$this->store->expects($this->never())->method('save');

		$response = $this->controller()->update();

		$this->assertSame(422, $response->getStatus());
		$this->assertStringContainsString('between 0 and 23', $response->getData()['error']);
	}//end testAValueOutOfRangeIsRefused()

	/**
	 * Reading answers the stored settings.
	 *
	 * @return void
	 */
	public function testReadingAnswersTheStoredSettings(): void {
		$this->store->method('get')->willReturn(new BackupSettings(enabled: true, keep: 5));

		$this->assertSame(5, $this->controller()->get()->getData()['keep']);
	}//end testReadingAnswersTheStoredSettings()

	/**
	 * "Back up now" queues the job and answers at once.
	 *
	 * @return void
	 */
	public function testBackupNowQueuesTheJob(): void {
		$this->store->method('get')->willReturn(new BackupSettings(ownerUid: 'admin'));
		$this->jobs->expects($this->once())->method('add')->with(BackupNowJob::class);

		$response = $this->controller()->backupNow();

		$this->assertSame(202, $response->getStatus());
	}//end testBackupNowQueuesTheJob()

	/**
	 * Without saved settings there is no folder, so nothing is queued.
	 *
	 * @return void
	 */
	public function testBackupNowWithoutAFolderQueuesNothing(): void {
		$this->store->method('get')->willReturn(new BackupSettings());
		$this->jobs->expects($this->never())->method('add');

		$this->assertSame(409, $this->controller()->backupNow()->getStatus());
	}//end testBackupNowWithoutAFolderQueuesNothing()
}//end class
