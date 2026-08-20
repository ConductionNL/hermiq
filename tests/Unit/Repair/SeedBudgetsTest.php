<?php

/**
 * Unit tests for the SeedBudgets repair step (cost-guardrails).
 *
 * Covers the graceful-degradation contract: the organisation-scoped budget is
 * deferred (not fabricated) when no OpenRegister organisation exists yet, each
 * agent-scoped budget is deferred when its named agent does not exist, and a
 * re-run is idempotent (an already-seeded budget is never duplicated).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedBudgets;
use OCA\Hermiq\Service\BudgetService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the cost-guardrails SeedBudgets repair step.
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
class SeedBudgetsTest extends TestCase {

	/**
	 * A stateful ObjectService test double keyed by schema (mirrors BudgetServiceTest).
	 *
	 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $bySchema): ObjectService {
		return new class($bySchema) extends ObjectService {
			private ?string $schema = null;

			/**
			 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
			 */
			public function __construct(
				private array $bySchema,
			) {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->bySchema[$this->schema] ?? []);
			}
		};

	}//end objectService()

	/**
	 * An agent ObjectEntity with a name and organisation.
	 *
	 * @param string $uuid The agent uuid.
	 * @param string $name The agent display name.
	 * @param string $organisation The agent's organisation.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $uuid, string $name, string $organisation): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setOrganisation($organisation);
		$e->setObject(['name' => $name]);
		return $e;
	}//end agent()

	/**
	 * A container resolving ObjectService/OrganisationMapper/BudgetService to the
	 * given fixtures.
	 *
	 * @param ObjectService $objectService The object service double.
	 * @param array<int, string> $orgUuids Organisation UUIDs findAll() returns.
	 * @param BudgetService|null $budgetService Optional custom BudgetService.
	 *
	 * @return ContainerInterface
	 */
	private function container(ObjectService $objectService, array $orgUuids, ?BudgetService $budgetService = null): ContainerInterface {
		$orgMapper = $this->createMock(OrganisationMapper::class);
		$orgs = array_map(
			function (string $uuid) {
				// Real entity, not a mock: the real Organisation resolves
				// getUuid() via Entity magic, unmockable under a server tree.
				$org = new Organisation();
				$org->setUuid($uuid);
				return $org;
			},
			$orgUuids
		);
		$orgMapper->method('findAll')->willReturn($orgs);

		$budgetService ??= $this->createMock(BudgetService::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($objectService, $orgMapper, $budgetService) {
				return match ($class) {
					ObjectService::class => $objectService,
					OrganisationMapper::class => $orgMapper,
					BudgetService::class => $budgetService,
					default => throw new \RuntimeException("Unexpected service: {$class}"),
				};
			}
		);

		return $container;
	}//end container()

	/**
	 * With an organisation and both named agents present, and no pre-existing
	 * budgets, all three seed rows are created via BudgetService::create().
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function testSeedsAllThreeRowsWhenPrerequisitesExist(): void {
		$objectService = $this->objectService(
			[
				'agentbudget' => [],
				'agent' => [
					$this->agent('agent-briefing', 'Daily Briefing', 'org-a'),
					$this->agent('agent-heavy', 'Heavy Tool Runner', 'org-a'),
				],
			]
		);

		$budgetService = $this->createMock(BudgetService::class);
		$created = [];
		$budgetService->method('create')->willReturnCallback(
			function (array $payload, string $organisation) use (&$created): array {
				$created[] = ['payload' => $payload, 'organisation' => $organisation];
				return array_merge(['id' => 'new'], $payload);
			}
		);

		$step = new SeedBudgets(
			container: $this->container(objectService: $objectService, orgUuids: ['org-a'], budgetService: $budgetService),
			logger: $this->createMock(LoggerInterface::class),
		);

		$step->run(output: $this->createMock(IOutput::class));

		$this->assertCount(3, $created, 'Org budget + 2 agent budgets must all be created.');
		$scopes = array_map(static fn (array $c) => $c['payload']['scope'], $created);
		$this->assertSame(['organisation', 'agent', 'agent'], $scopes);

	}//end testSeedsAllThreeRowsWhenPrerequisitesExist()

	/**
	 * With no OpenRegister organisation yet, the org-level budget is deferred (never
	 * fabricated into a bogus tenant) — no BudgetService::create() call for it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function testDefersOrgBudgetWhenNoOrganisationExists(): void {
		$objectService = $this->objectService(['agentbudget' => [], 'agent' => []]);
		$budgetService = $this->createMock(BudgetService::class);
		$budgetService->expects($this->never())->method('create');

		$step = new SeedBudgets(
			container: $this->container(objectService: $objectService, orgUuids: [], budgetService: $budgetService),
			logger: $this->createMock(LoggerInterface::class),
		);

		$step->run(output: $this->createMock(IOutput::class));

	}//end testDefersOrgBudgetWhenNoOrganisationExists()

	/**
	 * When a named seed agent does not exist, its budget row is deferred (skipped,
	 * logged) rather than fabricating an agentId.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function testDefersAgentBudgetWhenAgentMissing(): void {
		$objectService = $this->objectService(['agentbudget' => [], 'agent' => []]);

		$budgetService = $this->createMock(BudgetService::class);
		$created = [];
		$budgetService->method('create')->willReturnCallback(
			function (array $payload, string $organisation) use (&$created): array {
				$created[] = $payload;
				return array_merge(['id' => 'new'], $payload);
			}
		);

		$step = new SeedBudgets(
			container: $this->container(objectService: $objectService, orgUuids: ['org-a'], budgetService: $budgetService),
			logger: $this->createMock(LoggerInterface::class),
		);

		$step->run(output: $this->createMock(IOutput::class));

		// Only the organisation-scoped budget is created; neither agent exists.
		$this->assertCount(1, $created);
		$this->assertSame('organisation', $created[0]['scope']);

	}//end testDefersAgentBudgetWhenAgentMissing()

	/**
	 * A re-run (upgrade) does not duplicate an already-seeded budget — matched by
	 * (organisation, scope, agentId, period).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function testReRunIsIdempotent(): void {
		$existingOrgBudget = new ObjectEntity();
		$existingOrgBudget->setUuid('existing-1');
		$existingOrgBudget->setOrganisation('org-a');
		$existingOrgBudget->setObject(['scope' => 'organisation', 'agentId' => '', 'period' => 'monthly']);

		$objectService = $this->objectService(['agentbudget' => [$existingOrgBudget], 'agent' => []]);

		$budgetService = $this->createMock(BudgetService::class);
		$budgetService->expects($this->never())->method('create');

		$step = new SeedBudgets(
			container: $this->container(objectService: $objectService, orgUuids: ['org-a'], budgetService: $budgetService),
			logger: $this->createMock(LoggerInterface::class),
		);

		$step->run(output: $this->createMock(IOutput::class));

	}//end testReRunIsIdempotent()
}//end class
