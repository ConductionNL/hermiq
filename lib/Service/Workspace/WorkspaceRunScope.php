<?php

/**
 * Hermiq WorkspaceRunScope.
 *
 * The run a governed workspace tool call belongs to, set once per request by
 * the governed MCP endpoint from the VERIFIED run token and read by the
 * workspace toolset. It is never filled from tool arguments, so the model cannot
 * name another run's workspace: the workspace served is always the one derived
 * from the caller's own token. A request that did not come through that
 * endpoint (the in-process tool loop, a controller) has no scope, and every
 * workspace tool refuses it.
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
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * Request-scoped holder of the verified run binding.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
 */
class WorkspaceRunScope {

	/**
	 * The verified binding, or null outside a governed run.
	 *
	 * @var array{runId: string, agentId: string, userId: string}|null
	 */
	private ?array $binding = null;

	/**
	 * Enter a verified run.
	 *
	 * @param string $runId   The run id from the token.
	 * @param string $agentId The agent id from the token.
	 * @param string $userId  The acting user (the run owner) from the token.
	 *
	 * @return void
	 */
	public function enter(string $runId, string $agentId, string $userId): void {
		$this->binding = ['runId' => $runId, 'agentId' => $agentId, 'userId' => $userId];
	}//end enter()

	/**
	 * Leave the run.
	 *
	 * @return void
	 */
	public function leave(): void {
		$this->binding = null;
	}//end leave()

	/**
	 * The verified binding.
	 *
	 * @return array{runId: string, agentId: string, userId: string}
	 *
	 * @throws WorkspaceException token_invalid outside a governed run.
	 */
	public function current(): array {
		if ($this->binding === null || $this->binding['runId'] === '') {
			throw new WorkspaceException(
				errorCode: WorkspaceException::TOKEN_INVALID,
				message: 'Workspace tools are only available inside a governed run.'
			);
		}

		return $this->binding;
	}//end current()
}//end class
