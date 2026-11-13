<?php

/**
 * Hermiq RunGovernance
 *
 * What governs a scheduled run: redaction, approvals, budgets, guardrails and retention.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use OCA\Hermiq\Service\AiFeature\RunRetentionPolicy;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\RedactionService;

/**
 * What governs a scheduled run: redaction, approvals, budgets, guardrails and retention.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class RunGovernance {

	/**
	 * Constructor.
	 *
	 * @param RedactionService $redactionService Masks secrets/PII BEFORE the audit write (run-audit-log).
	 * @param ApprovalService $approvalService Human-approval gate: ensures a pending Approval
	 *                                         for gated runs (human-approval-gate-enforcement).
	 * @param BudgetService $budgetService Budget hard-cap gate + soft-threshold warning (cost-guardrails).
	 * @param GuardrailPolicyService $guardrailPolicyService Resolves + applies the effective GuardrailPolicy's
	 *                                                       input filter (legacy ChatService branch only —
	 *                                                       the Engine branch already applies it internally)
	 *                                                       and the defense-in-depth output filter at
	 *                                                       runAgentAsOwner()'s single return point
	 *                                                       (agent-guardrails).
	 * @param RunRetentionPolicy|null $retentionPolicy Stamps each run entry with the
	 *                                                 retention that applied when it was written
	 *                                                 (what-the-model-reads-and-what-is-kept). Nullable and
	 *                                                 trailing: a service built by hand in a test writes the
	 *                                                 entry it always wrote.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly RedactionService $redactionService,
		public readonly ApprovalService $approvalService,
		public readonly BudgetService $budgetService,
		public readonly GuardrailPolicyService $guardrailPolicyService,
		public readonly ?RunRetentionPolicy $retentionPolicy = null,
	) {
	}//end __construct()
}//end class
