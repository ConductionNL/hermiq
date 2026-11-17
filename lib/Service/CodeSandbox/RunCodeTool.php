<?php

/**
 * Hermiq runCode tool (tools-code-sandbox).
 *
 * Runs an agent's code in the code sandbox. Before anything is read or sent,
 * the AI feature `agent-code-execution` must be enabled: absent, unreadable
 * or disabled refuses the call, whatever the agent's grants. Files the call
 * names are read as the acting user from their own Files and copied into the
 * run as text. Never throws: every failure is an error envelope.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CodeSandbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use InvalidArgumentException;
use OCA\Hermiq\Mcp\CodeSandboxToolDescriptors;
use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The runCode tool behind its AI feature gate.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */
class RunCodeTool {

	/**
	 * The AI feature that must be enabled before any code runs.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'agent-code-execution';

	/**
	 * The refusal while the feature is not enabled.
	 *
	 * @var string
	 */
	public const NOT_ENABLED = 'Running code must be enabled as an AI feature before an agent can run code.';

	/**
	 * The largest file one call copies into a run, in bytes.
	 *
	 * @var int
	 */
	public const MAX_FILE_BYTES = 1048576;

	/**
	 * Constructor.
	 *
	 * @param AiFeatureLookup     $features   The AI feature register.
	 * @param CodeSandboxClient   $client     Sends the run.
	 * @param CodeSandboxSettings $settings   Where code runs, for the reach.
	 * @param IRootFolder         $rootFolder The acting user's Files.
	 * @param LoggerInterface     $logger     Records failures, never code or output.
	 * @param WorkspaceRunScope|null $scope    The verified run, for its workspace; null runs without one.
	 * @param WorkspaceTransfer|null $transfer Copies the workspace into a run and writes back what it changed.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-3-the-runcode-tool-and-its-ai-feature-gate
	 */
	public function __construct(
		private readonly AiFeatureLookup $features,
		private readonly CodeSandboxClient $client,
		private readonly CodeSandboxSettings $settings,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
		private readonly ?WorkspaceRunScope $scope=null,
		private readonly ?WorkspaceTransfer $transfer=null,
	) {
	}//end __construct()

	/**
	 * Run the call's code as the acting user.
	 *
	 * @param string               $uid       The acting user.
	 * @param string               $toolId    The tool id; only runCode is served.
	 * @param array<string, mixed> $arguments language, code, stdin, files [{path}], timeoutSeconds, memoryMb.
	 *
	 * @return array<string, mixed> exitCode, stdout, stderr, durationMs, timedOut, files, limits, language
	 *   and reach; or `['error' => ['code', 'message']]`.
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#scenario-the-ai-feature-is-off
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		$refusal = $this->refusal(toolId: $toolId, code: ($arguments['code'] ?? null));
		if ($refusal !== null) {
			return $refusal;
		}

		$files = $this->files(uid: $uid, requested: ($arguments['files'] ?? []));
		if (isset($files['error']) === true) {
			return $files;
		}

		$workspace = $this->workspaceInput(arguments: $arguments, namedFiles: $files);
		if (isset($workspace['error']) === true) {
			return $workspace;
		}

		$reach = $this->settings->reach();
		if ($files !== [] || $workspace !== []) {
			$reach = CodeSandboxToolDescriptors::atLeastUser(reach: $reach);
		}

		$run = [
			'uid'      => $uid,
			'language' => (string)($arguments['language'] ?? ''),
			'code'     => (string)$arguments['code'],
			'stdin'    => (string)($arguments['stdin'] ?? ''),
			'limits'   => ['wallSeconds' => ($arguments['timeoutSeconds'] ?? null), 'memoryMb' => ($arguments['memoryMb'] ?? null)],
			'reach'    => $reach,
		];
		if ($workspace !== []) {
			return $this->dispatchOnWorkspace(run: $run, workspace: $workspace);
		}

		return $this->dispatch(run: $run, files: $files);
	}//end invoke()

	/**
	 * Why the call cannot run: another tool, the AI feature off, or no code; null when it can.
	 *
	 * @param string $toolId The tool id.
	 * @param mixed  $code   The `code` argument.
	 *
	 * @return array<string, mixed>|null An error envelope, or null.
	 */
	private function refusal(string $toolId, mixed $code): ?array {
		if ($toolId !== CodeSandboxToolDescriptors::RUN_CODE) {
			return self::err(code: 'unknown_tool', message: "Unknown tool id '" . $toolId . "'.");
		}

		if ($this->featureEnabled() === false) {
			return self::err(code: 'code_execution_not_enabled', message: self::NOT_ENABLED);
		}

		if (is_string($code) === false || trim($code) === '') {
			return self::err(code: 'invalid_argument', message: 'The code to run is required.');
		}

		return null;
	}//end refusal()

