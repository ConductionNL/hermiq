<?php

/**
 * Hermiq WorkspaceToolset.
 *
 * The governed workspace and git tools (hermiq-runner-git-capability). Reached
 * only through `HermiqToolProvider::invokeTool()`, which the registry calls from
 * `FacadeToolInvoker`, the one dispatch path every governed tool takes; there is
 * no route of its own.
 *
 * Every call is tied to the run in `WorkspaceRunScope`, which the governed MCP
 * endpoint fills from the verified run token. A `workspaceId` argument that is
 * not the caller's own is refused, and every tool but `open` answers
 * `workspace_absent` until the run has a workspace. Results carry workspace-
 * relative paths only.
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use FilesystemIterator;
use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Dispatches the workspace tools.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface
 */
class WorkspaceToolset {

	public const DEFAULT_READ_BYTES = 262144;

	public const MAX_READ_BYTES = 1048576;

	/**
	 * Files listed at most per call.
	 *
	 * @var int
	 */
	private const MAX_LISTED = 2000;

	/**
	 * Budget for a local git command.
	 *
	 * @var int
	 */
	private const LOCAL_TIMEOUT_SECONDS = 20;

	/**
	 * Build the toolset.
	 *
	 * @param WorkspaceRunScope        $scope      The verified run.
	 * @param WorkspaceProvider        $provider   Where the workspace lives.
	 * @param WorkspacePathGuard       $guard      Path confinement.
	 * @param ForgeLocator             $forge      Slug to URL, and the egress policy.
	 * @param GitRunner                $git        The hardened git runner.
	 * @param WorkspaceEditor          $editor     The in-workspace writes.
	 * @param WorkspaceWriteAuthoriser $authoriser The run-scoped approval gate.
	 * @param WorkspacePusher          $pusher     The governed push.
	 * @param WorkspaceAuditor         $auditor    One audit record per write-shaped call.
	 */
	public function __construct(
		private readonly WorkspaceRunScope $scope,
		private readonly WorkspaceProvider $provider,
		private readonly WorkspacePathGuard $guard,
		private readonly ForgeLocator $forge,
		private readonly GitRunner $git,
		private readonly WorkspaceEditor $editor,
		private readonly WorkspaceWriteAuthoriser $authoriser,
		private readonly WorkspacePusher $pusher,
		private readonly WorkspaceAuditor $auditor,
	) {
	}//end __construct()

	/**
	 * Run one workspace tool. Never throws: a refusal is an error envelope with
	 * the contract's stable code.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-workspace-tools-dispatch-through-the-single-governed-path
	 */
	public function invoke(string $toolId, array $arguments): array {
		try {
			$run = $this->scope->current();
			$this->assertOwnWorkspace(runKey: $run['runId'], arguments: $arguments);

			if ($toolId === WorkspaceToolDescriptors::OPEN) {
				return $this->open(runKey: $run['runId'], arguments: $arguments);
			}

			$root = $this->provider->root(runKey: $run['runId']);
			if (in_array($toolId, WorkspaceToolDescriptors::WRITE_IDS, true) === true) {
				return $this->governedWrite(toolId: $toolId, run: $run, root: $root, arguments: $arguments);
			}

			return $this->dispatch(toolId: $toolId, root: $root, arguments: $arguments);
		} catch (WorkspaceException $e) {
			return ['error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()]];
		}
	}//end invoke()

	/**
	 * Dispatch a tool that needs an open workspace.
	 *
	 * @param string               $toolId    The tool id.
	 * @param string               $root      The workspace root.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 */
	private function dispatch(string $toolId, string $root, array $arguments): array {
		return match ($toolId) {
			WorkspaceToolDescriptors::STATUS => $this->status(root: $root),
			WorkspaceToolDescriptors::DIFF => $this->diff(root: $root, arguments: $arguments),
			WorkspaceToolDescriptors::LOG => $this->log(root: $root, arguments: $arguments),
			WorkspaceToolDescriptors::LIST_FILES => $this->listFiles(root: $root, arguments: $arguments),
			WorkspaceToolDescriptors::READ_FILE => $this->readFile(root: $root, arguments: $arguments),
			default => throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'Unknown workspace tool.'),
		};
	}//end dispatch()

	/**
	 * A write-shaped call: the approval gate first, before any argument is looked
	 * at, then the write; either way one audit record. The credential id a push
	 * used goes into the record and never into the result.
	 *
	 * @param string                                               $toolId    The tool id.
	 * @param array{runId: string, agentId: string, userId: string} $run       The verified run.
	 * @param string                                               $root      The workspace root.
	 * @param array<string, mixed>                                 $arguments The tool arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-every-governed-workspace-write-is-audited-with-owner-credential-and-approval
	 */
	private function governedWrite(string $toolId, array $run, string $root, array $arguments): array {
		$approval = null;
		try {
			$approval = $this->authoriser->assertAuthorised(
				run: $run,
				toolId: $toolId,
				workspace: $this->provider->describe(runKey: $run['runId'])
			);
			$result = $this->dispatchWrite(toolId: $toolId, run: $run, root: $root, arguments: $arguments);
		} catch (WorkspaceException $e) {
			$this->auditor->record(run: $run, toolId: $toolId, arguments: $arguments, approval: $approval, result: null, outcome: $e->getErrorCode());
			throw $e;
		}

		$this->auditor->record(run: $run, toolId: $toolId, arguments: $arguments, approval: $approval, result: $result, outcome: 'ok');
		unset($result['credentialId']);

		return $result;
	}//end governedWrite()

