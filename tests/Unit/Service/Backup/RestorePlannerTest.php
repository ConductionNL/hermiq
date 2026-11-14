<?php

/**
 * Unit tests for RestorePlanner.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Backup
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Backup;

use DateTime;
use DateTimeImmutable;
use OCA\Hermiq\Service\Backup\BackupFiles;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCA\Hermiq\Service\Backup\BackupWriter;
use OCA\Hermiq\Service\Backup\RestorePlanner;
use OCA\Hermiq\Service\Backup\RestorePoint;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The preview counts what a restore would change and writes nothing.
 *
 * @covers \OCA\Hermiq\Service\Backup\RestorePlanner
 * @uses   \OCA\Hermiq\Service\Backup\RestorePoint
 * @uses   \OCA\Hermiq\Service\Backup\BackupSettings
 * @uses   \OCA\Hermiq\Service\Backup\BackupWriter
 * @uses   \OCA\Hermiq\Service\Backup\BackupFiles
 */
final class RestorePlannerTest extends TestCase {

	private const FILE = 'hermiq-backup-tilburg-2026-09-28-0200.json.gz';

	/**
	 * The schema slug the object service is set to.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The register descriptor file.
	 *
	 * @var string
	 */
	private string $descriptorFile = '';

	/**
	 * Write a small register descriptor: agents and skills.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->descriptorFile = (string)tempnam(sys_get_temp_dir(), 'hermiq-register-');
		file_put_contents(
			$this->descriptorFile,
			(string)json_encode(
				[
					'info'       => ['version' => '0.54.0'],
					'components' => [
						'schemas' => [
							'Agent'   => ['slug' => 'agent', 'properties' => ['name' => [], 'prompt' => []]],
							'Skill'   => ['slug' => 'agentskill', 'properties' => ['name' => []]],
							'Session' => ['slug' => 'agentsession', 'properties' => ['title' => []]],
						],
					],
				]
			)
		);
	}//end setUp()

	/**
	 * Remove the descriptor file.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->descriptorFile);
		parent::tearDown();
	}//end tearDown()

	/**
	 * An object of one organisation.
	 *
	 * @param string $uuid         The uuid.
	 * @param string $organisation The organisation.
	 * @param array  $data         The content.
	 * @param string $created      When it was created.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $organisation, array $data, string $created='2026-09-01T00:00:00Z'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setOrganisation($organisation);
		$object->setObject($data);
		$object->setCreated(new DateTime($created));
		return $object;
	}//end object()

	/**
	 * A change entry of the audit trail.
	 *
	 * @param string $action  create or update.
	 * @param string $when    When.
	 * @param array  $changed The recorded change.
	 *
	 * @return AuditTrail
	 */
	private function entry(string $action, string $when, array $changed=[]): AuditTrail {
		$entry = new AuditTrail();
		$entry->setAction($action);
		$entry->setCreated(new DateTime($when));
		$entry->setChanged($changed);
		return $entry;
	}//end entry()

	/**
	 * The planner over fixed live objects, a backup and audit entries.
	 *
	 * @param array<string, list<ObjectEntity>> $live    Live objects per schema slug.
	 * @param array<string, mixed>|null         $backup  The backup file, when one is read.
	 * @param array<string, list<AuditTrail>>   $entries Change entries per object uuid.
	 * @param ObjectService|null                $objects An object service to use instead.
	 *
	 * @return RestorePlanner
	 */
	private function planner(array $live, ?array $backup=null, array $entries=[], ?ObjectService $objects=null): RestorePlanner {
		if ($objects === null) {
			$objects = $this->createMock(ObjectService::class);
			$objects->expects($this->never())->method('saveObject');
			$objects->expects($this->never())->method('deleteObject');
		}

		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function ($schema) use ($objects) {
				$this->schema = (string)$schema;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config): array => array_slice(($live[$this->schema] ?? []), (int)$config['offset'], (int)$config['limit'])
		);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('findAll')->willReturnCallback(
			function (?int $limit=null, ?int $offset=null, ?array $filters=[]) use ($entries): array {
				$this->assertSame('create,update', $filters['action']);
				return ($entries[$filters['object_uuid']] ?? []);
			}
		);

