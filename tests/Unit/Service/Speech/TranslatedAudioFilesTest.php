<?php

/**
 * Hermiq TranslatedAudioFiles: a recording is read only from the person's own Files.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Speech
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
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Speech;

use OCA\Hermiq\Service\Speech\TranslatedAudioFiles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for reading the recording.
 */
final class TranslatedAudioFilesTest extends TestCase {

	/**
	 * Files over a root where `anne` holds file 41 and `bram` holds nothing.
	 *
	 * @param bool $readable Whether file 41 is readable.
	 *
	 * @return TranslatedAudioFiles The files.
	 */
	private function files(bool $readable = true): TranslatedAudioFiles {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn($readable);
		$file->method('getContent')->willReturn('m4a-bytes');
		$file->method('getName')->willReturn('terugbelverzoek.m4a');
		$file->method('getSize')->willReturn(9);

		$anne = $this->createMock(Folder::class);
		$anne->method('getFirstNodeById')->willReturnCallback(static fn (int $id): ?File => ($id === 41 ? $file : null));
		$bram = $this->createMock(Folder::class);
		$bram->method('getFirstNodeById')->willReturn(null);
		$carol = $this->createMock(Folder::class);
		$carol->method('getFirstNodeById')->willThrowException(new NotPermittedException('no'));

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			static fn (string $uid): Folder => match ($uid) {
				'anne' => $anne,
				'bram' => $bram,
				default => $carol,
			}
		);
		return new TranslatedAudioFiles(rootFolder: $root);
	}//end files()

	/**
	 * The owner reads their recording; another person, a failing lookup and an unreadable file read as absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function testARecordingIsReadOnlyFromTheCallersOwnFiles(): void {
		$this->assertSame(
			['bytes' => 'm4a-bytes', 'name' => 'terugbelverzoek.m4a', 'size' => 9],
			$this->files()->readRecording(uid: 'anne', fileId: 41)
		);
		$this->assertNull($this->files()->readRecording(uid: 'bram', fileId: 41));
		$this->assertNull($this->files()->readRecording(uid: 'carol', fileId: 41));
		$this->assertNull($this->files(readable: false)->readRecording(uid: 'anne', fileId: 41));
		$this->assertNull($this->files()->readRecording(uid: 'anne', fileId: 0));
		$this->assertNull($this->files()->readRecording(uid: '', fileId: 41));
	}//end testARecordingIsReadOnlyFromTheCallersOwnFiles()
}//end class