	/**
	 * Dispatch a write-shaped tool that has passed the approval gate.
	 *
	 * @param string                                               $toolId    The tool id.
	 * @param array{runId: string, agentId: string, userId: string} $run       The verified run.
	 * @param string                                               $root      The workspace root.
	 * @param array<string, mixed>                                 $arguments The tool arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-commits-are-authored-and-pushes-authorised-as-the-resolved-run-owner
	 */
	private function dispatchWrite(string $toolId, array $run, string $root, array $arguments): array {
		return match ($toolId) {
			WorkspaceToolDescriptors::WRITE_FILE => $this->editor->writeFile(runKey: $run['runId'], root: $root, arguments: $arguments),
			WorkspaceToolDescriptors::DELETE_FILE => $this->editor->deleteFile(root: $root, arguments: $arguments),
			WorkspaceToolDescriptors::APPLY_PATCH => $this->editor->applyPatch(runKey: $run['runId'], root: $root, arguments: $arguments),
			WorkspaceToolDescriptors::CREATE_BRANCH => $this->editor->createBranch(root: $root, branch: $this->branchArgument(arguments: $arguments)),
			WorkspaceToolDescriptors::CHECKOUT_BRANCH => $this->editor->checkoutBranch(root: $root, branch: $this->branchArgument(arguments: $arguments)),
			WorkspaceToolDescriptors::PUSH => $this->push(run: $run, root: $root, arguments: $arguments),
			default => $this->editor->commit(root: $root, ownerUid: $run['userId'], arguments: $arguments),
		};
	}//end dispatchWrite()

	/**
	 * `push`: the pinned repository must be the run's own. The credential id is for the audit record only.
	 *
	 * @param array{runId: string, agentId: string, userId: string} $run       The verified run.
	 * @param string                                               $root      The workspace root.
	 * @param array<string, mixed>                                 $arguments `repository`, `branch`.
	 *
	 * @return array{repository: string, branch: string, sha: string, credentialId: string}
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-a-failed-push-does-not-leak-the-credential
	 */
	private function push(array $run, string $root, array $arguments): array {
		return $this->pusher->push(
			run: $run,
			root: $root,
			repository: $this->provider->describe(runKey: $run['runId'])['repository'],
			branch: $this->branchArgument(arguments: $arguments),
			arguments: $arguments
		);
	}//end push()

	/**
	 * The validated `branch` argument.
	 *
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException invalid_argument.
	 */
	private function branchArgument(array $arguments): string {
		return $this->ref(value: (string)($arguments['branch'] ?? ''));
	}//end branchArgument()

