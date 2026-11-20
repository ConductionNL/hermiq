<?php

/**
 * Hermiq WorkspaceTransfer.
 *
 * Copies a workspace into a sandbox run and writes back what the run changed
 * (tools-agent-workspace-and-installed-tools design D2). The sandbox stays
 * stateless and mount-free: before the run the workspace files (or the named
 * subset) become the run's `files`, within the sandbox's input cap; after the
 * run every returned file that is new or differs from what went in is written
 * back through the same path confinement as `workspace.write_file`, and inside
 * the workspace budget. A returned path that leaves the workspace, or names its
 * `.git`, is refused and reported; the rest of the run's files still land.
 *
 * A file is sent as text when it is valid UTF-8 and as base64 otherwise, and
 * says which (`encoding`), so binary output survives the round trip.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CodeSandbox
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use FilesystemIterator;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspacePathGuard;
use OCA\Hermiq\Service\Workspace\WorkspaceProvider;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pack a workspace into run files and write back what changed.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002
 */
class WorkspaceTransfer {

	/**
	 * The sandbox's input cap: 10 MB of file content per run.
	 *
	 * @var int
	 */
	public const MAX_INPUT_BYTES = 10485760;

	/**
	 * The refusal when the workspace is over the cap.
	 *
	 * @var string
	 */
	public const TOO_LARGE = 'The workspace is too large to run code on (limit 10 MB). Remove files or run on fewer files.';

	/**
	 * Text encoding marker.
	 *
	 * @var string
	 */
	public const ENCODING_TEXT = 'utf-8';

	/**
	 * Binary encoding marker.
	 *
	 * @var string
	 */
	public const ENCODING_BASE64 = 'base64';

	/**
	 * Constructor.
	 *
	 * @param WorkspaceProvider  $workspaces The governed workspaces.
	 * @param WorkspacePathGuard $guard      Path confinement for reads and writes.
	 */
	public function __construct(
		private readonly WorkspaceProvider $workspaces,
		private readonly WorkspacePathGuard $guard,
	) {
	}//end __construct()

	/**
	 * The workspace (or the named paths in it) as run input files.
	 *
	 * @param string             $workspaceKey The run's workspace key.
	 * @param array<int, string> $paths        Paths to send; empty sends every file outside `.git`.
	 *
	 * @return array<int, array{name: string, content: string, encoding: string}>
	 *
	 * @throws WorkspaceException When no workspace is open, a path is outside it or missing, or the input is over the cap.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002
	 */
	public function pack(string $workspaceKey, array $paths = []): array {
		$root = (string)realpath($this->workspaces->root(runKey: $workspaceKey));
		$selected = $this->selected(root: $root, paths: $paths);

		$total = 0;
		foreach ($selected as $absolute) {
			$total += (int)filesize($absolute);
			if ($total > self::MAX_INPUT_BYTES) {
				throw new WorkspaceException(errorCode: WorkspaceException::WORKSPACE_QUOTA, message: self::TOO_LARGE);
			}
		}

		$files = [];
		foreach ($selected as $relative => $absolute) {
			$files[] = self::encode(name: $relative, bytes: (string)file_get_contents($absolute));
		}

		return $files;
	}//end pack()

	/**
	 * Write back the files a run created or changed.
	 *
	 * @param string                           $workspaceKey The run's workspace key.
	 * @param array<int, array<string, mixed>> $sent         The files that went in (pack()'s result).
	 * @param mixed                            $returned     The run's `files`: a list of {name, content, encoding?}.
	 *
	 * @return array{written: array<int, array{path: string, action: string, bytes: int}>, refused: array<int, array{path: string, reason: string}>}
	 *
	 * @throws WorkspaceException When no workspace is open, or the changes would exceed its budget.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002
	 */
	public function writeBack(string $workspaceKey, array $sent, mixed $returned): array {
		$root = $this->workspaces->root(runKey: $workspaceKey);
		$before = [];
		foreach ($sent as $file) {
			$before[(string)($file['name'] ?? '')] = hash('sha256', self::decode(file: $file) ?? '');
		}

		$changes = [];
		$refused = [];
		if (is_array($returned) === false) {
			$returned = [];
		}

		foreach ($returned as $file) {
			if (is_array($file) === false) {
				$file = [];
			}

			$change = $this->changeOf(root: $root, file: $file, before: $before);
			if (isset($change['reason']) === true) {
				$refused[] = $change;
			} elseif ($change !== []) {
				$changes[] = $change;
			}
		}

		$this->reserveFor(workspaceKey: $workspaceKey, changes: $changes);

		return ['written' => array_map(self::write(...), $changes), 'refused' => $refused];
	}//end writeBack()

	/**
	 * One returned file as a change to write, `[]` when it is unchanged, or a refusal `{path, reason}`.
	 *
	 * @param string                $root   The workspace root.
	 * @param array<string, mixed>  $file   The returned {name, content, encoding?}.
	 * @param array<string, string> $before The sha256 of each file that went in, by name.
	 *
	 * @return array<string, mixed>
	 */
	private function changeOf(string $root, array $file, array $before): array {
		$name = (string)($file['name'] ?? '');
		$bytes = self::decode(file: $file);
		if ($name === '' || $bytes === null) {
			return ['path' => $name, 'reason' => 'The run returned a file without a name or readable content.'];
		}

		if (isset($before[$name]) === true && $before[$name] === hash('sha256', $bytes)) {
			return [];
		}

		try {
			$absolute = $this->guard->forWrite(root: $root, relativePath: $name);
		} catch (WorkspaceException $e) {
			return ['path' => $name, 'reason' => $e->getMessage()];
		}

		if (is_dir($absolute) === true) {
			return ['path' => $name, 'reason' => 'That path is a folder, not a file.'];
		}

		return ['path' => $name, 'absolute' => $absolute, 'bytes' => $bytes];
	}//end changeOf()

