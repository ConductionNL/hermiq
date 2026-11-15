<?php

/**
 * Hermiq BudgetScopes.
 *
 * Which budget objects belong to an organisation or agent scope, and which one
 * of a list wins for a scope.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Scope matching for budget objects.
 *
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
 */
final class BudgetScopes {

	/**
	 * Whether a budget object matches the given organisation/agent scope.
	 *
	 * An empty organisation matches no organisation-scoped budget, and a null or
	 * empty agent id matches no agent-scoped budget.
	 *
	 * @param ObjectEntity $budget The budget object.
	 * @param string $organisation The organisation identifier.
	 * @param string|null $agentId The bound agent UUID, when known.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function matches(ObjectEntity $budget, string $organisation, ?string $agentId): bool {
		$scope = $this->scopeOf(budget: $budget);

		if ($scope === 'organisation') {
			return ($organisation !== '' && (string)($budget->getOrganisation() ?? '') === $organisation);
		}

		if ($scope === 'agent') {
			return ($agentId !== null && $agentId !== '' && (string)($budget->getObject()['agentId'] ?? '') === $agentId);
		}

		return false;
	}//end matches()

	/**
	 * The last agent-scoped budget for this agent in a list.
	 *
	 * @param array<int, ObjectEntity> $budgets The budgets, in read order.
	 * @param string|null $agentId The agent UUID; none matches when it is null or ''.
	 *
	 * @return ObjectEntity|null The budget, or null.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function lastAgentBudget(array $budgets, ?string $agentId): ?ObjectEntity {
		$match = null;
		foreach ($budgets as $budget) {
			// With no organisation given, matches() can only match an agent-scoped budget.
			if ($this->matches(budget: $budget, organisation: '', agentId: $agentId) === true) {
				$match = $budget;
			}
		}

		return $match;
	}//end lastAgentBudget()

	/**
	 * The last organisation-scoped budget in a list.
	 *
	 * @param array<int, ObjectEntity> $budgets The budgets, in read order.
	 *
	 * @return ObjectEntity|null The budget, or null.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function lastOrganisationBudget(array $budgets): ?ObjectEntity {
		$match = null;
		foreach ($budgets as $budget) {
			if ($this->scopeOf(budget: $budget) === 'organisation') {
				$match = $budget;
			}
		}

		return $match;
	}//end lastOrganisationBudget()

	/**
	 * A budget's scope: 'organisation', 'agent', or '' when it records none.
	 *
	 * @param ObjectEntity $budget The budget object.
	 *
	 * @return string The scope.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	private function scopeOf(ObjectEntity $budget): string {
		return (string)($budget->getObject()['scope'] ?? '');
	}//end scopeOf()
}//end class
