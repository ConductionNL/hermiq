<?php

/**
 * Hermiq WorkspaceFiles.
 *
 * The files of a conversation's session workspace as the chat shows them, and
 * "Save to my Files" (tools-agent-workspace-and-installed-tools D3). A saved
 * file lands in the person's own Files under "Hermiq" and carries the
 * agent-authored tag from the same operation; a tag that cannot be applied
 * removes the copy again and fails the save (ADR-088).
 *
 * The caller passes the workspace key; this class never composes one, so the
 * controller's access check decides whose workspace is read.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
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

namespace OCA\Hermiq\Service\Workspace;

use FilesystemIterator;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Lists, opens and saves the files of one session workspace.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */
class WorkspaceFiles {

	/**
	 * The folder in the person's Files that saved workspace files land in.
	 *
	 * @var string
	 */
	public const FOLDER = 'Hermiq';

	/**
	 * The most files the panel lists; the rest is reported as truncated.
	 *
	 * @var int
	 */
	public const MAX_LISTED = 2000;

	/**
	 * Constructor.
	 *
	 * @param WorkspaceProvider $workspaces The governed workspaces.
	 * @param WorkspacePathGuard $guard Path confinement inside a workspace.
	 * @param IRootFolder $rootFolder The person's Files.
	 * @param AgentArtefactMarker $marker Applies the agent-authored tag.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	public function __construct(
		private readonly WorkspaceProvider $workspaces,
		private readonly WorkspacePathGuard $guard,
		private readonly IRootFolder $rootFolder,
		private readonly AgentArtefactMarker $marker,
	) {
	}//end __construct()

	/**
	 * The workspace's files: path, size in bytes and changed time, sorted by path.
	 * A workspace that is not open lists nothing.
	 *
	 * @param string $workspaceKey The session workspace key.
	 *
	 * @return array{files: list<array{path: string, size: int, modified: int}>, truncated: bool}
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	public function list(string $workspaceKey): array {
		$root = $this->rootOrNull(workspaceKey: $workspaceKey);
		if ($root === null) {
			return ['files' => [], 'truncated' => false];
		}

		$realRoot = (string)realpath($root);
		$notMetadata = static fn (SplFileInfo $file): bool => $file->getPathname() !== $realRoot . '/.git';
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS), $notMetadata)
		);

		$files = [];
		$truncated = false;
		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->isLink() === true) {
				continue;
			}

			if (count($files) >= self::MAX_LISTED) {
				$truncated = true;
				break;
			}

			$files[] = [
				'path' => substr((string)$file->getPathname(), strlen($realRoot) + 1),
				'size' => (int)$file->getSize(),
				'modified' => (int)$file->getMTime(),
			];
		}

		usort($files, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
		return ['files' => $files, 'truncated' => $truncated];
	}//end list()

	/**
	 * The absolute path of one workspace file, confined to the workspace.
	 *
	 * @param string $workspaceKey The session workspace key.
	 * @param string $path The file's path inside the workspace.
	 *
	 * @return string The absolute path.
	 *
	 * @throws WorkspaceException When the workspace is not open, the path leaves it, or no such file exists.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	public function open(string $workspaceKey, string $path): string {
		$absolute = $this->guard->forRead(root: $this->workspaces->root(runKey: $workspaceKey), relativePath: $path);
		if (is_file($absolute) === false) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'That file does not exist in the workspace.');
		}

		return $absolute;
	}//end open()

	/**
	 * Copy one workspace file into the person's Files under "Hermiq" and tag it
	 * as agent-authored. A failed tag deletes the copy and fails the save.
	 *
	 * @param string $workspaceKey The session workspace key.
	 * @param string $path The file's path inside the workspace.
	 * @param string $userId The person whose Files receive the copy.
	 *
	 * @return array{fileId: int, name: string, path: string}
	 *
	 * @throws WorkspaceException When the file cannot be read from the workspace.
	 * @throws ArtefactMarkingFailedException When the agent-authored tag cannot be applied.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	public function saveToFiles(string $workspaceKey, string $path, string $userId): array {
		$absolute = $this->open(workspaceKey: $workspaceKey, path: $path);
		$bytes = file_get_contents($absolute);
		if ($bytes === false) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'That file could not be read from the workspace.');
		}

		$folder = $this->folderFor(userId: $userId);
		$name = $folder->getNonExistingName(basename($absolute));
		$file = $folder->newFile($name, $bytes);

		try {
			$this->marker->markFile(fileId: (int)$file->getId());
		} catch (ArtefactMarkingFailedException $e) {
			$file->delete();
			throw $e;
		}

		return ['fileId' => (int)$file->getId(), 'name' => $name, 'path' => self::FOLDER . '/' . $name];
	}//end saveToFiles()

	/**
	 * The workspace root, or null when no workspace is open for the key.
	 *
	 * @param string $workspaceKey The session workspace key.
	 *
	 * @return string|null The root.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	private function rootOrNull(string $workspaceKey): ?string {
		try {
			return $this->workspaces->root(runKey: $workspaceKey);
		} catch (WorkspaceException $e) {
			return null;
		}
	}//end rootOrNull()

	/**
	 * The "Hermiq" folder in the person's Files, created when missing.
	 *
	 * @param string $userId The person.
	 *
	 * @return Folder The folder.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	private function folderFor(string $userId): Folder {
		$home = $this->rootFolder->getUserFolder($userId);
		if ($home->nodeExists(self::FOLDER) === true) {
			$existing = $home->get(self::FOLDER);
			if ($existing instanceof Folder) {
				return $existing;
			}
		}

		return $home->newFolder(self::FOLDER);
	}//end folderFor()
}//end class
