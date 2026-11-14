<?php

/**
 * Hermiq WorkspaceSnapshot.
 *
 * Freezes a workspace into a build archive and opens a new workspace from one.
 * An archive is a zip of the workspace's checkout, kept in the build store under
 * its content hash: the sha256 over the sorted paths and file hashes of every
 * file outside `.git`, so two builds of the same files share one archive.
 * Symbolic links are left out, as the Files panel leaves them out.
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Build archives of workspaces.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
 */
class WorkspaceSnapshot {

	/**
	 * Constructor.
	 *
	 * @param ServerSideWorkspaceProvider $workspaces The governed workspaces.
	 */
	public function __construct(
		private readonly ServerSideWorkspaceProvider $workspaces,
	) {
	}//end __construct()

	/**
	 * Archive the workspace under a key into the build store.
	 *
	 * @param string $workspaceKey The workspace key.
	 *
	 * @return array{contentHash: string, files: array<int, array{path: string, size: int}>, repository: string, ref: string}
	 *
	 * @throws WorkspaceException When no workspace is open under the key, or the archive cannot be written.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
	 */
	public function archive(string $workspaceKey): array {
		$origin = $this->workspaces->describe(runKey: $workspaceKey);
		$root = (string)realpath($this->workspaces->root(runKey: $workspaceKey));

		$entries = $this->entries(root: $root);
		if ($entries === []) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'The workspace has no files to publish.');
		}

		$files = [];
		$digest = [];
		foreach ($entries as $relative => $absolute) {
			if ($relative === '.git' || str_starts_with($relative, '.git/') === true) {
				continue;
			}

			$files[] = ['path' => $relative, 'size' => (int)filesize($absolute)];
			$digest[] = $relative . "\0" . hash_file('sha256', $absolute);
		}

		$contentHash = hash('sha256', implode("\n", $digest));
		$this->store(contentHash: $contentHash, entries: $entries);

		return ['contentHash' => $contentHash, 'files' => $files, 'repository' => $origin['repository'], 'ref' => $origin['ref']];
	}//end archive()

	/**
	 * The stored archive for a content hash.
	 *
	 * @param string $contentHash The archive's content hash.
	 *
	 * @return string The archive's path; it may not exist.
	 *
	 * @throws WorkspaceException When the hash is not a sha256.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-runs-of-a-published-agent-start-from-its-pinned-build-req-build-002
	 */
	public function archivePath(string $contentHash): string {
		if (preg_match('/^[0-9a-f]{64}$/', $contentHash) !== 1) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'The build archive could not be read.');
		}

		return $this->workspaces->buildStore() . '/' . $contentHash . '.zip';
	}//end archivePath()

	/**
	 * Open a workspace under a key as a copy of a stored archive.
	 *
	 * @param string               $workspaceKey The new workspace's key.
	 * @param string               $contentHash  The archive's content hash.
	 * @param array<string, mixed> $origin       repository, ref and buildId.
	 *
	 * @return void
	 *
	 * @throws WorkspaceException When the archive is missing or unsafe, or a workspace is already open.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-runs-of-a-published-agent-start-from-its-pinned-build-req-build-002
	 */
	public function restore(string $workspaceKey, string $contentHash, array $origin): void {
		$this->workspaces->restore(runKey: $workspaceKey, archivePath: $this->archivePath(contentHash: $contentHash), origin: $origin);
	}//end restore()

	/**
	 * Every regular file under the root, by relative path, sorted.
	 *
	 * @param string $root The workspace checkout.
	 *
	 * @return array<string, string> Absolute path by relative path.
	 */
	private function entries(string $root): array {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		$entries = [];
		foreach ($iterator as $file) {
			if ($file->isLink() === true || $file->isFile() === false) {
				continue;
			}

			$entries[substr((string)$file->getPathname(), strlen($root) + 1)] = (string)$file->getPathname();
		}

		ksort($entries, SORT_STRING);
		return $entries;
	}//end entries()

	/**
	 * Write the archive once per content hash.
	 *
	 * @param string                $contentHash The content hash.
	 * @param array<string, string> $entries     Absolute path by relative path.
	 *
	 * @return void
	 *
	 * @throws WorkspaceException When the archive cannot be written.
	 */
	private function store(string $contentHash, array $entries): void {
		$target = $this->archivePath(contentHash: $contentHash);
		if (is_file($target) === true) {
			return;
		}

		$store = dirname($target);
		if (is_dir($store) === false) {
			mkdir($store, 0700, true);
		}

		$partial = $target . '.' . bin2hex(random_bytes(4)) . '.part';
		$zip = new ZipArchive();
		if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'The build archive could not be written.');
		}

		foreach ($entries as $relative => $absolute) {
			$zip->addFile($absolute, $relative);
		}

		if ($zip->close() !== true) {
			if (is_file($partial) === true) {
				unlink($partial);
			}

			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'The build archive could not be written.');
		}

		rename($partial, $target);
	}//end store()
}//end class
