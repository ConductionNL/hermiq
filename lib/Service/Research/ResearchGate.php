<?php

/**
 * Hermiq ResearchGate.
 *
 * What a research run checks before every model call (design D4): whether the
 * user pressed "Stop research", whether the organisation's kill switch is
 * engaged, and whether the organisation's or agent's budget is at its hard
 * cap. Any of the three ends the run without a report; the answer is the
 * sentence the conversation shows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\TenantKillSwitch;

/**
 * Decides whether a research run may make its next model call.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */
final class ResearchGate {

	/**
	 * What the conversation says when the user stopped the run.
	 */
	public const STOPPED = 'Research stopped.';

	/**
	 * What the conversation says when the organisation's kill switch is engaged.
	 */
	public const KILL_SWITCH = 'Research ended: agent runs are switched off for this organisation.';

	/**
	 * What the conversation says when the budget is at its hard cap.
	 */
	public const BUDGET = 'Research ended: the budget for this organisation or agent has reached its limit.';

	/**
	 * Constructor.
	 *
	 * @param BudgetService    $budgets    The hard cap check (GATE 2).
	 * @param TenantKillSwitch $killSwitch The organisation kill switch (GATE 1).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BudgetService $budgets,
		private readonly TenantKillSwitch $killSwitch,
	) {
	}//end __construct()

	/**
	 * Why the run must end before its next model call, or null when it may go on.
	 *
	 * The user's stop is read first, so a stopped run never asks the budget or
	 * the kill switch again. The kill switch comes before the budget: it halts
	 * every run of the organisation, whatever is left to spend.
	 *
	 * @param string      $organisation  The run's organisation.
	 * @param string|null $agentId       The agent running the research, when known.
	 * @param bool        $stopRequested Whether the user pressed "Stop research".
	 *
	 * @return string|null The sentence that ends the run, or null.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function endReason(string $organisation, ?string $agentId, bool $stopRequested): ?string {
		if ($stopRequested === true) {
			return self::STOPPED;
		}

		if ($this->killSwitch->isOrganisationEngaged(organisation: $organisation) === true) {
			return self::KILL_SWITCH;
		}

		if ($this->budgets->isBlocked(organisation: $organisation, agentId: $agentId) === true) {
			return self::BUDGET;
		}

		return null;
	}//end endReason()
}//end class
