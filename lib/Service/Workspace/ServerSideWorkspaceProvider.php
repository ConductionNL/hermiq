<?php

/**
 * Hermiq ServerSideWorkspaceProvider.
 *
 * Keeps each run's workspace in a directory on the governed side, under the
 * instance's app data, named by an opaque id derived from the run id with the
 * instance secret. The runner container never sees it: the model reaches it only
 * through the governed tools, and only for the run its token belongs to.
 *
 * Budget: a size and a file-count ceiling (app config `workspace_max_bytes`,
 * `workspace_max_files`), checked after the clone and before every write.
 * Lifetime: the workspace is reachable only while the run's token verifies; the
 * directory itself is removed by `reap()` once it has been idle for the retention
 * window (app config `workspace_retention_seconds`, one hour by default), which
 * keeps a failed run debuggable for a while without keeping source forever
 * (design Open Question 1, provisional position).
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use FilesystemIterator;
use OCA\Hermiq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Run-keyed workspaces in app data.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
 */
class ServerSideWorkspaceProvider implements WorkspaceProvider {

	public const DEFAULT_MAX_BYTES = 209715200;

	public const DEFAULT_MAX_FILES = 20000;

	public const DEFAULT_RETENTION_SECONDS = 3600;

	/**
	 * Budget for a clone.
	 *
	 * @var int
	 */
	private const CLONE_TIMEOUT_SECONDS = 60;

	/**
	 * Build the provider.
	 *
	 * @param IConfig      $config    System config (data directory, instance id, secret).
	 * @param IAppConfig   $appConfig App config (budgets, retention).
	 * @param GitRunner    $git       The hardened git runner.
	 * @param ITimeFactory $time      Clock.
	 * @param string|null  $baseDir   Override of the workspace base directory (tests).
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppConfig $appConfig,
		private readonly GitRunner $git,
		private readonly ITimeFactory $time,
		private readonly ?string $baseDir = null,
	) {
	}//end __construct()

	/**
	 * Materialise the run's workspace, or return the existing one.
	 *
	 * @param string $runKey     The run id from the verified token.
	 * @param string $cloneUrl   The server-resolved clone URL.
	 * @param string $repository The forge-relative slug.
	 * @param string $ref        The branch or tag.
	 * @param int    $depth      The history depth.
	 *
	 * @return array{workspaceId: string, repository: string, ref: string, headSha: string, fileCount: int}
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-model-never-learns-a-filesystem-path
	 */
	public function open(string $runKey, string $cloneUrl, string $repository, string $ref, int $depth): array {
		$dir = $this->dirFor(runKey: $runKey);
		$meta = $this->readMeta(dir: $dir);
		if ($meta !== null) {
			if ($meta['repository'] !== $repository || $meta['ref'] !== $ref) {
				throw new WorkspaceException(
					errorCode: WorkspaceException::INVALID_ARGUMENT,
					message: 'This run already has a workspace on another repository or ref. One run works on one checkout.'
				);
			}

			$this->touch(dir: $dir, meta: $meta);
			return $this->summary(runKey: $runKey, dir: $dir, meta: $meta);
		}

		if (is_dir($dir) === true) {
			$this->removeTree(path: $dir);
		}

		mkdir($dir, 0700, true);
		$result = $this->git->run(
			arguments: [
				'clone', '--quiet', '--no-tags', '--single-branch',
				'--depth', (string)max(1, min($depth, 500)), '--branch', $ref, '--', $cloneUrl, $dir . '/repo',
			],
			workingDir: $dir,
			timeoutSeconds: self::CLONE_TIMEOUT_SECONDS
		);
		if ($result['exit'] !== 0) {
			$this->removeTree(path: $dir);
			throw new WorkspaceException(
				errorCode: WorkspaceException::GIT_FAILED,
				message: 'The repository could not be opened. Check the repository name and the ref.'
			);
		}

		$usage = $this->usage(root: $dir . '/repo');
		if ($usage['bytes'] > $this->maxBytes() || $usage['files'] > $this->maxFiles()) {
			$this->removeTree(path: $dir);
			throw $this->quota();
		}

		$meta = ['repository' => $repository, 'ref' => $ref, 'createdAt' => $this->time->getTime(), 'lastUsedAt' => $this->time->getTime()];
		$this->writeMeta(dir: $dir, meta: $meta);

		return $this->summary(runKey: $runKey, dir: $dir, meta: $meta);
	}//end open()

