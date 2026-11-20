<?php

/**
 * Unit tests for BackupWriter.
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
 * @spec openspec/changes/operations-backup-and-restore/specs/backup-restore/spec.md#requirement-an-administrator-can-back-up-an-organisations-hermiq-data-to-files-on-a-schedule-req-bkup-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Backup;

use DateTime;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCA\Hermiq\Service\Backup\BackupWriter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One file per organisation, holding only that organisation's objects.
 *
 * @covers \OCA\Hermiq\Service\Backup\BackupWriter
 * @uses   \OCA\Hermiq\Service\Backup\BackupSettings
 */
final class BackupWriterTest extends TestCase {

	/**
	 * Files written to the backup folder: name => decoded document.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Names already in the backup folder.
	 *
	 * @var list<string>
	 */
	private array $existing = [];

	/**
	 * Names deleted from the backup folder.
	 *
	 * @var list<string>
	 */
	private array $deleted = [];

	/**
	 * The schema slug the object service is set to.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * An object of one organisation.
	 *
	 * @param string $uuid         The uuid.
	 * @param string $organisation The organisation.
	 * @param array  $data         The object.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $organisation, array $data=[]): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setOrganisation($organisation);
		$object->setObject(['id' => $uuid] + $data);
		return $object;
	}//end object()

	/**
	 * The writer over a fixed set of objects per schema.
	 *
	 * @param array<string, list<ObjectEntity>> $bySchema The objects per schema slug.
	 * @param LoggerInterface|null              $logger   The logger.
	 *
	 * @return BackupWriter
	 */
	private function writer(array $bySchema, ?LoggerInterface $logger=null): BackupWriter {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function ($schema) use ($objects) {
				$this->schema = (string)$schema;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac, bool $_multitenancy) use ($bySchema): array {
				$this->assertFalse($_multitenancy, 'the job has no session; the boundary is drawn per object');
				if ($this->schema === 'evalrun') {
					throw new RuntimeException('table missing');
				}

				return array_slice(($bySchema[$this->schema] ?? []), (int)$config['offset'], (int)$config['limit']);
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, string $content) {
				$this->written[$name] = json_decode((string)gzdecode($content), true);
				$this->existing[] = $name;
				return $this->createMock(File::class);
			}
		);
		$folder->method('getDirectoryListing')->willReturnCallback(
			function (): array {
				return array_map(
					function (string $name): File {
						$file = $this->createMock(File::class);
						$file->method('getName')->willReturn($name);
						$file->method('delete')->willReturnCallback(function () use ($name): void { $this->deleted[] = $name; });
						return $file;
					},
					$this->existing
				);
			}
		);
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->with('Hermiq backups')->willReturn(true);
		$home->method('get')->with('Hermiq backups')->willReturn($folder);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('admin')->willReturn($home);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('1.4.0');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-28 02:00:00'));

