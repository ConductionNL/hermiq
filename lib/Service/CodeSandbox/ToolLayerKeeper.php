<?php

/**
 * Keeps an installed tool's layer and records the tool on the conversation's
 * workspace (tools-agent-workspace-and-installed-tools D4).
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use RuntimeException;

/**
 * The run's workspace for an install, and what is kept of one: the layer by
 * its hash beside the workspaces, and the tool on the workspace.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
 */
class ToolLayerKeeper {

	/**
	 * Constructor.
	 *
	 * @param WorkspaceRunScope           $scope      The verified run.
	 * @param ServerSideWorkspaceProvider $workspaces Stores the layer and records the tool.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
	 */
	public function __construct(
		private readonly WorkspaceRunScope $scope,
		private readonly ServerSideWorkspaceProvider $workspaces,
	) {
	}//end __construct()

	/**
	 * The run's agent and the key of its open workspace.
	 *
	 * @return array{agentId: string, workspaceKey: string}
	 *
	 * @throws WorkspaceException When the call is outside a governed run or the run has no workspace.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
	 */
	public function workspace(): array {
		$binding = $this->scope->current();
		$this->workspaces->root(runKey: $binding['workspaceKey']);

		return ['agentId' => $binding['agentId'], 'workspaceKey' => $binding['workspaceKey']];
	}//end workspace()

	/**
	 * Check the layer against its hash, store it by that hash and record the
	 * tool on the workspace.
	 *
	 * @param string               $workspaceKey The conversation's workspace.
	 * @param array<string, mixed> $request      `{manager, package, version}`.
	 * @param array<string, mixed> $installed    The installer's answer.
	 *
	 * @return array{manager: string, package: string, version: string, sha256: string, binaries: array<int, string>}
	 *
	 * @throws RuntimeException When the layer does not match its hash, cannot be stored or the tool cannot be recorded.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#scenario-an-agent-installs-csvkit
	 */
	public function keep(string $workspaceKey, array $request, array $installed): array {
		$sha256 = (string)$installed['layer']['sha256'];
		$this->store(sha256: $sha256, archive: (string)$installed['layer']['archive']);

		$version = (string)$installed['version'];
		if ($version === '') {
			$version = (string)$request['version'];
		}

		$tool = [
			'manager' => (string)$request['manager'],
			'package' => (string)$request['package'],
			'version' => $version,
			'sha256' => $sha256,
			'binaries' => $installed['binaries'],
		];

		try {
			$this->workspaces->recordInstalledTool(runKey: $workspaceKey, tool: $tool);
		} catch (WorkspaceException $e) {
			throw new RuntimeException('The tool was installed, but could not be recorded on the workspace: ' . $e->getMessage());
		}

		return $tool;
	}//end keep()

	/**
	 * Store the layer under its hash once it matches it; an equal layer is stored once.
	 *
	 * @param string $sha256  The claimed hash.
	 * @param string $archive The layer, base64.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the layer does not match its hash or cannot be stored.
	 */
	private function store(string $sha256, string $archive): void {
		$bytes = base64_decode($archive, true);
		if ($bytes === false || hash('sha256', $bytes) !== $sha256) {
			throw new RuntimeException('The installed tool\'s layer does not match its hash; nothing was kept.');
		}

		$store = $this->workspaces->toolLayerStore();
		if (is_dir($store) === false && mkdir($store, 0700, true) === false && is_dir($store) === false) {
			throw new RuntimeException('The tool layer could not be stored.');
		}

		$path = $store . '/' . $sha256 . '.tar.gz';
		if (is_file($path) === false && file_put_contents($path, $bytes) !== strlen($bytes)) {
			throw new RuntimeException('The tool layer could not be stored.');
		}
	}//end store()
}//end class
