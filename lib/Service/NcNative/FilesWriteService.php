<?php

/**
 * Hermiq Files Write Service.
 *
 * Lets an agent create a file and move a file in the acting user's own Files
 * (spec-coverage-built-rows, REQ-SPCOV-005). Both tools are confined to
 * `IRootFolder::getUserFolder($uid)`: a path is read relative to that folder,
 * and a path that climbs out of it (`..`), is empty, or carries a NUL byte is
 * refused before any node is touched. There is no overwrite and no delete:
 * creating over an existing file and moving onto an existing path are refused,
 * so neither tool can destroy content.
 *
 * A created file is marked as agent-authored (ADR-088); a failed mark is
 * reported as a failed write. A move authors nothing, so it carries the moved
 * file's identity for the run trace without a mark.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/spec-coverage-built-rows/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * File create and move scoped to the acting user's folder.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/changes/spec-coverage-built-rows/tasks.md
 */
class FilesWriteService {

	use ErrorEnvelopeTrait;

	/**
	 * The most bytes one created file may hold.
	 */
	public const MAX_CONTENT_BYTES = 1048576;

	/**
	 * Constructor.
	 *
	 * @param IRootFolder         $rootFolder Resolves the acting user's folder.
	 * @param AgentArtefactMarker $marker     ADR-088 marking.
	 * @param LoggerInterface     $logger     Diagnostics.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly AgentArtefactMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Create a new file in the acting user's folder; an existing file is never overwritten.
	 *
	 * @param string               $uid       The acting user id.
	 * @param array<string, mixed> $arguments path, content.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/changes/spec-coverage-built-rows/tasks.md
	 */
	public function createFile(string $uid, array $arguments): array {
		$path = $this->confinedPath(path: $arguments['path'] ?? null);
		if ($path === null) {
			return $this->err(code: 'invalid_argument', message: 'A file path inside your files is required.');
		}

		$content = (string)($arguments['content'] ?? '');
		if (strlen($content) > self::MAX_CONTENT_BYTES) {
			return $this->err(code: 'content_too_large', message: 'The file content is larger than one megabyte.');
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			if ($userFolder->nodeExists($path) === true) {
				return $this->err(code: 'already_exists', message: 'A file or folder already exists at that path.');
			}

			if ($this->parentFolder(userFolder: $userFolder, path: $path) === null) {
				return $this->err(code: 'parent_not_found', message: 'The folder to create the file in does not exist.');
			}

			$file = $userFolder->newFile($path, $content);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: file creation failed', ['exception' => $e]);

			return $this->err(code: 'files_unavailable', message: 'The file could not be created.');
		}

		try {
			$this->marker->markFile(fileId: (int)$file->getId());
		} catch (Throwable $e) {
			// ADR-088: written but unmarked is a failure, not a success.
			$this->logger->warning('Hermiq: file mark failed', ['exception' => $e]);

			return $this->err(code: 'artefact_not_marked', message: 'The file was written but could not be marked as agent-authored.');
		}

		return [
			'created' => true,
			'path' => $path,
			'artefact' => ['type' => 'file', 'id' => (string)$file->getId()],
		];

	}//end createFile()

	/**
	 * Move a file in the acting user's folder to a path that is free, in a folder that exists.
	 *
	 * @param string               $uid       The acting user id.
	 * @param array<string, mixed> $arguments from, to.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/changes/spec-coverage-built-rows/tasks.md
	 */
	public function moveFile(string $uid, array $arguments): array {
		$from = $this->confinedPath(path: $arguments['from'] ?? null);
		$to = $this->confinedPath(path: $arguments['to'] ?? null);
		if ($from === null || $to === null) {
			return $this->err(code: 'invalid_argument', message: 'A source and a target path inside your files are required.');
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$refusal = $this->moveRefusal(userFolder: $userFolder, from: $from, to: $to);
			if ($refusal !== null) {
				return $refusal;
			}

			$node = $userFolder->get($from);
			$moved = $node->move($userFolder->getFullPath($to));
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: file move failed', ['exception' => $e]);

			return $this->err(code: 'files_unavailable', message: 'The file could not be moved.');
		}

		return [
			'moved' => true,
			'from' => $from,
			'to' => $to,
			'artefact' => ['type' => 'file', 'id' => (string)$moved->getId()],
		];

	}//end moveFile()

	/**
	 * Why a move may not run, or null when it may: the source must be a file,
	 * the target free, and the target's folder must exist.
	 *
	 * @param Folder $userFolder The acting user's folder.
	 * @param string $from       The confined source path.
	 * @param string $to         The confined target path.
	 *
	 * @return array<string, mixed>|null The error envelope, or null.
	 */
	private function moveRefusal(Folder $userFolder, string $from, string $to): ?array {
		if ($userFolder->nodeExists($from) === false || ($userFolder->get($from) instanceof File) === false) {
			return $this->err(code: 'not_found', message: 'No file at the source path in your files.');
		}

		if ($userFolder->nodeExists($to) === true) {
			return $this->err(code: 'already_exists', message: 'A file or folder already exists at the target path.');
		}

		if ($this->parentFolder(userFolder: $userFolder, path: $to) === null) {
			return $this->err(code: 'parent_not_found', message: 'The folder to move the file into does not exist.');
		}

		return null;

	}//end moveRefusal()

	/**
	 * The folder a path would be created in, or null when it is missing or not a folder.
	 *
	 * @param Folder $userFolder The acting user's folder.
	 * @param string $path       A confined path.
	 *
	 * @return Folder|null
	 */
	private function parentFolder(Folder $userFolder, string $path): ?Folder {
		$parent = dirname($path);
		if ($parent === '.' || $parent === '') {
			return $userFolder;
		}

		if ($userFolder->nodeExists($parent) === false) {
			return null;
		}

		$node = $userFolder->get($parent);
		if ($node instanceof Folder) {
			return $node;
		}

		return null;

	}//end parentFolder()

	/**
	 * A path relative to the user's folder, or null when it is empty, not text,
	 * carries a NUL byte, or climbs out of the folder.
	 *
	 * @param mixed $path The path as the agent sent it.
	 *
	 * @return string|null The normalised path, without leading or trailing slashes.
	 *
	 * @spec openspec/changes/spec-coverage-built-rows/tasks.md
	 */
	public function confinedPath(mixed $path): ?string {
		if (is_string($path) === false || str_contains($path, "\0") === true) {
			return null;
		}

		$segments = [];
		foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
			if ($segment === '..') {
				return null;
			}

			if ($segment !== '' && $segment !== '.') {
				$segments[] = $segment;
			}
		}

		if ($segments === []) {
			return null;
		}

		return implode('/', $segments);

	}//end confinedPath()
}//end class
