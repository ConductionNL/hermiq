<?php

/**
 * Unit tests for the file tools on the NC-native write facade.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 *
 * @spec openspec/changes/spec-coverage-built-rows/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\NcNative\CalendarWriteService;
use OCA\Hermiq\Service\NcNative\ContactWriteService;
use OCA\Hermiq\Service\NcNative\FilesWriteService;
use OCA\Hermiq\Service\NcNative\NcNativeWriteService;
use OCA\Hermiq\Service\NcNative\NotesWriteService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\NcNative\NcNativeWriteService
 */
class NcNativeWriteServiceFilesTest extends TestCase {

	/**
	 * The facade over these file services.
	 *
	 * @param FilesWriteService|null $files The file service.
	 *
	 * @return NcNativeWriteService
	 */
	private function facade(?FilesWriteService $files): NcNativeWriteService {
		return new NcNativeWriteService(
			$this->createMock(CalendarWriteService::class),
			$this->createMock(ContactWriteService::class),
			$this->createMock(NotesWriteService::class),
			$files
		);
	}

	public function testDelegatesCreateAndMoveToTheFileService(): void {
		$files = $this->createMock(FilesWriteService::class);
		$files->expects($this->once())->method('createFile')->with('alice', ['path' => 'a.txt'])->willReturn(['created' => true]);
		$files->expects($this->once())->method('moveFile')->with('alice', ['from' => 'a.txt', 'to' => 'b.txt'])->willReturn(['moved' => true]);
		$facade = $this->facade($files);

		$this->assertSame(['created' => true], $facade->createFile('alice', ['path' => 'a.txt']));
		$this->assertSame(['moved' => true], $facade->moveFile('alice', ['from' => 'a.txt', 'to' => 'b.txt']));
	}

	public function testRefusesWithoutAFileService(): void {
		$facade = $this->facade(null);

		$this->assertSame('files_unavailable', $facade->createFile('alice', ['path' => 'a.txt'])['error']['code']);
		$this->assertSame('files_unavailable', $facade->moveFile('alice', ['from' => 'a', 'to' => 'b'])['error']['code']);
	}
}