	/**
	 * The opaque workspace id for a run key: an HMAC, so the run id is not recoverable.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
	 */
	public function workspaceId(string $runKey): string {
		$hash = hash_hmac('sha256', 'hermiq-workspace:' . $runKey, $this->config->getSystemValueString('secret', 'hermiq'));
		return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
	}//end workspaceId()

	/**
	 * The absolute repository root of the run's workspace, for the toolset only.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-a-tool-call-before-materialisation-is-refused
	 */
	public function root(string $runKey): string {
		$dir = $this->dirFor(runKey: $runKey);
		$meta = $this->readMeta(dir: $dir);
		if ($meta === null || is_dir($dir . '/repo') === false) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::WORKSPACE_ABSENT,
				message: 'No workspace is open for this run. Call workspace open first.'
			);
		}

		$this->touch(dir: $dir, meta: $meta);
		return $dir . '/repo';
	}//end root()

	/**
	 * The repository and ref the workspace was opened on.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return array{repository: string, ref: string}
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function describe(string $runKey): array {
		$this->root(runKey: $runKey);
		$meta = (array)$this->readMeta(dir: $this->dirFor(runKey: $runKey));
		return ['repository' => (string)($meta['repository'] ?? ''), 'ref' => (string)($meta['ref'] ?? '')];
	}//end describe()

	/**
	 * Refuse a write that would take the workspace over its budget.
	 *
	 * @param string $runKey   The run id.
	 * @param int    $addBytes The bytes the write adds.
	 * @param int    $addFiles The files the write adds.
	 *
	 * @return void
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function reserve(string $runKey, int $addBytes, int $addFiles): void {
		$usage = $this->usage(root: $this->root(runKey: $runKey));
		if (($usage['bytes'] + $addBytes) > $this->maxBytes() || ($usage['files'] + $addFiles) > $this->maxFiles()) {
			throw $this->quota();
		}
	}//end reserve()

	/**
	 * Remove the run's workspace.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return void
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function discard(string $runKey): void {
		$dir = $this->dirFor(runKey: $runKey);
		if (is_dir($dir) === true) {
			$this->removeTree(path: $dir);
		}
	}//end discard()

	/**
	 * Remove every workspace idle for longer than the retention window.
	 *
	 * @return int The number removed.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function reap(): int {
		$base = $this->base();
		if (is_dir($base) === false) {
			return 0;
		}

		$retention = $this->appConfig->getValueInt(Application::APP_ID, 'workspace_retention_seconds', self::DEFAULT_RETENTION_SECONDS);
		$now = $this->time->getTime();
		$removed = 0;
		foreach ((array)scandir($base) as $entry) {
			$dir = $base . '/' . (string)$entry;
			if ($entry === '.' || $entry === '..' || is_dir($dir) === false) {
				continue;
			}

			$meta = $this->readMeta(dir: $dir);
			$lastUsed = (int)($meta['lastUsedAt'] ?? 0);
			if (($lastUsed + $retention) < $now) {
				$this->removeTree(path: $dir);
				$removed++;
			}
		}

		return $removed;
	}//end reap()

	/**
	 * The workspace directory for a run key.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return string
	 */
	private function dirFor(string $runKey): string {
		if (trim($runKey) === '') {
			throw new WorkspaceException(
				errorCode: WorkspaceException::TOKEN_INVALID,
				message: 'Workspace tools are only available inside a governed run.'
			);
		}

		return $this->base() . '/' . $this->workspaceId(runKey: $runKey);
	}//end dirFor()

	/**
	 * The base directory holding every workspace.
	 *
	 * @return string
	 */
	private function base(): string {
		if ($this->baseDir !== null) {
			return rtrim($this->baseDir, '/');
		}

		$dataDir = rtrim($this->config->getSystemValueString('datadirectory', '/var/www/html/data'), '/');
		return $dataDir . '/appdata_' . $this->config->getSystemValueString('instanceid', '') . '/' . Application::APP_ID . '/workspaces';
	}//end base()

	/**
	 * The size and file count of a tree, its metadata directory included.
	 *
	 * @param string $root The repository root.
	 *
	 * @return array{bytes: int, files: int}
	 */
	private function usage(string $root): array {
		$bytes = 0;
		$files = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			$files++;
			$bytes += (int)$file->getSize();
		}

		return ['bytes' => $bytes, 'files' => $files];
	}//end usage()

	/**
	 * The result of an open, without any path.
	 *
	 * @param string               $runKey The run id.
	 * @param string               $dir    The workspace directory.
	 * @param array<string, mixed> $meta   The workspace metadata.
	 *
	 * @return array{workspaceId: string, repository: string, ref: string, headSha: string, fileCount: int}
	 */
	private function summary(string $runKey, string $dir, array $meta): array {
		$head = $this->git->run(arguments: ['rev-parse', 'HEAD'], workingDir: $dir . '/repo', timeoutSeconds: 10);
		$listed = $this->git->run(arguments: ['ls-files', '-z'], workingDir: $dir . '/repo', timeoutSeconds: 10);

		return [
			'workspaceId' => $this->workspaceId(runKey: $runKey),
			'repository' => (string)$meta['repository'],
			'ref' => (string)$meta['ref'],
			'headSha' => trim($head['stdout']),
			'fileCount' => count(array_filter(explode("\0", $listed['stdout']), static fn (string $f): bool => $f !== '')),
		];
	}//end summary()

	/**
	 * Read a workspace's metadata.
	 *
	 * @param string $dir The workspace directory.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readMeta(string $dir): ?array {
		$file = $dir . '/meta.json';
		if (is_file($file) === false) {
			return null;
		}

		$meta = json_decode((string)file_get_contents($file), true);
		if (is_array($meta) === false) {
			return null;
		}

		return $meta;
	}//end readMeta()

	/**
	 * Write a workspace's metadata.
	 *
	 * @param string               $dir  The workspace directory.
	 * @param array<string, mixed> $meta The metadata.
	 *
	 * @return void
	 */
	private function writeMeta(string $dir, array $meta): void {
		file_put_contents($dir . '/meta.json', (string)json_encode($meta));
	}//end writeMeta()

	/**
	 * Record that the workspace was used now.
	 *
	 * @param string               $dir  The workspace directory.
	 * @param array<string, mixed> $meta The metadata.
	 *
	 * @return void
	 */
	private function touch(string $dir, array $meta): void {
		$meta['lastUsedAt'] = $this->time->getTime();
		$this->writeMeta(dir: $dir, meta: $meta);
	}//end touch()

	/**
	 * Remove a directory tree without following links out of it.
	 *
	 * @param string $path The directory.
	 *
	 * @return void
	 */
	private function removeTree(string $path): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $file) {
			if ($file->isDir() === true && $file->isLink() === false) {
				rmdir($file->getPathname());
				continue;
			}

			unlink($file->getPathname());
		}

		rmdir($path);
	}//end removeTree()

	/**
	 * The size ceiling in bytes.
	 *
	 * @return int
	 */
	private function maxBytes(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'workspace_max_bytes', self::DEFAULT_MAX_BYTES);
	}//end maxBytes()

	/**
	 * The file-count ceiling.
	 *
	 * @return int
	 */
	private function maxFiles(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'workspace_max_files', self::DEFAULT_MAX_FILES);
	}//end maxFiles()

	/**
	 * The quota refusal.
	 *
	 * @return WorkspaceException
	 */
	private function quota(): WorkspaceException {
		return new WorkspaceException(
			errorCode: WorkspaceException::WORKSPACE_QUOTA,
			message: 'The workspace would exceed its size or file budget.'
		);
	}//end quota()
}//end class
