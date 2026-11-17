<?php

/**
 * FolderPath walks a path under a folder, creating what is missing, reusing
 * what exists and refusing a segment taken by a file.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Files
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Files;

use OCA\Hermiq\Service\Files\FolderPath;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for FolderPath.
 */
#[CoversClass(FolderPath::class)]
class FolderPathTest extends TestCase {

	public function testItCreatesEveryMissingSegmentInOrder(): void {
		$leaf   = $this->createMock(Folder::class);
		$middle = $this->createMock(Folder::class);
		$middle->method('nodeExists')->with('b')->willReturn(false);
		$middle->expects($this->once())->method('newFolder')->with('b')->willReturn($leaf);
		$root = $this->createMock(Folder::class);
		$root->method('nodeExists')->with('a')->willReturn(false);
		$root->expects($this->once())->method('newFolder')->with('a')->willReturn($middle);

		$this->assertSame($leaf, FolderPath::ensure(parent: $root, path: ['a', 'b'], inTheWay: static fn (): RuntimeException => new RuntimeException('in the way')));
	}

	public function testItReusesAnExistingFolderWithoutCreatingIt(): void {
		$existing = $this->createMock(Folder::class);
		$root     = $this->createMock(Folder::class);
		$root->method('nodeExists')->with('a')->willReturn(true);
		$root->method('get')->with('a')->willReturn($existing);
		$root->expects($this->never())->method('newFolder');

		$this->assertSame($existing, FolderPath::ensure(parent: $root, path: ['a'], inTheWay: static fn (): RuntimeException => new RuntimeException('in the way')));
	}

	public function testAnEmptyPathIsTheParent(): void {
		$root = $this->createMock(Folder::class);
		$root->expects($this->never())->method('nodeExists');

		$this->assertSame($root, FolderPath::ensure(parent: $root, path: [], inTheWay: static fn (): RuntimeException => new RuntimeException('in the way')));
	}

	public function testAFileInTheWayThrowsTheCallersException(): void {
		$root = $this->createMock(Folder::class);
		$root->method('nodeExists')->with('a')->willReturn(true);
		$root->method('get')->with('a')->willReturn($this->createMock(File::class));
		$root->expects($this->never())->method('newFolder');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('a file is in the way');
		FolderPath::ensure(parent: $root, path: ['a', 'b'], inTheWay: static fn (): RuntimeException => new RuntimeException('a file is in the way'));
	}
}
