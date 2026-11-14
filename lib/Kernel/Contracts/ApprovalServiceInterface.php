<?php

/**
 * Contract of ApprovalService
 *
 * The public surface of ApprovalService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of ApprovalService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApprovalServiceInterface {

	/**
	 * Approve a pending Approval and execute the gated run.
	 *
	 * Transitions the Approval to `approved` (decidedBy/decidedAt), audits the
	 * decision, then resumes the gated run matching `sourceType`: a Schedule via
	 * `ScheduleService::runNow()` (the original path), a flow-triggered agent run
	 * via `FlowAgentRunService::run()`, or a webhook-triggered agent run via
	 * `WebhookAgentRunService::run()` — all three with the approval gate bypassed
	 * for this authorised occurrence, and none loops back into another pending
	 * Approval. A non-pending Approval is a no-op (no run).
	 *
	 * @param ObjectEntity $approval The pending approval to authorise.
	 * @param string $deciderUid The reviewer/admin making the decision.
	 *
	 * @return array{status:string, ran:bool} The resulting status and whether a run fired.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 * @spec openspec/changes/archive/2026-09-06-flow-agent-listener/tasks.md#3-approvalservice-generalisation-sourcetype-flow
	 * @spec openspec/changes/archive/2026-07-12-agent-webhook-trigger/tasks.md#task-5-approvalservice-sourcetype-webhook-generalisation
	 */
	public function approve(ObjectEntity $approval, string $deciderUid): array;

	/**
	 * Deny a pending Approval — the gated run never executes.
	 *
	 * Transitions the Approval to `denied` (decidedBy/decidedAt/reason) and audits the
	 * decision (reason redacted before the append-only write). A non-pending Approval
	 * is a no-op.
	 *
	 * @param ObjectEntity $approval The pending approval to deny.
	 * @param string $deciderUid The reviewer/admin making the decision.
	 * @param string|null $reason Optional free-text denial reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 */
	public function deny(ObjectEntity $approval, string $deciderUid, ?string $reason): void;

	/**
	 * Whether the given user may decide the given Approval (separation of duties).
	 *
	 * Admits the resolved reviewer user, a member of the reviewer group, or a
	 * Nextcloud instance admin. The schedule owner is admitted only when they are
	 * themselves the reviewer (Art. 14 separation of duties).
	 *
	 * @param ObjectEntity $approval The approval object.
	 * @param string $uid The requesting user's UID.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
	 */
	public function isReviewer(ObjectEntity $approval, string $uid): bool;

	/**
	 * List the pending Approvals routed to the given user as reviewer.
	 *
	 * Returns approvals where the user is the reviewer user, or a member of the
	 * reviewer group. Instance-admin visibility is deliberately NOT included — this
	 * is the "routed to me" inbox, not a global queue.
	 *
	 * @param string $uid The requesting user's UID.
	 *
	 * @return array<int, array<string, mixed>> Compact pending-approval records.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 */
	public function listPendingForReviewer(string $uid): array;

	/**
	 * Load an Approval object by UUID without RBAC (the caller applies the guard).
	 *
	 * The Approval is owned by the schedule owner, but the decider is the reviewer —
	 * a different user — so an RBAC read would hide it. It is loaded RBAC-off and the
	 * caller MUST authorise via isReviewer() before acting (IDOR guard).
	 *
	 * @param string $uuid The Approval object UUID.
	 *
	 * @return ObjectEntity|null The approval, or null when absent.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 */
	public function loadApproval(string $uuid): ?ObjectEntity;
}//end interface
