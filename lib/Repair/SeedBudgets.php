<?php

/**
 * Hermiq Seed Budgets Repair Step.
 *
 * Idempotently seeds realistic `Budget` guardrail objects (cost-guardrails) on
 * install/upgrade, via `BudgetService::create()` — the same single write-path (OR
 * `ObjectService`, ADR-001/ADR-004) `BudgetController` uses. Re-running is safe: a
 * budget matching an existing (scope, agentId, period, organisation) tuple is skipped.
 *
 * Budget objects are tenant-scoped (`ObjectEntity.organisation`, exactly like
 * `TenantControl`), so seeding is CONDITIONAL on prerequisite OpenRegister objects
 * already existing:
 *   - The organisation-scoped budget needs at least one OpenRegister organisation to
 *     pin itself to; on a genuinely fresh install (no organisation created yet) it is
 *     deferred, not fabricated into a bogus tenant.
 *   - The two agent-scoped budgets need a matching named `agent` object ("Daily
 *     Briefing", "Heavy Tool Runner") to resolve their `agentId`/organisation from;
 *     when no such agent exists yet, that row is skipped (logged), never invented.
 * This mirrors `SeedAiFeatures`'s own graceful-degradation contract (no-ops cleanly
 * when its prerequisites are absent) rather than the schedule/agent seed pattern,
 * which has no tenancy precondition.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\Hermiq\Repair\Support\FirstOrganisation;
use OCA\Hermiq\Repair\Support\OrganisationSeed;
use OCA\Hermiq\Service\BudgetService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the realistic Budget guardrail objects via BudgetService (idempotent).
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
class SeedBudgets implements IRepairStep {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for Budget objects. Namespaced (`agentbudget`, not
	 * `budget`) because OpenRegister resolves schema slugs GLOBALLY across all
	 * registers — the generic `budget` slug collided with another app's Budget
	 * schema, making every seed fail validation against foreign required
	 * properties (same fix as `agentsession`/`agentskill`).
	 *
	 * @var string
	 */
	private const BUDGET_SCHEMA = 'agentbudget';

	/**
	 * OpenRegister schema slug for agent objects.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * The organisation-scoped budget seeded on the first organisation.
	 *
	 * @var array{scope: string, period: string, tokenLimit: int, softThresholdPercent: int}
	 */
	private const ORGANISATION_BUDGET = [
		'scope' => 'organisation',
		'period' => 'monthly',
		'tokenLimit' => 2000000,
		'softThresholdPercent' => 80,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container for lazy service resolution
	 *                                      (OpenRegister may not be installed yet).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function getName(): string {
		return 'Seed cost-guardrail budgets (cost-guardrails)';
	}//end getName()

	/**
	 * Seed the Budget objects whose prerequisites (an organisation, and for
	 * agent-scoped rows, a matching agent) already exist and that do not yet exist
	 * (matched by scope/agentId/period/organisation).
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function run(IOutput $output): void {
		$run = $this->startRun(output: $output);
		if ($run === null) {
			return;
		}

		$seeded = $this->seedOrganisationBudget(run: $run);
		foreach ($this->agentSeedRows() as $row) {
			$seeded += $this->seedAgentBudget(run: $run, row: $row);
		}

		$output->info('Budget seed complete (' . $seeded . ' new).');

	}//end run()

	/**
	 * Resolve the OpenRegister services the seed needs, or warn and answer null when
	 * OpenRegister is not available.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return BudgetSeedRun|null
	 */
	private function startRun(IOutput $output): ?BudgetSeedRun {
		$run = new BudgetSeedRun(output: $output);
		try {
			$run->objectService = $this->container->get(ObjectService::class);
			$run->organisationMapper = $this->container->get(OrganisationMapper::class);
			$run->budgetService = $this->container->get(BudgetService::class);
		} catch (Throwable $e) {
			$output->warning('OpenRegister not available — skipping budget seed.');
			$this->logger->warning('[hermiq] Budget seed skipped: ' . $e->getMessage());
			return null;
		}

		return $run;
	}//end startRun()

	/**
	 * Seed the organisation-scoped budget on the first organisation, or defer it when
	 * no organisation exists yet.
	 *
	 * @param BudgetSeedRun $run The seed run.
	 *
	 * @return int 1 when a new budget was created, otherwise 0.
	 */
	private function seedOrganisationBudget(BudgetSeedRun $run): int {
		$organisation = (new FirstOrganisation(organisationMapper: $run->organisationMapper))->uuid();
		if ($organisation === '') {
			$run->output->info('No OpenRegister organisation exists yet — org-level budget seed deferred.');
			return 0;
		}

		return $this->seedIfMissing(
			run: $run,
			seed: new OrganisationSeed(organisation: $organisation, payload: self::ORGANISATION_BUDGET)
		);
	}//end seedOrganisationBudget()

	/**
	 * Seed one agent-scoped budget on its agent's organisation, or defer it when the
	 * agent or its organisation does not exist yet.
	 *
	 * @param BudgetSeedRun $run The seed run.
	 * @param array{agentName: string, period: string, tokenLimit: int, softThresholdPercent: int} $row The seed row.
	 *
	 * @return int 1 when a new budget was created, otherwise 0.
	 */
	private function seedAgentBudget(BudgetSeedRun $run, array $row): int {
		$agent = $this->findAgentByName(objectService: $run->objectService, name: $row['agentName']);
		if ($agent === null) {
			$run->output->info(sprintf('No agent named "%s" exists yet — its budget seed is deferred.', $row['agentName']));
			return 0;
		}

		$agentOrganisation = (string)($agent->getOrganisation() ?? '');
		if ($agentOrganisation === '') {
			$run->output->info(sprintf('Agent "%s" has no organisation yet — its budget seed is deferred.', $row['agentName']));
			return 0;
		}

		return $this->seedIfMissing(
			run: $run,
			seed: new OrganisationSeed(organisation: $agentOrganisation, payload: $this->agentBudget(agent: $agent, row: $row))
		);
	}//end seedAgentBudget()

	/**
	 * The fields of one agent-scoped budget.
	 *
	 * @param ObjectEntity $agent The agent the budget is for.
	 * @param array{agentName: string, period: string, tokenLimit: int, softThresholdPercent: int} $row The seed row.
	 *
	 * @return array<string, mixed>
	 */
	private function agentBudget(ObjectEntity $agent, array $row): array {
		return [
			'scope' => 'agent',
			'agentId' => (string)$agent->getUuid(),
			'period' => $row['period'],
			'tokenLimit' => $row['tokenLimit'],
			'softThresholdPercent' => $row['softThresholdPercent'],
		];
	}//end agentBudget()

	/**
	 * The design.md seed rows for the two agent-scoped budgets.
	 *
	 * @return array<int, array{agentName: string, period: string, tokenLimit: int, softThresholdPercent: int}>
	 */
	private function agentSeedRows(): array {
		return [
			['agentName' => 'Daily Briefing', 'period' => 'daily', 'tokenLimit' => 50000, 'softThresholdPercent' => 80],
			['agentName' => 'Heavy Tool Runner', 'period' => 'daily', 'tokenLimit' => 200000, 'softThresholdPercent' => 90],
		];

	}//end agentSeedRows()

	/**
	 * Create the budget through BudgetService::create() when no matching one exists
	 * yet for this (scope, agentId, period, organisation) tuple.
	 *
	 * @param BudgetSeedRun $run The seed run.
	 * @param OrganisationSeed $seed The organisation to pin the budget to and its fields.
	 *
	 * @return int 1 when a new budget was created, 0 when skipped (already exists or failed).
	 */
	private function seedIfMissing(BudgetSeedRun $run, OrganisationSeed $seed): int {
		try {
			if ($this->alreadySeeded(objectService: $run->objectService, seed: $seed) === true) {
				return 0;
			}

			$run->budgetService->create(payload: $seed->payload, organisation: $seed->organisation);
			return 1;
		} catch (Throwable $e) {
			$run->output->warning('Could not seed a budget: ' . $e->getMessage());
			$this->logger->error('[hermiq] Budget seed failed: ' . $e->getMessage());
			return 0;
		}

	}//end seedIfMissing()

	/**
	 * Whether a budget matching this (organisation, scope, agentId, period) tuple
	 * already exists (system-wide read; a repair step runs with no user session).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param OrganisationSeed $seed The organisation to check and the candidate budget fields.
	 *
	 * @return bool
	 */
	private function alreadySeeded(ObjectService $objectService, OrganisationSeed $seed): bool {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::BUDGET_SCHEMA)
			->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);

		foreach ($objects as $object) {
			if ($this->isBudgetFor(object: $object, seed: $seed) === true) {
				return true;
			}
		}

		return false;
	}//end alreadySeeded()

	/**
	 * Whether a stored object is a budget on the seed's organisation with the same
	 * scope, agentId and period.
	 *
	 * @param mixed $object A stored object as findAll() answers it.
	 * @param OrganisationSeed $seed The candidate budget.
	 *
	 * @return bool
	 */
	private function isBudgetFor(mixed $object, OrganisationSeed $seed): bool {
		if (($object instanceof ObjectEntity) === false) {
			return false;
		}

		if ((string)($object->getOrganisation() ?? '') !== $seed->organisation) {
			return false;
		}

		return $this->sameBudgetKey(data: $object->getObject(), payload: $seed->payload);
	}//end isBudgetFor()

	/**
	 * Whether two budgets share scope, agentId and period.
	 *
	 * @param array<string, mixed> $data The stored budget fields.
	 * @param array<string, mixed> $payload The candidate budget fields.
	 *
	 * @return bool
	 */
	private function sameBudgetKey(array $data, array $payload): bool {
		return (string)($data['scope'] ?? '') === (string)($payload['scope'] ?? '')
			&& (string)($data['agentId'] ?? '') === (string)($payload['agentId'] ?? '')
			&& (string)($data['period'] ?? '') === (string)($payload['period'] ?? '');
	}//end sameBudgetKey()

	/**
	 * Find an agent object by its exact display name (system-wide read).
	 *
	 * @param ObjectService $objectService The OpenRegister object service.
	 * @param string $name The agent name to match.
	 *
	 * @return ObjectEntity|null The matching agent, or null when none exists.
	 */
	private function findAgentByName(ObjectService $objectService, string $name): ?ObjectEntity {
		$objects = $objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::AGENT_SCHEMA)
			->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($object->getObject()['name'] ?? '') === $name) {
				return $object;
			}
		}

		return null;
	}//end findAgentByName()
}//end class
