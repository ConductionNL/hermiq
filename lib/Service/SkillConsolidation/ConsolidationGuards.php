<?php

/**
 * Hermiq ConsolidationGuards
 *
 * What may stop or hold a consolidation: the kill switch, budgets, content scanning and approvals.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\SkillConsolidation
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

namespace OCA\Hermiq\Service\SkillConsolidation;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\OpenRegister\Service\ContentScanService;

/**
 * What may stop or hold a consolidation: the kill switch, budgets, content scanning and approvals.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ConsolidationGuards {

	/**
	 * Constructor.
	 *
	 * @param TenantKillSwitch $tenantKillSwitch Org kill-switch check (same gate as a schedule tick).
	 * @param BudgetService $budgetService Budget hard-cap gate (same gate as a schedule tick).
	 * @param ContentScanService $contentScanService OpenRegister heuristic content scanner.
	 * @param ApprovalService $approvalService The ONE human-decision surface (Approval objects).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly TenantKillSwitch $tenantKillSwitch,
		public readonly BudgetService $budgetService,
		public readonly ContentScanService $contentScanService,
		public readonly ApprovalService $approvalService,
	) {
	}//end __construct()
}//end class
