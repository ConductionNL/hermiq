<?php

/**
 * Hermiq code sandbox tool descriptor (tools-code-sandbox).
 *
 * Held outside `HermiqToolProvider` so that class stays inside its length
 * budget, like the other descriptor classes. `hermiq.runCode` is
 * write-classified (`scope: create`, `readOnlyHint: false`), so it is
 * default-denied: an agent needs it in `Agent.tools`. Its reach follows the
 * admin's choice of where code runs, so the catalogue is built from the
 * current setting rather than held as a constant.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * Descriptor source for the runCode tool.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */
final class CodeSandboxToolDescriptors {

	/**
	 * The tool id.
	 *
	 * @var string
	 */
	public const RUN_CODE = AppIdentity::APP_ID . '.runCode';

	/**
	 * The install tool id (tools-agent-workspace-and-installed-tools D4).
	 *
	 * @var string
	 */
	public const INSTALL_TOOL = AppIdentity::APP_ID . '.installTool';

	/**
	 * The installed tool run id (tools-agent-workspace-and-installed-tools D5).
	 *
	 * @var string
	 */
	public const RUN_INSTALLED_TOOL = AppIdentity::APP_ID . '.runInstalledTool';

	/**
	 * The most arguments one installed tool run takes.
	 *
	 * @var int
	 */
	public const MAX_ARGS = 64;

	/**
	 * The tool ids RunCodeTool serves.
	 *
	 * @var array<int, string>
	 */
	public const IDS = [self::RUN_CODE];

	/**
	 * The tool ids InstallToolService serves.
	 *
	 * @var array<int, string>
	 */
	public const INSTALL_IDS = [self::INSTALL_TOOL];

	/**
	 * The tool ids RunInstalledToolService serves.
	 *
	 * @var array<int, string>
	 */
	public const RUN_INSTALLED_IDS = [self::RUN_INSTALLED_TOOL];

	/**
	 * The most files one call may name.
	 *
	 * @var int
	 */
	public const MAX_FILES = 10;

