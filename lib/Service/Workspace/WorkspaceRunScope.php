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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * Request-scoped holder of the verified run binding.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
 */
class WorkspaceRunScope {

	/**
	 * The verified binding, or null outside a governed run.
	 *
	 * @var array{runId: string, agentId: string, userId: string, conversationId: string, workspaceKey: string}|null
	 */
	private ?array $binding = null;

	/**
	 * Enter a verified run.
	 *
	 * 🔑 THE WORKSPACE KEY COMES FROM THE TOKEN, NEVER FROM THE MODEL. An agent
	 * whose `workspaceMode` is `session` keeps one workspace for its whole
	 * conversation, keyed by agent, conversation and user, so the next run of
	 * the same conversation finds the same files and a run of any other
	 * conversation, user or agent derives a different key. Every other mode, and
	 * a session run without a conversation, keys by the run as before.
	 *
	 * @param string $runId          The run id from the token.
	 * @param string $agentId        The agent id from the token.
	 * @param string $userId         The acting user (the run owner) from the token.
	 * @param string $conversationId The conversation from the token, '' when none.
	 * @param string $workspaceMode  The agent's `workspaceMode`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
	 */
	public function enter(
		string $runId,
		string $agentId,
		string $userId,
		string $conversationId='',
		string $workspaceMode='',
	): void {
		$workspaceKey = $runId;
		if ($workspaceMode === SessionWorkspaceKey::MODE_SESSION) {
			$workspaceKey = (SessionWorkspaceKey::compose(agentId: $agentId, conversationId: $conversationId, userId: $userId) ?? $runId);
		}

		$this->binding = [
			'runId' => $runId,
			'agentId' => $agentId,
			'userId' => $userId,
			'conversationId' => $conversationId,
			'workspaceKey' => $workspaceKey,
		];
	}//end enter()

	/**
	 * Leave the run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
	 */
	public function leave(): void {
		$this->binding = null;
	}//end leave()

	/**
	 * The verified binding.
	 *
	 * @return array{runId: string, agentId: string, userId: string, conversationId: string, workspaceKey: string}
	 *
	 * @throws WorkspaceException token_invalid outside a governed run.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
	 */
	public function current(): array {
		if ($this->binding === null || $this->binding['runId'] === '') {
			throw new WorkspaceException(
				errorCode: WorkspaceException::RUN_SCOPE_INVALID,
				message: 'Workspace tools are only available inside a governed run.'
			);
		}

		return $this->binding;
	}//end current()
}//end class
