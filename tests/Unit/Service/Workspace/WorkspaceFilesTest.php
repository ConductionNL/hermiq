<?php

/**
 * Unit tests for WorkspaceFiles (tools-agent-workspace-and-installed-tools REQ-WKSP-003).
 *
 * The workspace is a real temporary directory behind a doubled provider; the
 * path guard is the real one, so containment is exercised, not assumed.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceFiles;
use OCA\Hermiq\Service\Workspace\WorkspacePathGuard;
use OCA\Hermiq\Service\Workspace\WorkspaceProvider;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for listing, opening and saving session workspace files.
 *
 * @covers \OCA\Hermiq\Service\Workspace\WorkspaceFiles
 * @uses \OCA\Hermiq\Service\Workspace\WorkspacePathGuard
 * @uses \OCA\Hermiq\Service\Workspace\WorkspaceException
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */
final class WorkspaceFilesTest extends TestCase {

	private const KEY = 'session:agent-1:conv-1:alice';

	/**
	 * The temporary workspace root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * The provider double.
	 *
	 * @var WorkspaceProvider&MockObject
	 */
	private WorkspaceProvider $provider;

	/**
	 * The Files double.
	 *
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder $rootFolder;

	/**
	 * The tag double.
	 *
	 * @var AgentArtefactMarker&MockObject
	 */
	private AgentArtefactMarker $marker;

	/**
	 * A workspace with two files, a nested one and git metadata.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/hermiq-wsfiles-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/out', 0777, true);
		mkdir($this->root . '/.git', 0777, true);
		file_put_contents($this->root . '/rapportage-q3.pdf', 'PDFBYTES');
		file_put_contents($this->root . '/out/data.csv', "a,b\n1,2\n");
		file_put_contents($this->root . '/.git/HEAD', 'ref: refs/heads/main');
		touch($this->root . '/rapportage-q3.pdf', 1700000000);

		$this->provider = $this->createMock(WorkspaceProvider::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->marker = $this->createMock(AgentArtefactMarker::class);

	}//end setUp()

	/**
	 * Remove the temporary workspace.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
		parent::tearDown();

	}//end tearDown()

	/**
	 * The service over the doubles.
	 *
	 * @return WorkspaceFiles
	 */
	private function files(): WorkspaceFiles {
		return new WorkspaceFiles($this->provider, new WorkspacePathGuard(), $this->rootFolder, $this->marker);

	}//end files()

	/**
	 * A provider that has the workspace open.
	 *
	 * @return void
	 */
	private function open(): void {
		$this->provider->method('root')->with(self::KEY)->willReturn($this->root);

	}//end open()

	/**
	 * Listing gives every file with size and changed time, sorted, without git metadata.
	 *
	 * @return void
	 */
	public function testItListsTheFilesWithoutGitMetadata(): void {
		$this->open();

		$listed = $this->files()->list(self::KEY);

		$this->assertFalse($listed['truncated']);
		$this->assertSame(['out/data.csv', 'rapportage-q3.pdf'], array_column($listed['files'], 'path'));
		$this->assertSame([8, 8], array_column($listed['files'], 'size'));
		$this->assertSame(1700000000, $listed['files'][1]['modified']);

	}//end testItListsTheFilesWithoutGitMetadata()

	/**
	 * A workspace that is not open lists nothing.
	 *
	 * @return void
	 */
	public function testAnAbsentWorkspaceListsNothing(): void {
		$this->provider->method('root')->willThrowException(
			new WorkspaceException(errorCode: WorkspaceException::WORKSPACE_ABSENT, message: 'No workspace is open for this run.')
		);

		$this->assertSame(['files' => [], 'truncated' => false], $this->files()->list(self::KEY));

	}//end testAnAbsentWorkspaceListsNothing()

	/**
	 * Opening a file gives its absolute path inside the workspace.
	 *
	 * @return void
	 */
	public function testItOpensAFileInsideTheWorkspace(): void {
		$this->open();

		$this->assertSame(realpath($this->root . '/out/data.csv'), $this->files()->open(self::KEY, 'out/data.csv'));

	}//end testItOpensAFileInsideTheWorkspace()

