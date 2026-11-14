<?php

/**
 * Unit tests for BackupFiles.
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

use OCA\Hermiq\Service\Backup\BackupFiles;
use OCA\Hermiq\Service\Backup\BackupSettings;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Only backup files in the backup folder are listed and read.
 *
 * @covers \OCA\Hermiq\Service\Backup\BackupFiles
 * @uses   \OCA\Hermiq\Service\Backup\BackupSettings
 */
final class BackupFilesTest extends TestCase {

	/**
	 * A file in the folder.
	 *
	 * @param string $name    The name.
	 * @param string $content The raw content.
	 *
	 * @return File
	 */
	private function file(string $name, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		return $file;
	}//end file()

	/**
	 * The reader over a folder holding the given files, in the owner's Files.
	 *
	 * @param list<File> $files The files.
	 *
	 * @return BackupFiles
	 */
	private function reader(array $files): BackupFiles {
		$byName = [];
		foreach ($files as $file) {
			$byName[$file->getName()] = $file;
		}

		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($files);
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($byName[$name]));
		$folder->method('get')->willReturnCallback(fn (string $name) => $byName[$name]);

		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->with('Hermiq backups')->willReturn(true);
		$home->method('get')->with('Hermiq backups')->willReturn($folder);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('admin')->willReturn($home);

		return new BackupFiles(rootFolder: $root);
	}//end reader()

	/**
	 * A gzipped backup document.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return string
	 */
	private static function backup(string $organisation): string {
		return (string)gzencode((string)json_encode(['manifest' => ['organisation' => $organisation], 'objects' => ['agent' => [['name' => 'x']]]]));
	}//end backup()

	/**
	 * The list is newest first, holds only backups, and shows an unreadable one with no manifest.
	 *
	 * @return void
	 */
	public function testTheListIsNewestFirstWithEachManifest(): void {
		$reader = $this->reader(
			[
				$this->file('hermiq-backup-tilburg-2026-09-27-0200.json.gz', self::backup('tilburg')),
				$this->file('notes.txt', 'not a backup'),
				$this->file('hermiq-backup-breda-2026-09-28-0200.json.gz', 'broken'),
				$this->file('hermiq-backup-tilburg-2026-09-28-0200.json.gz', self::backup('tilburg')),
			]
		);

		$list = $reader->list(settings: new BackupSettings(ownerUid: 'admin'));

		$this->assertSame(
			[
				['name' => 'hermiq-backup-breda-2026-09-28-0200.json.gz', 'manifest' => []],
				['name' => 'hermiq-backup-tilburg-2026-09-28-0200.json.gz', 'manifest' => ['organisation' => 'tilburg']],
				['name' => 'hermiq-backup-tilburg-2026-09-27-0200.json.gz', 'manifest' => ['organisation' => 'tilburg']],
			],
			$list
		);
	}//end testTheListIsNewestFirstWithEachManifest()

	/**
	 * Reading returns the manifest and the objects.
	 *
	 * @return void
	 */
	public function testABackupIsRead(): void {
		$name = 'hermiq-backup-tilburg-2026-09-28-0200.json.gz';
		$backup = $this->reader([$this->file($name, self::backup('tilburg'))])->read(settings: new BackupSettings(ownerUid: 'admin'), name: $name);

		$this->assertSame(['organisation' => 'tilburg'], $backup['manifest']);
		$this->assertSame(['agent' => [['name' => 'x']]], $backup['objects']);
	}//end testABackupIsRead()

	/**
	 * A name that is not a backup's is never looked up.
	 *
	 * @return void
	 */
	public function testANameThatIsNotABackupIsRefused(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');

		$this->expectException(RuntimeException::class);
		(new BackupFiles(rootFolder: $root))->read(settings: new BackupSettings(ownerUid: 'admin'), name: '../../other/hermiq-backup-x-2026-09-28-0200.json.gz');
	}//end testANameThatIsNotABackupIsRefused()

	/**
	 * Without an owner there is no folder, and so nothing to list.
	 *
	 * @return void
	 */
	public function testWithoutAnOwnerThereAreNoBackups(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');

		$this->assertSame([], (new BackupFiles(rootFolder: $root))->list(settings: new BackupSettings()));
		$this->assertTrue(BackupFiles::isBackupName(name: 'hermiq-backup-tilburg-2026-09-28-0200.json.gz'));
		$this->assertFalse(BackupFiles::isBackupName(name: 'hermiq-backup-Tilburg-2026-09-28-0200.json.gz'));
	}//end testWithoutAnOwnerThereAreNoBackups()
}//end class
