<?php

/**
 * The hard-cap gate of BudgetService
 *
 * The part of BudgetService that callers outside its component use, so they depend on this contract instead of the implementation.
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

/**
 * The hard-cap gate of BudgetService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetHardCapInterface {

	/**
	 * GATE 2 — whether the organisation's or agent's budget is at (or beyond) its hard
	 * cap for the current period. Read system-wide (fails open on error, exactly like
	 * `ScheduleService::loadEngagedOrganisations()`), so a transient OpenRegister read
	 * error never halts every tenant's runs.
	 *
	 * @param string $organisation The schedule's/run's organisation identifier.
	 * @param string|null $agentId The bound agent UUID, when known.
	 *
	 * @return bool True when at least one matching, enabled budget has reached its cap.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
	 */
	public function isBlocked(string $organisation, ?string $agentId = null): bool;
}//end interface