		$files = $this->createMock(BackupFiles::class);
		if ($backup !== null) {
			$files->method('read')->with($this->anything(), self::FILE)->willReturn($backup);
		}

		$writer = new BackupWriter(
			objectService: $this->createMock(ObjectService::class),
			rootFolder: $this->createMock(IRootFolder::class),
			appManager: $this->createMock(IAppManager::class),
			time: $this->createMock(ITimeFactory::class),
			logger: $this->createMock(LoggerInterface::class),
			registerFile: $this->descriptorFile,
		);

		return new RestorePlanner(
			objectService: $objects,
			auditTrail: $audit,
			files: $files,
			writer: $writer,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end planner()

	/**
	 * A backup of Tilburg written at 02:00 on 28 September.
	 *
	 * @param array<string, list<array<string, mixed>>> $objects Objects per slug.
	 * @param string                                    $version The register version that wrote it.
	 *
	 * @return array<string, mixed>
	 */
	private function backup(array $objects, string $version='0.54.0'): array {
		return [
			'manifest' => ['createdAt' => '2026-09-28T02:00:00+00:00', 'registerVersion' => $version, 'organisation' => 'tilburg', 'schemas' => array_keys($objects)],
			'objects'  => $objects,
		];
	}//end backup()

	/**
	 * A backup point.
	 *
	 * @return RestorePoint
	 */
	private function backupPoint(): RestorePoint {
		return RestorePoint::fromArray(values: ['kind' => 'backup', 'file' => self::FILE]);
	}//end backupPoint()

	/**
	 * The scenario of the spec: a bad bulk edit to agents, one agent created after the backup.
	 *
	 * @return void
	 */
	public function testAChangedAgentIsCountedAndNothingIsWritten(): void {
		$backup = $this->backup(
			[
				'agent' => [
					['name' => 'Intake', 'prompt' => 'Be kind', '@self' => ['id' => 'a-1', 'organisation' => 'tilburg']],
					['name' => 'Router', 'prompt' => 'Route', '@self' => ['id' => 'a-2', 'organisation' => 'tilburg']],
					['name' => 'Gone', 'prompt' => 'Deleted since', '@self' => ['id' => 'a-3', 'organisation' => 'tilburg']],
				],
			]
		);
		$live = [
			'agent' => [
				$this->object('a-1', 'tilburg', ['prompt' => 'Be rude', 'name' => 'Intake']),
				$this->object('a-2', 'tilburg', ['prompt' => 'Route', 'name' => 'Router']),
				$this->object('a-4', 'tilburg', ['name' => 'New', 'prompt' => 'x'], '2026-09-28T09:00:00Z'),
				$this->object('a-5', 'tilburg', ['name' => 'Old', 'prompt' => 'y'], '2026-09-01T00:00:00Z'),
				$this->object('b-1', 'breda', ['name' => 'Elsewhere', 'prompt' => 'z']),
			],
		];

		$preview = $this->planner(live: $live, backup: $backup)->preview(point: $this->backupPoint(), settings: new BackupSettings(ownerUid: 'admin'));

		$this->assertNull($preview['refused']);
		$this->assertSame('tilburg', $preview['organisation']);
		$this->assertSame(
			['putBack' => 1, 'changed' => 1, 'unchanged' => 1, 'createdAfter' => 1, 'missing' => 1, 'noHistory' => 0, 'notRestorable' => []],
			$preview['schemas']['agent']
		);
		$this->assertSame(2, $preview['writes']);
		$this->assertSame(1, $preview['createdAfter']);
	}//end testAChangedAgentIsCountedAndNothingIsWritten()

	/**
	 * The plan writes the backup's content for the changed and the gone objects.
	 *
	 * @return void
	 */
	public function testThePlanWritesTheBackedUpContentAndDropsWhatTheSchemaNoLongerHas(): void {
		$backup = $this->backup(
			[
				'agent' => [
					['name' => 'Intake', 'prompt' => 'Be kind', 'voiceMode' => 'old', '@self' => ['id' => 'a-1']],
				],
			]
		);
		$live = ['agent' => [$this->object('a-1', 'tilburg', ['name' => 'Intake', 'prompt' => 'Be rude'])]];

		$plan = $this->planner(live: $live, backup: $backup)->plan(point: $this->backupPoint(), settings: new BackupSettings(ownerUid: 'admin'));

		$this->assertSame([['schema' => 'agent', 'uuid' => 'a-1', 'object' => ['name' => 'Intake', 'prompt' => 'Be kind']]], $plan['writes']);
		$this->assertSame(['voiceMode'], $plan['schemas']['agent']['notRestorable']);
	}//end testThePlanWritesTheBackedUpContentAndDropsWhatTheSchemaNoLongerHas()

	/**
	 * A backup from a newer register version is refused, before anything is read.
	 *
	 * @return void
	 */
	public function testABackupFromANewerRegisterIsRefused(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('findAll');
		$objects->expects($this->never())->method('saveObject');
		$backup = $this->backup(['agent' => [['name' => 'x', '@self' => ['id' => 'a-1']]]], '0.60.0');

		$plan = $this->planner(live: [], backup: $backup, objects: $objects)->plan(point: $this->backupPoint(), settings: new BackupSettings(ownerUid: 'admin'));

		$this->assertStringContainsString('0.60.0', (string)$plan['refused']);
		$this->assertSame([], $plan['writes']);
	}//end testABackupFromANewerRegisterIsRefused()

	/**
	 * A moment: changes after it are undone, an object created after it is listed,
	 * and one without history at that time is left alone.
	 *
	 * @return void
	 */
	public function testAMomentRebuildsEachObjectFromItsAuditTrail(): void {
		$live = [
			'agent' => [
				$this->object('a-1', 'tilburg', ['name' => 'Intake', 'prompt' => 'Be rude', 'tone' => 'loud']),
				$this->object('a-2', 'tilburg', ['name' => 'New', 'prompt' => 'x']),
				$this->object('a-3', 'tilburg', ['name' => 'Untracked', 'prompt' => 'y']),
				$this->object('a-4', 'tilburg', ['name' => 'Same', 'prompt' => 'z']),
			],
		];
		$entries = [
			'a-1' => [
				$this->entry('create', '2026-09-01T00:00:00Z'),
				$this->entry('update', '2026-09-28T09:00:00Z', ['prompt' => ['old' => 'Be kind', 'new' => 'Be rude'], 'tone' => ['old' => null, 'new' => 'loud']]),
			],
			'a-2' => [$this->entry('create', '2026-09-28T10:00:00Z')],
			'a-4' => [$this->entry('create', '2026-09-01T00:00:00Z')],
		];
		$point = RestorePoint::fromArray(values: ['kind' => 'moment', 'organisation' => 'tilburg', 'at' => '2026-09-28T02:00:00Z']);

		$plan = $this->planner(live: $live, entries: $entries)->plan(point: $point, settings: new BackupSettings(ownerUid: 'admin'));

		$this->assertSame(
			['putBack' => 0, 'changed' => 1, 'unchanged' => 1, 'createdAfter' => 1, 'missing' => 0, 'noHistory' => 1, 'notRestorable' => []],
			$plan['schemas']['agent']
		);
		$this->assertSame([['schema' => 'agent', 'uuid' => 'a-1', 'object' => ['name' => 'Intake', 'prompt' => 'Be kind']]], $plan['writes']);
		$this->assertSame([['schema' => 'agent', 'uuid' => 'a-2']], $plan['createdAfter']);
		$this->assertArrayNotHasKey('agentsession', $plan['schemas'], 'sessions are left out unless the backup settings include them');
	}//end testAMomentRebuildsEachObjectFromItsAuditTrail()

	/**
	 * History that starts after the moment, without a create, does not reach it.
	 *
	 * @return void
	 */
	public function testHistoryThatStartsAfterTheMomentDoesNotReachIt(): void {
		$planner = $this->planner(live: []);

		$state = $planner->stateAt(
			live: ['prompt' => 'b'],
			entries: [$this->entry('update', '2026-09-28T09:00:00Z', ['prompt' => ['old' => 'a', 'new' => 'b']])],
			at: new DateTimeImmutable('2026-09-28T02:00:00Z')
		);

		$this->assertNull($state);
	}//end testHistoryThatStartsAfterTheMomentDoesNotReachIt()
}//end class