	/**
	 * Refuse a workspace id that is not the caller's own.
	 *
	 * @param string               $runKey    The caller's run.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return void
	 *
	 * @throws WorkspaceException workspace_mismatch.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
	 */
	private function assertOwnWorkspace(string $runKey, array $arguments): void {
		if (isset($arguments['workspaceId']) === false) {
			return;
		}

		if ((string)$arguments['workspaceId'] !== $this->provider->workspaceId(runKey: $runKey)) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::WORKSPACE_MISMATCH,
				message: 'That workspace does not belong to this run.'
			);
		}
	}//end assertOwnWorkspace()

	/**
	 * `open`: materialise the run's workspace.
	 *
	 * @param string               $runKey    The run.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-model-never-learns-a-filesystem-path
	 */
	private function open(string $runKey, array $arguments): array {
		$repository = $this->forge->slug(repository: (string)($arguments['repository'] ?? ''));
		$ref = $this->ref(value: (string)($arguments['ref'] ?? ''));
		$url = $this->forge->cloneUrl(repository: $repository);

		return $this->provider->open(
			runKey: $runKey,
			cloneUrl: $url,
			repository: $repository,
			ref: $ref,
			depth: (int)($arguments['depth'] ?? 20)
		);
	}//end open()

	/**
	 * `status`: branch and changed files.
	 *
	 * @param string $root The workspace root.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 */
	private function status(string $root): array {
		$out = $this->gitOk(root: $root, arguments: ['status', '--porcelain=v1', '--branch', '--untracked-files=all']);
		$lines = array_values(array_filter(explode("\n", $out), static fn (string $l): bool => $l !== ''));
		$branch = '';
		$files = [];
		foreach ($lines as $line) {
			if (str_starts_with($line, '## ') === true) {
				$branch = (string)preg_replace('/\.\.\..*$/', '', substr($line, 3));
				continue;
			}

			$files[] = ['status' => trim(substr($line, 0, 2)), 'path' => substr($line, 3)];
		}

		return ['branch' => $branch, 'files' => $files, 'clean' => ($files === [])];
	}//end status()

	/**
	 * `diff`: uncommitted changes.
	 *
	 * @param string               $root      The workspace root.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 */
	private function diff(string $root, array $arguments): array {
		$command = ['diff', '--no-color', '--no-ext-diff', '--no-textconv'];
		if (($arguments['staged'] ?? false) === true) {
			$command[] = '--cached';
		}

		$path = (string)($arguments['path'] ?? '');
		if ($path !== '') {
			$this->guard->forRead(root: $root, relativePath: $path);
			$command[] = '--';
			$command[] = $path;
		}

		$out = $this->gitOk(root: $root, arguments: $command);
		$truncated = (strlen($out) > self::DEFAULT_READ_BYTES);

		return ['diff' => substr($out, 0, self::DEFAULT_READ_BYTES), 'truncated' => $truncated];
	}//end diff()

	/**
	 * `log`: latest commits.
	 *
	 * @param string               $root      The workspace root.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 */
	private function log(string $root, array $arguments): array {
		$limit = max(1, min((int)($arguments['limit'] ?? 20), 100));
		$out = $this->gitOk(root: $root, arguments: ['log', '-n', (string)$limit, '--no-color', '--format=%H%x1f%an%x1f%aI%x1f%s']);
		$commits = [];
		foreach (array_filter(explode("\n", $out), static fn (string $l): bool => $l !== '') as $line) {
			$parts = explode("\x1f", $line);
			$commits[] = ['sha' => $parts[0], 'author' => ($parts[1] ?? ''), 'date' => ($parts[2] ?? ''), 'subject' => ($parts[3] ?? '')];
		}

		return ['commits' => $commits];
	}//end log()

	/**
	 * `list_files`: files under a folder, never inside `.git`.
	 *
	 * @param string               $root      The workspace root.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 */
	private function listFiles(string $root, array $arguments): array {
		$path = (string)($arguments['path'] ?? '');
		$start = (string)realpath($root);
		if ($path !== '' && $path !== '.') {
			$start = $this->guard->forRead(root: $root, relativePath: $path);
		}

		if (is_dir($start) === false) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'That folder does not exist in the workspace.');
		}

		$realRoot = (string)realpath($root);
		$files = [];
		$truncated = false;
		$notMetadata = static fn (\SplFileInfo $file): bool => $file->getPathname() !== $realRoot . '/.git';
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS), $notMetadata)
		);
		foreach ($iterator as $file) {
			$relative = substr((string)$file->getPathname(), strlen($realRoot) + 1);
			if (count($files) >= self::MAX_LISTED) {
				$truncated = true;
				break;
			}

			$files[] = $relative;
		}

		sort($files);
		return ['files' => $files, 'truncated' => $truncated];
	}//end listFiles()

	/**
	 * `read_file`: a size-capped text read.
	 *
	 * @param string               $root      The workspace root.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 */
	private function readFile(string $root, array $arguments): array {
		$path = (string)($arguments['path'] ?? '');
		$absolute = $this->guard->forRead(root: $root, relativePath: $path);
		if (is_file($absolute) === false) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'That file does not exist in the workspace.');
		}

		$max = max(1, min((int)($arguments['maxBytes'] ?? self::DEFAULT_READ_BYTES), self::MAX_READ_BYTES));
		$bytes = (int)filesize($absolute);
		$content = (string)file_get_contents($absolute, false, null, 0, $max);

		return ['path' => $path, 'bytes' => $bytes, 'truncated' => ($bytes > $max), 'content' => $content];
	}//end readFile()

	/**
	 * A branch or tag name: plain characters, no leading dash, no `..`.
	 *
	 * @param string $value The supplied ref.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException invalid_argument.
	 */
	private function ref(string $value): string {
		$ref = trim($value);
		if ($ref === '' || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$#', $ref) !== 1
			|| str_contains($ref, '..') === true || str_ends_with($ref, '.lock') === true || str_ends_with($ref, '/') === true
		) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::INVALID_ARGUMENT,
				message: 'Name a branch or tag with letters, digits, dots, dashes and slashes.'
			);
		}

		return $ref;
	}//end ref()

	/**
	 * Run a local git command in the workspace and return its output.
	 *
	 * @param string             $root      The workspace root.
	 * @param array<int, string> $arguments The git arguments.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException git_failed.
	 */
	private function gitOk(string $root, array $arguments): string {
		$result = $this->git->run(arguments: $arguments, workingDir: $root, timeoutSeconds: self::LOCAL_TIMEOUT_SECONDS);
		if ($result['exit'] !== 0) {
			throw new WorkspaceException(errorCode: WorkspaceException::GIT_FAILED, message: 'The version control operation failed.');
		}

		return $result['stdout'];
	}//end gitOk()
}//end class
