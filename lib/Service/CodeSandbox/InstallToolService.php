<?php

/**
 * Hermiq installTool tool (tools-agent-workspace-and-installed-tools D4).
 *
 * Installs one command line tool for the run's conversation. Before the
 * installer is called, the install passes its policy (the agent code
 * execution AI feature, the arguments, the admin's registries and the
 * organisation's package allowlist) and the run must have a workspace. The
 * installer answers a tool layer; the keeper checks it against its hash,
 * stores it by that hash beside the workspaces and records the tool on the
 * conversation's workspace, so a published build pins it. Never throws: every
 * failure is an error envelope.
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use OCA\Hermiq\Service\Workspace\WorkspaceException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The installTool tool behind its policy.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
 */
class InstallToolService {

	/**
	 * Constructor.
	 *
	 * @param InstallPolicy     $policy The gates an install passes first.
	 * @param CodeSandboxClient $client Calls the installer.
	 * @param ToolLayerKeeper   $keeper The run's workspace, and what is kept of an install.
	 * @param LoggerInterface   $logger Records failures, never package content.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public function __construct(
		private readonly InstallPolicy $policy,
		private readonly CodeSandboxClient $client,
		private readonly ToolLayerKeeper $keeper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Install the call's package for the run's conversation.
	 *
	 * @param string               $uid       The acting user.
	 * @param string               $toolId    The tool id; only installTool is served.
	 * @param array<string, mixed> $arguments manager, package, version.
	 *
	 * @return array<string, mixed> manager, package, version, versions, binaries, sha256 and the layer as
	 *   artefact; or `['error' => ['code', 'message']]`.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array {
		$request = $this->policy->request(toolId: $toolId, arguments: $arguments);
		if (isset($request['error']) === true) {
			return $request;
		}

		try {
			$workspace = $this->keeper->workspace();
		} catch (WorkspaceException) {
			return self::err(code: WorkspaceException::WORKSPACE_ABSENT, message: 'Tools are installed into the agent\'s workspace, and this run has none.');
		}

		$refusal = $this->policy->packageRefusal(agentId: $workspace['agentId'], manager: $request['manager'], package: $request['package']);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$installed = $this->client->install(manager: $request['manager'], package: $request['package'], version: $request['version'], uid: $uid);
			$tool = $this->keeper->keep(workspaceKey: $workspace['workspaceKey'], request: $request, installed: $installed);
		} catch (CodeSandboxUnavailableException $e) {
			return self::err(code: 'code_sandbox_unavailable', message: $e->getMessage());
		} catch (RuntimeException $e) {
			$this->logger->warning('[InstallToolService] the install failed', ['reason' => $e->getMessage()]);
			return self::err(code: 'install_failed', message: $e->getMessage());
		}

		return $tool + [
			'versions' => $installed['versions'],
			'artefact' => ['type' => 'toolLayer', 'id' => $tool['sha256']],
		];
	}//end invoke()

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