	/**
	 * Send the run and shape its answer.
	 *
	 * @param array<string, mixed>                            $run         uid, language, code, stdin, limits and reach.
	 * @param array<int, array{name: string, content: string}> $files       The input files.
	 * @param bool                                            $onWorkspace Whether the files are a workspace copy (relative paths).
	 *
	 * @return array<string, mixed>
	 */
	private function dispatch(array $run, array $files, bool $onWorkspace=false): array {
		try {
			$request = [
				'language' => $run['language'],
				'code'     => $run['code'],
				'stdin'    => $run['stdin'],
				'files'    => $files,
				'limits'   => $run['limits'],
				'uid'      => $run['uid'],
			];
			if ($onWorkspace === true) {
				$result = $this->client->runOnWorkspace(...$request);
			} else {
				$result = $this->client->run(...$request);
			}
		} catch (CodeSandboxUnavailableException $e) {
			return self::err(code: 'code_sandbox_unavailable', message: $e->getMessage());
		} catch (InvalidArgumentException $e) {
			return self::err(code: 'invalid_argument', message: $e->getMessage());
		} catch (RuntimeException $e) {
			$this->logger->warning('[RunCodeTool] the run failed', ['reason' => $e->getMessage()]);
			return self::err(code: 'code_run_failed', message: $e->getMessage());
		}

		return $result + ['language' => $run['language'], 'reach' => $run['reach']];
	}//end dispatch()

	/**
	 * The workspace input of the run: its key and the packed files, an empty
	 * list when the run goes without the workspace, or an error envelope.
	 *
	 * `useWorkspace` defaults to true when the run has an open workspace and
	 * the call names no `files` of the acting user, so a call that only names
	 * files runs as it did before workspaces existed. Asked for explicitly, a
	 * missing workspace or a combination with `files` is refused.
	 *
	 * @param array<string, mixed>     $arguments  The call's arguments.
	 * @param array<int|string, mixed> $namedFiles The acting user's files the call named.
	 *
	 * @return array<string, mixed> `{key, files}`, `[]`, or `['error' => ...]`.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002
	 */
	private function workspaceInput(array $arguments, array $namedFiles): array {
		$explicit = self::askedForWorkspace(asked: ($arguments['useWorkspace'] ?? null));
		if ($explicit === false || ($explicit === null && $namedFiles !== [])) {
			return [];
		}

		if ($explicit === true && $namedFiles !== []) {
			return self::err(code: 'invalid_argument', message: 'Name files or run on the workspace, not both. Write the file into the workspace first.');
		}

		$paths = ($arguments['paths'] ?? []);
		$invalid = self::invalidPaths(paths: $paths);
		if ($invalid !== null) {
			return self::err(code: 'invalid_argument', message: $invalid);
		}

		return $this->packWorkspace(paths: $paths, asksForIt: ($explicit === true || $paths !== []));
	}//end workspaceInput()

	/**
	 * What the call's `useWorkspace` says: true asked for, false declined, null not said.
	 *
	 * @param mixed $asked The `useWorkspace` argument.
	 *
	 * @return bool|null
	 */
	private static function askedForWorkspace(mixed $asked): ?bool {
		if (in_array($asked, [false, 'false', 0], true) === true) {
			return false;
		}

		if (in_array($asked, [true, 'true', 1], true) === true) {
			return true;
		}

		return null;
	}//end askedForWorkspace()

	/**
	 * Why the `paths` argument is not a list of workspace paths, or null when it is.
	 *
	 * @param mixed $paths The `paths` argument.
	 *
	 * @return string|null
	 */
	private static function invalidPaths(mixed $paths): ?string {
		if (is_array($paths) === false || array_is_list($paths) === false) {
			return 'paths is a list of workspace paths.';
		}

		foreach ($paths as $path) {
			if (is_string($path) === false || trim($path, '/') === '') {
				return 'Every path is a workspace path.';
			}
		}

		return null;
	}//end invalidPaths()

	/**
	 * The open workspace's key and packed files, `[]` without one, or an error envelope.
	 *
	 * @param array<int, string> $paths     The workspace paths to send, all when empty.
	 * @param bool               $asksForIt Whether the call asked for the workspace.
	 *
	 * @return array<string, mixed>
	 */
	private function packWorkspace(array $paths, bool $asksForIt): array {
		if ($this->scope === null || $this->transfer === null) {
			return $this->noWorkspace(explicit: $asksForIt);
		}

		try {
			$key = $this->scope->current()['workspaceKey'];
			return ['key' => $key, 'files' => $this->transfer->pack(workspaceKey: $key, paths: $paths)];
		} catch (WorkspaceException $e) {
			$absent = in_array($e->getErrorCode(), [WorkspaceException::RUN_SCOPE_INVALID, WorkspaceException::WORKSPACE_ABSENT], true);
			if ($absent === true) {
				return $this->noWorkspace(explicit: $asksForIt);
			}

			return self::err(code: $e->getErrorCode(), message: $e->getMessage());
		}
	}//end workspaceInput()