	/**
	 * The descriptors for a reach.
	 *
	 * `reach` is where the code and its input go: `self` in the own sandbox,
	 * `external` at a hosted one. `reachWithArguments` states that naming files
	 * of the acting user lifts the reach to at least `user`.
	 *
	 * @param string $reach The setting's reach: `self` or `external`.
	 *
	 * @return array<int, array<string, mixed>> The descriptors.
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-3-the-runcode-tool-and-its-ai-feature-gate
	 */
	public static function forReach(string $reach): array {
		if ($reach !== ToolReachResolver::REACH_EXTERNAL) {
			$reach = ToolReachResolver::REACH_SELF;
		}

		return [
			[
				'id' => self::RUN_CODE,
				'subject' => 'codeRun',
				'action' => 'create',
				'reach' => $reach,
				'reachWithArguments' => ['files' => self::atLeastUser(reach: $reach)],
				'name' => 'Run code',
				'description' => 'Run a short Python or JavaScript program in an isolated sandbox without network '
					. 'access and with limits on time and memory, and get its exit code and output. Use it to '
					. 'calculate, to parse a file you were given, or to check your own reasoning. `files` copies '
					. 'text files from the acting user\'s Files into the run. When the agent keeps a workspace, the '
					. 'run works on a copy of it (or of `paths`) and the files it creates or changes are written back; '
					. '`useWorkspace` false runs without it. Requires the agent code execution AI feature.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'language' => ['type' => 'string', 'enum' => ['python', 'node'], 'description' => 'The language.'],
						'code' => ['type' => 'string', 'description' => 'The program.'],
						'stdin' => ['type' => 'string', 'description' => 'What the program reads on standard input.'],
						'files' => [
							'type' => 'array',
							'maxItems' => self::MAX_FILES,
							'items' => [
								'type' => 'object',
								'properties' => ['path' => ['type' => 'string', 'description' => 'A path in the acting user\'s Files.']],
								'required' => ['path'],
							],
							'description' => 'Files of the acting user to place in the run\'s work directory.',
						],
						'useWorkspace' => [
							'type' => 'boolean',
							'description' => 'Run on the agent\'s workspace and write back what the run changes. Default true when '
								. 'the agent has a workspace and no `files` are named.',
						],
					] + self::workspaceRunProperties(),
					'required' => ['language', 'code'],
				],
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => false,
				'scope' => 'create',
			],
			self::installTool(),
			self::runInstalledTool(reach: $reach),
		];
	}//end forReach()

	/**
	 * The runInstalledTool descriptor: it matches runCode, because an installed
	 * binary runs in the same sandbox, on the same workspace copy, under the
	 * same limits and without network.
	 *
	 * @param string $reach The setting's reach: `self` or `external`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
	 */
	public static function runInstalledTool(string $reach): array {
		return [
			'id' => self::RUN_INSTALLED_TOOL,
			'subject' => 'codeRun',
			'action' => 'create',
			'reach' => self::atLeastUser(reach: $reach),
			'name' => 'Run an installed tool',
			'description' => 'Run a command line tool installed with hermiq.installTool in this conversation, on the '
				. 'agent\'s workspace, in the isolated sandbox without network access. `args` is a list: each entry '
				. 'reaches the tool as one argument and no shell reads it. The files the tool creates or changes in '
				. 'the workspace are written back. Requires the agent code execution AI feature.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'tool' => ['type' => 'string', 'description' => 'The installed binary, such as csvstat.'],
					'args' => [
						'type' => 'array',
						'maxItems' => self::MAX_ARGS,
						'items' => ['type' => 'string'],
						'description' => 'The arguments, each passed as one argument.',
					],
					'stdin' => ['type' => 'string', 'description' => 'What the tool reads on standard input.'],
				] + self::workspaceRunProperties(),
				'required' => ['tool'],
			],
			'readOnlyHint' => false,
			'destructiveHint' => false,
			'idempotentHint' => false,
			'scope' => 'create',
		];
	}//end runInstalledTool()

	/**
	 * The installTool descriptor. Its reach is `external` whatever the sandbox
	 * setting, because the package name goes to a registry; `destructiveHint`
	 * because it brings third-party code into the agent's runs. So it is
	 * default-denied and approval-gated when not granted.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public static function installTool(): array {
		return [
			'id' => self::INSTALL_TOOL,
			'subject' => 'toolInstall',
			'action' => 'create',
			'reach' => ToolReachResolver::REACH_EXTERNAL,
			'name' => 'Install a command line tool',
			'description' => 'Install a command line tool from PyPI (pip) or npm into the agent\'s workspace for this '
				. 'conversation, without running any install script, and get its binaries and the resolved versions. '
				. 'Only the registries the admin allows and, when the organisation keeps one, the packages on its '
				. 'allowlist can be installed. Requires the agent code execution AI feature and a workspace.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'manager' => ['type' => 'string', 'enum' => ['pip', 'npm'], 'description' => 'The package manager, and so the registry.'],
					'package' => ['type' => 'string', 'description' => 'The package name, such as csvkit.'],
					'version' => ['type' => 'string', 'description' => 'One exact version, such as 2.1.0; the latest when left out.'],
				],
				'required' => ['manager', 'package'],
			],
			'readOnlyHint' => false,
			'destructiveHint' => true,
			'idempotentHint' => false,
			'scope' => 'create',
		];
	}//end installTool()

	/**
	 * The input properties every sandbox run on the workspace takes: the paths
	 * to send and the limits.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005
	 */
	private static function workspaceRunProperties(): array {
		return [
			'paths' => [
				'type' => 'array',
				'items' => ['type' => 'string'],
				'description' => 'Workspace paths to send instead of the whole workspace (limit 10 MB together).',
			],
			'timeoutSeconds' => ['type' => 'integer', 'description' => 'Wall time limit (default 30, max 300).'],
			'memoryMb' => ['type' => 'integer', 'description' => 'Memory limit (default 512, max 2048).'],
		];
	}//end workspaceRunProperties()

	/**
	 * The reach lifted to at least `user`.
	 *
	 * @param string $reach The reach.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tools-code-sandbox/tasks.md#task-3-the-runcode-tool-and-its-ai-feature-gate
	 */
	public static function atLeastUser(string $reach): string {
		if ($reach === ToolReachResolver::REACH_SELF) {
			return ToolReachResolver::REACH_USER;
		}

		return $reach;
	}//end atLeastUser()
}//end class
