<?php

/**
 * Unit tests for FilesWriteService: create and move confined to the acting
 * user's folder, with no overwrite and an ADR-088 mark on every created file.
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

use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\FilesWriteService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\NcNative\FilesWriteService
 */
class FilesWriteServiceTest extends TestCase {

	/**
	 * The acting user's folder.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $userFolder;

	/**
	 * The root folder.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $rootFolder;

	/**
	 * The ADR-088 marker.
	 *
	 * @var AgentArtefactMarker&MockObject
	 */
	private AgentArtefactMarker&MockObject $marker;

	/**
	 * The service under test.
	 *
	 * @var FilesWriteService
	 */
	private FilesWriteService $service;

	/**
	 * The paths that exist in the user's folder, and the node each answers.
	 *
	 * @var array<string, object>
	 */
	private array $nodes = [];

	protected function setUp(): void {
		$this->userFolder = $this->createMock(Folder::class);
		$this->userFolder->method('nodeExists')->willReturnCallback(fn (string $path): bool => isset($this->nodes[$path]));
		$this->userFolder->method('get')->willReturnCallback(fn (string $path): object => $this->nodes[$path]);
		$this->userFolder->method('getFullPath')->willReturnCallback(static fn (string $path): string => '/alice/files/' . $path);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with('alice')->willReturn($this->userFolder);
		$this->marker = $this->createMock(AgentArtefactMarker::class);

		$this->service = new FilesWriteService($this->rootFolder, $this->marker, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A file node with an id.
	 *
	 * @param int $id The file id.
	 *
	 * @return File&MockObject
	 */
	private function file(int $id): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	public function testCreatesAFileAndMarksIt(): void {
		$this->nodes['Reports'] = $this->createMock(Folder::class);
		$this->userFolder->expects($this->once())->method('newFile')->with('Reports/q3.md', '# Q3')->willReturn($this->file(42));
		$this->marker->expects($this->once())->method('markFile')->with(42);

		$result = $this->service->createFile('alice', ['path' => '/Reports/./q3.md', 'content' => '# Q3']);

		$this->assertSame(['created' => true, 'path' => 'Reports/q3.md', 'artefact' => ['type' => 'file', 'id' => '42']], $result);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function pathsOutsideTheUserFolder(): array {
		return [
			'parent' => ['../bob/files/secret.txt'],
			'parent in the middle' => ['Reports/../../bob/x.txt'],
			'backslash parent' => ['Reports\\..\\..\\x.txt'],
			'empty' => [''],
			'only slashes' => ['///'],
			'nul byte' => ["a\0b.txt"],
			'not text' => [['a.txt']],
		];
	}

	/**
	 * @param mixed $path The path.
	 *
	 * @dataProvider pathsOutsideTheUserFolder
	 *
	 * @return void
	 */
	public function testRefusesAPathOutsideTheUserFolder(mixed $path): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->userFolder->expects($this->never())->method('newFile');

		$this->assertSame('invalid_argument', $this->service->createFile('alice', ['path' => $path])['error']['code']);
		$this->assertSame('invalid_argument', $this->service->moveFile('alice', ['from' => $path, 'to' => 'x.txt'])['error']['code']);
		$this->assertSame('invalid_argument', $this->service->moveFile('alice', ['from' => 'x.txt', 'to' => $path])['error']['code']);
	}

	public function testNeverOverwritesAnExistingPath(): void {
		$this->nodes['notes.txt'] = $this->file(1);
		$this->userFolder->expects($this->never())->method('newFile');

		$this->assertSame('already_exists', $this->service->createFile('alice', ['path' => 'notes.txt'])['error']['code']);
	}

	public function testRefusesAMissingParentFolder(): void {
		$this->userFolder->expects($this->never())->method('newFile');

		$this->assertSame('parent_not_found', $this->service->createFile('alice', ['path' => 'Nope/a.txt'])['error']['code']);
	}

	public function testRefusesContentOverOneMegabyte(): void {
		$result = $this->service->createFile('alice', ['path' => 'a.txt', 'content' => str_repeat('x', FilesWriteService::MAX_CONTENT_BYTES + 1)]);

		$this->assertSame('content_too_large', $result['error']['code']);
	}

	public function testAnUnmarkedFileIsReportedAsAFailure(): void {
		$this->userFolder->method('newFile')->willReturn($this->file(7));
		$this->marker->method('markFile')->willThrowException(new RuntimeException('tags down'));

		$this->assertSame('artefact_not_marked', $this->service->createFile('alice', ['path' => 'a.txt'])['error']['code']);
	}

	public function testMovesAFileOntoAFreePath(): void {
		$source = $this->file(9);
		$this->nodes['inbox/a.pdf'] = $source;
		$this->nodes['Archive'] = $this->createMock(Folder::class);
		$source->expects($this->once())->method('move')->with('/alice/files/Archive/a.pdf')->willReturn($this->file(9));

		$result = $this->service->moveFile('alice', ['from' => 'inbox/a.pdf', 'to' => 'Archive/a.pdf']);

		$this->assertSame(['moved' => true, 'from' => 'inbox/a.pdf', 'to' => 'Archive/a.pdf', 'artefact' => ['type' => 'file', 'id' => '9']], $result);
	}

	public function testAMoveRefusesAnOccupiedTargetAMissingSourceAndAFolderSource(): void {
		$source = $this->file(9);
		$source->expects($this->never())->method('move');
		$this->nodes['a.pdf'] = $source;
		$this->nodes['b.pdf'] = $this->file(10);
		$this->nodes['Folder'] = $this->createMock(Folder::class);

		$this->assertSame('already_exists', $this->service->moveFile('alice', ['from' => 'a.pdf', 'to' => 'b.pdf'])['error']['code']);
		$this->assertSame('not_found', $this->service->moveFile('alice', ['from' => 'gone.pdf', 'to' => 'c.pdf'])['error']['code']);
		$this->assertSame('not_found', $this->service->moveFile('alice', ['from' => 'Folder', 'to' => 'c'])['error']['code']);
		$this->assertSame('parent_not_found', $this->service->moveFile('alice', ['from' => 'a.pdf', 'to' => 'Missing/a.pdf'])['error']['code']);
	}
}
