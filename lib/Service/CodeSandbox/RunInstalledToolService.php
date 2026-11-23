<?php

/**
 * Hermiq runInstalledTool tool (tools-agent-workspace-and-installed-tools D5).
 *
 * Runs a binary installed with hermiq.installTool in the run's conversation,
 * on a copy of the agent's workspace, in the sandbox. The arguments are a list
 * of strings that reach the binary as they are, without a shell. The sandbox
 * finds the tool layer by its hash, or is sent its bytes once when it lacks
 * them. What the binary creates or changes in the workspace copy is written
 * back. Never throws: every failure is an error envelope.
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use OCA\Hermiq\Mcp\CodeSandboxToolDescriptors;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use RuntimeException;

/**
 * The runInstalledTool tool.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
 */
class RunInstalledToolService {

	/**
	 * A binary's plain name.
	 *
	 * @var string
	 */
	private const TOOL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

	/**
	 * Constructor.
	 *
	 * @param RunCodeTool       $runCode  Answers whether the code execution AI feature is on.
	 * @param CodeSandboxClient $client   Sends the run.
	 * @param ToolLayerKeeper   $keeper   The run's workspace, its installed tools and their layers.
	 * @param WorkspaceTransfer $transfer Copies the workspace into the run and writes back what it changed.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
	 */
	public function __construct(
		private readonly RunCodeTool $runCode,
		private readonly CodeSandboxClient $client,
		private readonly ToolLayerKeeper $keeper,
		private readonly WorkspaceTransfer $transfer,
	) {
	}//end __construct()

	/**
	 * Run the call's installed tool on the workspace.
	 *
	 * @param string               $uid       The acting user.
	 * @param string               $toolId    The tool id; only runInstalledTool is served.
	 * @param array<string, mixed> $arguments tool, args, stdin, paths, timeoutSeconds, memoryMb.
	 *
	 * @return array<string, mixed> exitCode, stdout, stderr, durationMs, timedOut, limits, the tool and package,
	 *   and `workspace: {written, refused}`; or `['error' => ['code', 'message']]`.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-shell-syntax-is-not-interpreted
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		$refusal = $this->refusal(toolId: $toolId, arguments: $arguments);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$workspaceKey = $this->keeper->workspace()['workspaceKey'];
			$installed = $this->keeper->toolOffering(workspaceKey: $workspaceKey, binary: (string)$arguments['tool']);
			if ($installed === null) {
				$message = 'No tool installed in this conversation offers ' . $arguments['tool'] . '. Install it with hermiq.installTool first.';
				return self::err(code: 'tool_not_installed', message: $message);
			}

			$files = $this->transfer->pack(workspaceKey: $workspaceKey, paths: (array)($arguments['paths'] ?? []));
			$result = $this->client->runInstalled(
				call: [
					'sha256' => $installed['sha256'],
					'tool' => $arguments['tool'],
					'args' => (array)($arguments['args'] ?? []),
					'stdin' => (string)($arguments['stdin'] ?? ''),
					'limits' => ['wallSeconds' => ($arguments['timeoutSeconds'] ?? null), 'memoryMb' => ($arguments['memoryMb'] ?? null)],
				],
				files: $files,
				layer: fn (): string => $this->keeper->layer(sha256: (string)$installed['sha256']),
				uid: $uid
			);
			$written = $this->transfer->writeBack(workspaceKey: $workspaceKey, sent: $files, returned: ($result['files'] ?? []));
		} catch (WorkspaceException $e) {
			return self::err(code: $e->getErrorCode(), message: $e->getMessage());
		} catch (CodeSandboxUnavailableException $e) {
			return self::err(code: 'code_sandbox_unavailable', message: $e->getMessage());
		} catch (RuntimeException $e) {
			return self::err(code: 'tool_run_failed', message: $e->getMessage());
		}//end try

		return ['files' => [], 'workspace' => $written, 'tool' => $arguments['tool'], 'package' => $installed['package']] + $result;
	}//end invoke()

	/**
	 * Why the call cannot run: another tool, the AI feature off, or arguments
	 * that are not a binary's name and a list of strings; null when it can.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return array{error: array{code: string, message: string}}|null
	 */
	private function refusal(string $toolId, array $arguments): ?array {
		if ($toolId !== CodeSandboxToolDescriptors::RUN_INSTALLED_TOOL) {
			return self::err(code: 'unknown_tool', message: "Unknown tool id '" . $toolId . "'.");
		}

		if ($this->runCode->featureEnabled() === false) {
			return self::err(code: 'code_execution_not_enabled', message: RunCodeTool::NOT_ENABLED);
		}

		$tool = ($arguments['tool'] ?? null);
		if (is_string($tool) === false || preg_match(self::TOOL_PATTERN, $tool) !== 1) {
			return self::err(code: 'invalid_argument', message: 'tool is the plain name of an installed binary.');
		}

		if (self::isArgumentList(args: ($arguments['args'] ?? [])) === false) {
			return self::err(code: 'invalid_argument', message: 'args is a list of at most ' . CodeSandboxToolDescriptors::MAX_ARGS . ' strings.');
		}

		return null;
	}//end refusal()

	/**
	 * Whether the arguments are a list of strings within the limit.
	 *
	 * @param mixed $args The `args` argument.
	 *
	 * @return bool
	 */
	private static function isArgumentList(mixed $args): bool {
		if (is_array($args) === false || array_is_list($args) === false || count($args) > CodeSandboxToolDescriptors::MAX_ARGS) {
			return false;
		}

		return array_filter($args, static fn ($arg): bool => is_string($arg) === false) === [];
	}//end isArgumentList()

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
