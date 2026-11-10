<?php

/**
 * Hermiq WorkspaceWriteAuthoriser.
 *
 * The approval gate every write-shaped workspace tool passes before it touches
 * anything. The authorisation is a RUN-SCOPED PRE-AUTHORISATION: one Approval
 * record (`sourceType: workspace-run`) keyed by the agent and the run id from
 * the verified token, decided by a named person. Once approved it covers the
 * writes of that run on the workspace it names and nothing else: another run of
 * the same agent has another run id, so it needs its own.
 *
 * There is no setting that skips this gate. The `#noapproval` grant fragment is
 * consulted by the tool invoker for other tools; this class never reads it, and
 * nothing else can let a workspace write through.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCA\Hermiq\Service\ApprovalService;

/**
 * Refuses a workspace write that no person has authorised for this run.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form
 */
class WorkspaceWriteAuthoriser {

	/**
	 * Build the authoriser.
	 *
	 * @param ApprovalService $approvals The human-approval state machine.
	 */
	public function __construct(
		private readonly ApprovalService $approvals,
	) {
	}//end __construct()

	/**
	 * Pass when this run holds an approved pre-authorisation; otherwise request
	 * one (once) and refuse.
	 *
	 * @param array{runId: string, agentId: string, userId: string} $run       The verified run.
	 * @param string                                               $toolId    The write-shaped tool being called.
	 * @param array{repository: string, ref: string}               $workspace What the run's workspace is on.
	 *
	 * @return array{approvalId: string, decidedBy: string} The authorising decision.
	 *
	 * @throws WorkspaceException approval_required or approval_denied.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-an-unapproved-write-is-refused-before-it-happens
	 */
	public function assertAuthorised(array $run, string $toolId, array $workspace): array {
		$this->assertHasAgent(run: $run);

		$decision = $this->approvals->runPreAuthorisation(agentId: $run['agentId'], runId: $run['runId']);
		$authorised = $this->authorisedBy(decision: $decision);
		if ($authorised !== null) {
			return $authorised;
		}

		$pending = $decision;
		if ($pending === null) {
			$pending = $this->approvals->requestRunPreAuthorisation(
				agentId: $run['agentId'],
				runId: $run['runId'],
				toolId: $toolId,
				repository: $workspace['repository'],
				ref: $workspace['ref']
			);
		}

		throw new WorkspaceException(
			errorCode: WorkspaceException::APPROVAL_REQUIRED,
			message: 'A person has to approve changes to ' . $workspace['repository'] . ' in this run first. '
				. 'The approval request is ' . $pending['uuid'] . '. Nothing was written.'
		);
	}//end assertAuthorised()

	/**
	 * Refuse a run that has no agent to route an approval to.
	 *
	 * @param array{runId: string, agentId: string, userId: string} $run The verified run.
	 *
	 * @return void
	 *
	 * @throws WorkspaceException approval_required when the run has no agent.
	 */
	private function assertHasAgent(array $run): void {
		if ($run['agentId'] === '') {
			throw new WorkspaceException(
				errorCode: WorkspaceException::APPROVAL_REQUIRED,
				message: 'This run has no agent to route an approval to, so it cannot write.'
			);
		}
	}//end assertHasAgent()

	/**
	 * The authorising decision when the run's pre-authorisation is approved and signed;
	 * a denied one refuses; anything else is null.
	 *
	 * @param array<string, string>|null $decision The run's pre-authorisation, or null.
	 *
	 * @return array{approvalId: string, decidedBy: string}|null The decision, or null when still open.
	 *
	 * @throws WorkspaceException approval_denied when a reviewer refused.
	 */
	private function authorisedBy(?array $decision): ?array {
		if ($decision !== null && $this->isSignedApproval(decision: $decision) === true) {
			return ['approvalId' => $decision['uuid'], 'decidedBy' => $decision['decidedBy']];
		}

		if ($decision !== null && $decision['status'] === 'denied') {
			throw new WorkspaceException(
				errorCode: WorkspaceException::APPROVAL_DENIED,
				message: 'A reviewer refused changes to this repository in this run. Do not try again in this run.'
			);
		}

		return null;
	}//end authorisedBy()

	/**
	 * Whether the decision is an approval someone signed.
	 *
	 * @param array<string, string> $decision The run's pre-authorisation.
	 *
	 * @return bool True for an approved decision with a decider.
	 */
	private function isSignedApproval(array $decision): bool {
		return $decision['status'] === 'approved' && $decision['decidedBy'] !== '';
	}//end isSignedApproval()
}//end class
