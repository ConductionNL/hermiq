<?php

/**
 * Contract of OrganisationOpsService
 *
 * The public surface of OrganisationOpsService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of OrganisationOpsService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrganisationOpsServiceInterface {

	/**
	 * List every Agent in the caller's organisation with owner, actingUser,
	 * last-run timestamp, a tool/RAG capability summary, and the reassignment/
	 * review-attestation state (agent-lifecycle-governance periodic access review).
	 *
	 * @return array<string, mixed> `{ agents: [...] }`, tenant-scoped to the caller.
	 *
	 * @spec openspec/specs/agent-lifecycle-governance/spec.md#requirement-periodic-access-review-with-capability-summary
	 */
	public function accessReviewList(): array;

	/**
	 * Record a "reviewed" attestation for one Agent (idempotent — re-attesting
	 * updates the timestamp/reviewer rather than duplicating a record).
	 *
	 * @param string $uuid The Agent UUID.
	 * @param string $reviewerUid The reviewing user's id.
	 *
	 * @return array<string, mixed> The updated agent's access-review row.
	 *
	 * @throws RuntimeException When the Agent does not exist (in the caller's tenant scope).
	 *
	 * @spec openspec/specs/agent-lifecycle-governance/spec.md#requirement-reviewed-attestation-is-recorded-and-auditable
	 */
	public function attestAgentReviewed(string $uuid, string $reviewerUid): array;

	/**
	 * Open a new incident record, scoped to the caller's organisation.
	 *
	 * @param string $description What happened.
	 * @param string $impact The incident's impact.
	 * @param string $actionsTaken The remedial actions taken.
	 * @param string|null $linkedAgentId Optional linked Agent UUID.
	 * @param array<int, string> $linkedRunIds Optional linked run (AuditTrail entry) uuids.
	 * @param string $createdBy The org admin opening the incident.
	 *
	 * @return array<string, mixed> The created incident.
	 *
	 * @spec openspec/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents
	 */
	public function createIncident(
		string $description,
		string $impact,
		string $actionsTaken,
		?string $linkedAgentId,
		array $linkedRunIds,
		string $createdBy,
	): array;

	/**
	 * Produce a per-tenant EU AI Act audit export scoped to the caller's own objects.
	 *
	 * @return array<string, mixed> The export payload.
	 *
	 * @spec openspec/changes/archive/2026-09-06-multi-tenant-ops/tasks.md#task-1-2
	 */
	public function exportAuditTrail(): array;

	/**
	 * The caller's organisation's currently configured retention period, in
	 * months (EU AI Act Art. 12), defaulting to 6 when unconfigured.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration
	 */
	public function getRetentionMonths(): int;

	/**
	 * List the caller's organisation's incident records, newest first.
	 *
	 * @return array<string, mixed> `{ incidents: [...] }`, tenant-scoped to the caller.
	 *
	 * @spec openspec/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents
	 */
	public function listIncidents(): array;

	/**
	 * Report the caller's organisation quota usage against the configured limits.
	 *
	 * @return array<string, mixed> The quota status payload.
	 *
	 * @spec openspec/changes/archive/2026-09-06-multi-tenant-ops/tasks.md#task-1-1
	 */
	public function quotaStatus(): array;

	/**
	 * Reassign a flagged Agent's `actingUser` to a new, existing, active
	 * Nextcloud user, clearing the reassignment flag. Never re-enables any
	 * Schedule the offboarding pause disabled — that stays a separate, explicit,
	 * auditable org-admin action (proposal.md Open Question 2).
	 *
	 * @param string $uuid The Agent UUID.
	 * @param string $newActingUser The target user id.
	 *
	 * @return array<string, mixed> The updated agent's access-review row.
	 *
	 * @throws RuntimeException When the Agent does not exist (in the caller's tenant scope).
	 * @throws InvalidArgumentException When the target user does not exist or is not active.
	 *
	 * @spec openspec/specs/agent-lifecycle-governance/spec.md#requirement-org-admin-reassignment-flow-for-flagged-agents
	 */
	public function reassignAgent(string $uuid, string $newActingUser): array;

	/**
	 * Configure the caller's organisation's retention period. Rejects any value
	 * below the Art. 12 minimum (6 months) — the stored value is left unchanged.
	 *
	 * @param int $months The new retention period, in months.
	 *
	 * @return int The persisted retention period.
	 *
	 * @throws InvalidArgumentException When `$months` is below the 6-month minimum.
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration
	 */
	public function setRetentionMonths(int $months): int;
}//end interface