	/**
	 * A run without a workspace: nothing to add, or a refusal when the call asked for one.
	 *
	 * @param bool $explicit Whether the call asked for the workspace (or named paths in it).
	 *
	 * @return array<string, mixed> `[]` or `['error' => ...]`.
	 */
	private function noWorkspace(bool $explicit): array {
		if ($explicit === false) {
			return [];
		}

		return self::err(code: WorkspaceException::WORKSPACE_ABSENT, message: 'This run has no workspace to run code on.');
	}//end noWorkspace()

	/**
	 * Send a run over the workspace copy and write back what it created or changed.
	 *
	 * The answer carries what was written and refused instead of the run's
	 * file contents, which are now in the workspace.
	 *
	 * @param array<string, mixed> $run       uid, language, code, stdin, limits and reach.
	 * @param array<string, mixed> $workspace `{key, files}` from workspaceInput().
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-code-adds-a-column-to-a-file-in-the-workspace
	 */
	private function dispatchOnWorkspace(array $run, array $workspace): array {
		$result = $this->dispatch(run: $run, files: $workspace['files'], onWorkspace: true);
		if (isset($result['error']) === true || $this->transfer === null) {
			return $result;
		}

		try {
			$written = $this->transfer->writeBack(workspaceKey: (string)$workspace['key'], sent: $workspace['files'], returned: ($result['files'] ?? []));
		} catch (WorkspaceException $e) {
			return self::err(code: $e->getErrorCode(), message: 'The code ran, but nothing was written back: ' . $e->getMessage());
		}

		$result['files'] = [];
		$result['workspace'] = $written;

		return $result;
	}//end dispatchOnWorkspace()

	/**
	 * The named files as run input, read as the acting user, or an error envelope.
	 *
	 * @param string $uid       The acting user.
	 * @param mixed  $requested The `files` argument: a list of {path}.
	 *
	 * @return array<int|string, mixed> A list of {name, content}, or `['error' => ...]`.
	 */
	private function files(string $uid, mixed $requested): array {
		if ($requested === [] || $requested === null) {
			return [];
		}

		if (is_array($requested) === false || array_is_list($requested) === false || count($requested) > CodeSandboxToolDescriptors::MAX_FILES) {
			return self::err(code: 'invalid_argument', message: 'files is a list of at most ' . CodeSandboxToolDescriptors::MAX_FILES . ' paths.');
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
		} catch (Throwable $e) {
			$this->logger->warning('[RunCodeTool] the acting user\'s Files could not be opened', ['reason' => $e->getMessage()]);
			return self::err(code: 'not_found', message: 'Your files could not be opened.');
		}

		$files = [];
		foreach ($requested as $entry) {
			$path = '';
			if (is_array($entry) === true) {
				$path = trim((string)($entry['path'] ?? ''));
			}

			if (trim($path, '/') === '') {
				return self::err(code: 'invalid_argument', message: 'Every file needs a path.');
			}

			// IDOR: resolved inside the acting user's own folder only.
			if ($userFolder->nodeExists($path) === false) {
				return self::err(code: 'not_found', message: 'File not found in your files: ' . $path);
			}

			$node = $userFolder->get($path);
			if (($node instanceof File) === false) {
				return self::err(code: 'not_a_file', message: 'Path is not a file: ' . $path);
			}

			if ($node->getSize() > self::MAX_FILE_BYTES) {
				return self::err(code: 'file_too_large', message: 'A file copied into a run may be at most 1 MB: ' . $path);
			}

			$files[] = ['name' => basename($path), 'content' => (string)$node->getContent()];
		}//end foreach

		return $files;
	}//end files()

	/**
	 * Whether the AI feature is explicitly enabled; any failure reads as not.
	 * The code-step flow node asks the same gate.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-a-flow-can-run-a-code-step-over-each-item-req-sandbox-003
	 */
	public function featureEnabled(): bool {
		try {
			$feature = $this->features->findBySlug(self::FEATURE_SLUG);
			if ($feature === null) {
				return false;
			}

			$data = $feature->getObject();
		} catch (Throwable $e) {
			$this->logger->warning('[RunCodeTool] the agent-code-execution feature lookup failed', ['reason' => $e->getMessage()]);
			return false;
		}

		return ($data['enabled'] ?? false) === true;
	}//end featureEnabled()

	/**
	 * An error envelope.
	 *
	 * @param string $code    The machine code.
	 * @param string $message The message.
	 *
	 * @return array{error: array{code: string, message: string}}
	 */
	private static function err(string $code, string $message): array {
		return ['error' => ['code' => $code, 'message' => $message]];
	}//end err()
}//end class