		return new BackupWriter(
			objectService: $objects,
			rootFolder: $root,
			appManager: $apps,
			time: $time,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
		);
	}//end writer()

	/**
	 * Two organisations get one file each, with only their own objects, and the
	 * manifest counts match.
	 *
	 * @return void
	 */
	public function testEachOrganisationGetsItsOwnFileWithOnlyItsObjects(): void {
		$report = $this->writer(
			[
				'agent'  => [$this->object('a1', 'tilburg'), $this->object('a2', 'tilburg'), $this->object('a3', 'breda')],
				'memory' => [$this->object('m1', 'breda'), $this->object('m0', '')],
			]
		)->backUp(settings: new BackupSettings(enabled: true, ownerUid: 'admin'));

		$names = array_keys($this->written);
		sort($names);
		$this->assertSame(['hermiq-backup-breda-2026-09-28-0200.json.gz', 'hermiq-backup-tilburg-2026-09-28-0200.json.gz'], $names);
		$tilburg = $this->written['hermiq-backup-tilburg-2026-09-28-0200.json.gz'];
		$this->assertSame(['agent' => 2], $tilburg['manifest']['counts']);
		$this->assertSame(['a1', 'a2'], array_column($tilburg['objects']['agent'], 'id'));
		$this->assertSame('tilburg', $tilburg['manifest']['organisation']);
		$this->assertSame('1.4.0', $tilburg['manifest']['appVersion']);
		$this->assertNotSame('', $tilburg['manifest']['registerVersion']);
		$this->assertSame('2026-09-28T02:00:00+00:00', substr($tilburg['manifest']['createdAt'], 0, 19) . '+00:00');

		$breda = $this->written['hermiq-backup-breda-2026-09-28-0200.json.gz'];
		$counts = $breda['manifest']['counts'];
		ksort($counts);
		$this->assertSame(['agent' => 1, 'memory' => 1], $counts);
		$this->assertSame(['a3'], array_column($breda['objects']['agent'], 'id'));
		$organisations = array_keys($report);
		sort($organisations);
		$this->assertSame(['breda', 'tilburg'], $organisations);
		$this->assertStringNotContainsString('m0', json_encode($this->written), 'an object of no organisation is in no file');
	}//end testEachOrganisationGetsItsOwnFileWithOnlyItsObjects()

	/**
	 * Sessions and turns are left out unless included.
	 *
	 * @return void
	 */
	public function testSessionsAreLeftOutUnlessIncluded(): void {
		$data = [
			'agent'            => [$this->object('a1', 'tilburg')],
			'agentsession'     => [$this->object('s1', 'tilburg')],
			'agentsessionturn' => [$this->object('t1', 'tilburg')],
		];

		$this->writer($data)->backUp(settings: new BackupSettings(ownerUid: 'admin'));
		$file = array_values($this->written)[0];
		$this->assertSame(['agent'], array_keys($file['objects']));

		$this->written = [];
		$this->existing = [];
		$this->writer($data)->backUp(settings: new BackupSettings(includeSessions: true, ownerUid: 'admin'));
		$file = array_values($this->written)[0];
		$counts = $file['manifest']['counts'];
		ksort($counts);
		$this->assertSame(['agent' => 1, 'agentsession' => 1, 'agentsessionturn' => 1], $counts);
	}//end testSessionsAreLeftOutUnlessIncluded()

	/**
	 * With 15 backups and keep 14, the oldest is deleted; other organisations'
	 * files and other files are never touched.
	 *
	 * @return void
	 */
	public function testBackupsBeyondTheNumberToKeepAreDeletedOldestFirst(): void {
		for ($day = 14; $day <= 27; $day++) {
			$this->existing[] = sprintf('hermiq-backup-tilburg-2026-09-%02d-0200.json.gz', $day);
		}

		$this->existing[] = 'hermiq-backup-breda-2026-09-01-0200.json.gz';
		$this->existing[] = 'notes.txt';

		$this->writer(['agent' => [$this->object('a1', 'tilburg')]])->backUp(settings: new BackupSettings(keep: 14, ownerUid: 'admin'));

		$this->assertSame(['hermiq-backup-tilburg-2026-09-14-0200.json.gz'], $this->deleted);
	}//end testBackupsBeyondTheNumberToKeepAreDeletedOldestFirst()

	/**
	 * The schema set is every declared schema, including those the register's
	 * schemas list leaves out; the chats only when included.
	 *
	 * @return void
	 */
	public function testTheSchemaSetIsEveryDeclaredSchema(): void {
		$writer = $this->writer([]);
		$descriptor = $writer->descriptor();
		$slugs = $writer->schemaSlugs(descriptor: $descriptor, includeSessions: false);

		$this->assertCount(count($descriptor['components']['schemas']) - 4, $slugs);
		foreach (['agentoutsideregistration', 'agentassistantprompt', 'agentreportgroup', 'agentintakeconversation', 'agent', 'tenantcontrol'] as $slug) {
			$this->assertContains($slug, $slugs);
		}

		foreach (BackupWriter::SESSION_SLUGS as $slug) {
			$this->assertNotContains($slug, $slugs);
			$this->assertContains($slug, $writer->schemaSlugs(descriptor: $descriptor, includeSessions: true));
		}
	}//end testTheSchemaSetIsEveryDeclaredSchema()

	/**
	 * A schema that cannot be read is logged and the rest is still backed up.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaIsLoggedAndTheRestIsBackedUp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('could not be read'), $this->callback(static fn (array $c): bool => $c['schema'] === 'evalrun'));

		$this->writer(['agent' => [$this->object('a1', 'tilburg')]], $logger)->backUp(settings: new BackupSettings(ownerUid: 'admin'));

		$this->assertCount(1, $this->written);
	}//end testAnUnreadableSchemaIsLoggedAndTheRestIsBackedUp()

	/**
	 * Without an administrator owning the folder nothing is written.
	 *
	 * @return void
	 */
	public function testNoOwnerNoBackup(): void {
		$this->expectException(RuntimeException::class);
		$this->writer(['agent' => [$this->object('a1', 'tilburg')]])->backUp(settings: new BackupSettings());
	}//end testNoOwnerNoBackup()

	/**
	 * The organisation in a file name is lower case letters, digits and dashes.
	 *
	 * @return void
	 */
	public function testTheFileNameIsSafe(): void {
		$this->assertSame('hermiq-backup-gemeente-tilburg-x-2026-09-28-0200.json.gz', BackupWriter::fileName(organisation: 'Gemeente Tilburg/../x', stamp: new DateTime('2026-09-28 02:00')));
		$this->assertSame('organisation', BackupWriter::token(organisation: '///'));
	}//end testTheFileNameIsSafe()
}//end class
