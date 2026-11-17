<?php

/**
 * Contract of BudgetService
 *
 * The public surface of BudgetService that other components call, so they depend
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
use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Contract of BudgetService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetServiceInterface {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * Public because `BudgetController::destroy()` addresses the same register when
	 * it deletes a Budget through OpenRegister directly (ADR-022: no app-local CRUD
	 * wrapper) — the slug is declared once, here.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for Budget objects. Namespaced (`agentbudget`, not
	 * `budget`) because OpenRegister resolves schema slugs GLOBALLY across all
	 * registers — the generic `budget` slug collided with another app's Budget
	 * schema, making every write fail validation against foreign required
	 * properties (same fix as `agentsession`/`agentskill`).
	 *
	 * Public for the same reason as REGISTER_SLUG above.
	 *
	 * @var string
	 */
	public const BUDGET_SCHEMA = 'agentbudget';

	/**
	 * Create a Budget for an organisation (admin/owner-gated by the controller).
	 *
	 * @param array<string, mixed> $payload The requested budget fields.
	 * @param string $organisation The organisation to pin the budget to.
	 *
	 * @return array<string, mixed> The shaped, created budget.
	 *
	 * @throws InvalidArgumentException When the payload fails cross-field validation.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function create(array $payload, string $organisation): array;

	/**
	 * Pre-run rough cost estimate for one agent — the trailing average tokens per run,
	 * derived from `AnalyticsService::computeAnalytics()`'s existing aggregation. NEVER
	 * read by the enforcement gate (see `isBlocked()`) — advisory-only.
	 *
	 * @param string $agentId The agent UUID to estimate for.
	 *
	 * @return array<string, mixed> The estimate payload (design.md shape).
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
	 */
	public function estimateNextRun(string $agentId): array;

	/**
	 * Find a single Budget object by UUID, system-wide (used by the controller's
	 * update/delete authorization check — it needs the budget's organisation before it
	 * can decide whether the caller may administer it).
	 *
	 * @param string $budgetId The Budget object UUID.
	 *
	 * @return ObjectEntity|null The budget, or null when not found.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function findById(string $budgetId): ?ObjectEntity;

	/**
	 * List budgets visible to the caller (RBAC/multitenancy ON — tenant-scoped),
	 * optionally narrowed to one organisation (defense-in-depth; RBAC already
	 * restricts to the caller's own accessible objects).
	 *
	 * @param string $organisation Optional organisation filter.
	 *
	 * @return array<int, array<string, mixed>> The shaped budget list.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function listForCaller(string $organisation = ''): array;

	/**
	 * Current-period status for one scope, tenant-scoped (RBAC/multitenancy ON) — a
	 * caller-supplied organisation/agentId never itself grants visibility; OpenRegister's
	 * own tenant filtering is the boundary.
	 *
	 * @param string $organisation The organisation identifier.
	 * @param string|null $agentId Optional agent UUID (agent-scoped budget).
	 *
	 * @return array<string, mixed> The status payload, or a "not configured" default.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function statusForScope(string $organisation, ?string $agentId = null): array;

	/**
	 * Update a Budget (admin/owner-gated by the controller).
	 *
	 * @param string $budgetId The Budget object UUID.
	 * @param array<string, mixed> $payload The fields to update.
	 *
	 * @return array<string, mixed> The shaped, updated budget.
	 *
	 * @throws RuntimeException When the budget cannot be found.
	 * @throws InvalidArgumentException When the merged payload fails validation.
	 *
	 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
	 */
	public function update(string $budgetId, array $payload): array;
}//end interface