	/**
	 * A path that leaves the workspace is refused.
	 *
	 * @return void
	 */
	public function testAPathLeavingTheWorkspaceIsRefused(): void {
		$this->open();

		$this->expectException(WorkspaceException::class);
		$this->files()->open(self::KEY, '../../etc/passwd');

	}//end testAPathLeavingTheWorkspaceIsRefused()

	/**
	 * A folder or a missing file is not a file to open.
	 *
	 * @return void
	 */
	public function testAFolderIsNotAFile(): void {
		$this->open();

		$this->expectExceptionMessage('That file does not exist in the workspace.');
		$this->files()->open(self::KEY, 'out');

	}//end testAFolderIsNotAFile()

	/**
	 * Saving copies the bytes into Files under "Hermiq" and tags the new file.
	 *
	 * @return void
	 */
	public function testSavingCopiesIntoHermiqAndTagsTheFile(): void {
		$this->open();
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->expects($this->never())->method('delete');
		$folder = $this->createMock(Folder::class);
		$folder->method('getNonExistingName')->with('rapportage-q3.pdf')->willReturn('rapportage-q3 (2).pdf');
		$folder->expects($this->once())->method('newFile')->with('rapportage-q3 (2).pdf', 'PDFBYTES')->willReturn($file);
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->with('Hermiq')->willReturn(true);
		$home->method('get')->with('Hermiq')->willReturn($folder);
		$home->expects($this->never())->method('newFolder');
		$this->rootFolder->expects($this->once())->method('getUserFolder')->with('alice')->willReturn($home);
		$this->marker->expects($this->once())->method('markFile')->with(42);

		$saved = $this->files()->saveToFiles(self::KEY, 'rapportage-q3.pdf', 'alice');

		$this->assertSame(['fileId' => 42, 'name' => 'rapportage-q3 (2).pdf', 'path' => 'Hermiq/rapportage-q3 (2).pdf'], $saved);

	}//end testSavingCopiesIntoHermiqAndTagsTheFile()

	/**
	 * The "Hermiq" folder is created when the person has none.
	 *
	 * @return void
	 */
	public function testTheHermiqFolderIsCreatedWhenMissing(): void {
		$this->open();
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(7);
		$folder = $this->createMock(Folder::class);
		$folder->method('getNonExistingName')->willReturnArgument(0);
		$folder->method('newFile')->willReturn($file);
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturn(false);
		$home->expects($this->once())->method('newFolder')->with('Hermiq')->willReturn($folder);
		$this->rootFolder->method('getUserFolder')->willReturn($home);

		$this->assertSame('Hermiq/data.csv', $this->files()->saveToFiles(self::KEY, 'out/data.csv', 'alice')['path']);

	}//end testTheHermiqFolderIsCreatedWhenMissing()

	/**
	 * A tag that cannot be applied deletes the copy and fails the save.
	 *
	 * @return void
	 */
	public function testAFailedTagDeletesTheCopyAndFailsTheSave(): void {
		$this->open();
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->expects($this->once())->method('delete');
		$folder = $this->createMock(Folder::class);
		$folder->method('getNonExistingName')->willReturnArgument(0);
		$folder->method('newFile')->willReturn($file);
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturn(true);
		$home->method('get')->willReturn($folder);
		$this->rootFolder->method('getUserFolder')->willReturn($home);
		$this->marker->method('markFile')->willThrowException(new ArtefactMarkingFailedException('not marked'));

		$this->expectException(ArtefactMarkingFailedException::class);
		$this->files()->saveToFiles(self::KEY, 'rapportage-q3.pdf', 'alice');

	}//end testAFailedTagDeletesTheCopyAndFailsTheSave()

	/**
	 * A path that leaves the workspace never reaches Files.
	 *
	 * @return void
	 */
	public function testSavingAPathOutsideTheWorkspaceNeverReachesFiles(): void {
		$this->open();
		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$this->expectException(WorkspaceException::class);
		$this->files()->saveToFiles(self::KEY, '../outside.txt', 'alice');

	}//end testSavingAPathOutsideTheWorkspaceNeverReachesFiles()
}//end class