	/**
	 * Write one change into the workspace.
	 *
	 * @param array{path: string, absolute: string, bytes: string} $change The change.
	 *
	 * @return array{path: string, action: string, bytes: int}
	 */
	private static function write(array $change): array {
		$action = 'modified';
		if (is_file($change['absolute']) === false) {
			$action = 'created';
		}

		$folder = dirname($change['absolute']);
		if (is_dir($folder) === false) {
			mkdir($folder, 0755, true);
		}

		file_put_contents($change['absolute'], $change['bytes']);

		return ['path' => $change['path'], 'action' => $action, 'bytes' => strlen($change['bytes'])];
	}//end write()

	/**
	 * Reserve the budget for every change at once, so a run over budget writes nothing.
	 *
	 * @param string                           $workspaceKey The workspace key.
	 * @param array<int, array<string, mixed>> $changes      The pending writes.
	 *
	 * @return void
	 *
	 * @throws WorkspaceException When the changes would exceed the budget.
	 */
	private function reserveFor(string $workspaceKey, array $changes): void {
		$addBytes = 0;
		$addFiles = 0;
		foreach ($changes as $change) {
			$exists = is_file($change['absolute']);
			$addBytes += strlen($change['bytes']);
			if ($exists === true) {
				$addBytes -= (int)filesize($change['absolute']);
				continue;
			}

			$addFiles++;
		}

		if ($changes !== []) {
			$this->workspaces->reserve(runKey: $workspaceKey, addBytes: $addBytes, addFiles: $addFiles);
		}
	}//end reserveFor()

	/**
	 * The files to send, by relative path, sorted.
	 *
	 * @param string             $root  The real workspace root.
	 * @param array<int, string> $paths Named paths, or empty for all.
	 *
	 * @return array<string, string> Absolute path by relative path.
	 *
	 * @throws WorkspaceException When a named path is outside the workspace or not a file.
	 *
	 * @SuppressWarnings(PHPMD.ElseExpression) the two branches build one value from two sources; an early
	 * return would duplicate what follows.
	 */
	private function selected(string $root, array $paths): array {
		$selected = [];
		if ($paths !== []) {
			foreach ($paths as $path) {
				$absolute = $this->existingFile(root: $root, path: (string)$path);
				$selected[substr($absolute, strlen($root) + 1)] = $absolute;
			}
		} else {
			foreach (self::allFiles(root: $root) as $absolute) {
				$selected[substr($absolute, strlen($root) + 1)] = $absolute;
			}
		}

		ksort($selected, SORT_STRING);
		return $selected;
	}//end selected()

	/**
	 * The absolute path of a named workspace file that exists.
	 *
	 * @param string $root The workspace root.
	 * @param string $path The relative path.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException When the path leaves the workspace or is not a file.
	 */
	private function existingFile(string $root, string $path): string {
		$absolute = $this->guard->forRead(root: $root, relativePath: $path);
		if (is_file($absolute) === false) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::INVALID_ARGUMENT,
				message: 'That file does not exist in the workspace: ' . $path
			);
		}

		return $absolute;
	}//end existingFile()

	/**
	 * Every regular file under the root, outside `.git`, symlinks left out.
	 *
	 * @param string $root The workspace root.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private static function allFiles(string $root): array {
		$notMetadata = static fn (SplFileInfo $file): bool => $file->getPathname() !== $root . '/.git';
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), $notMetadata)
		);

		$files = [];
		foreach ($iterator as $file) {
			if ($file->isFile() === true && $file->isLink() === false) {
				$files[] = (string)$file->getPathname();
			}
		}

		return $files;
	}//end allFiles()

	/**
	 * One run file: text when the bytes are UTF-8, base64 otherwise.
	 *
	 * @param string $name  The relative path.
	 * @param string $bytes The content.
	 *
	 * @return array{name: string, content: string, encoding: string}
	 */
	private static function encode(string $name, string $bytes): array {
		if (mb_check_encoding($bytes, 'UTF-8') === true) {
			return ['name' => $name, 'content' => $bytes, 'encoding' => self::ENCODING_TEXT];
		}

		return ['name' => $name, 'content' => base64_encode($bytes), 'encoding' => self::ENCODING_BASE64];
	}//end encode()

	/**
	 * The bytes of a run file, or null when they cannot be read.
	 *
	 * @param array<string, mixed> $file The run file.
	 *
	 * @return string|null
	 */
	private static function decode(array $file): ?string {
		$content = $file['content'] ?? null;
		if (is_string($content) === false) {
			return null;
		}

		if (($file['encoding'] ?? self::ENCODING_TEXT) !== self::ENCODING_BASE64) {
			return $content;
		}

		$bytes = base64_decode($content, true);
		if ($bytes === false) {
			return null;
		}

		return $bytes;
	}//end decode()
}//end class
