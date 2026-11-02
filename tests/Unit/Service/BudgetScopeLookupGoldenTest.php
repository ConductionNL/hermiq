<?php

/**
 * Golden test for BudgetService's tenant-scoped budget lookup (findBudgetTenantScoped()).
 *
 * Runs the lookup over generated pages of budget objects (organisation- and
 * agent-scoped, other and missing scopes, other organisations, missing and
 * padded agent ids, non-entity rows, several matches in one page) for
 * generated organisations and agent ids, and compares the budget it picks and
 * every read it made with a recorded fixture. Set GOLDEN_RECORD=1 to (re)write
 * the fixture; a run in record mode compares nothing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AnalyticsService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;

/**
 * Golden comparison of the tenant-scoped budget lookup.
 *
 * @covers \OCA\Hermiq\Service\BudgetService
 * @uses   \OCA\Hermiq\Service\BudgetScopes
 *
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-4-budgetcontroller-routes-crud-status-estimate
 */
class BudgetScopeLookupGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/budget-scope-lookup.json';

	private const CASES = 400;

	private const ORGANISATIONS = ['org-a', 'org-a', 'org-b', '', null];

	private const SCOPES = ['organisation', 'organisation', 'agent', 'agent', 'agent', 'schedule', '', null];

	private const AGENTS = ['ag-1', 'ag-1', 'ag-2', ' ag-1 ', '', null, 7];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every generated case picks the recorded budget after the recorded reads.
	 *
	 * @return void
	 */
	public function testLookupMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$page = $this->page();
			$reads = [];
			$service = $this->service(page: $page, reads: $reads);
			$organisation = $this->pick(pool: ['org-a', 'org-a', 'org-b', '']);
			$agentId = $this->pick(pool: ['ag-1', 'ag-1', 'ag-2', '', null]);

			$lookup = new ReflectionMethod(BudgetService::class, 'findBudgetTenantScoped');
			$found = $lookup->invoke($service, $organisation, $agentId);

			$actual[] = [
				'organisation' => $organisation,
				'agentId' => $agentId,
				'page' => array_map(fn (mixed $row): mixed => $this->describe(row: $row), $page),
				'found' => $found?->getUuid(),
				'reads' => $reads,
			];
		}

		foreach ($this->fixedPages() as $index => $page) {
			$reads = [];
			$service = $this->service(page: $page, reads: $reads);
			$lookup = new ReflectionMethod(BudgetService::class, 'findBudgetTenantScoped');
			$found = $lookup->invoke($service, 'org-a', 'ag-1');
			$actual[] = ['fixed' => $index, 'found' => $found?->getUuid(), 'reads' => $reads];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertCount(expectedCount: count($expected), haystack: $actual);
		foreach ($expected as $index => $one) {
			$this->assertSame(
				expected: json_encode($one, JSON_THROW_ON_ERROR),
				actual: json_encode($actual[$index], JSON_THROW_ON_ERROR),
				message: 'case ' . $index
			);
		}

	}//end testLookupMatchesTheRecordedFixture()

	/**
	 * The service over one page of budget objects, recording each read.
	 *
	 * @param array<int, mixed> $page The rows findAll() answers.
	 * @param array<int, mixed> $reads The read log, appended to.
	 *
	 * @return BudgetService
	 */
	private function service(array $page, array &$reads): BudgetService {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('setRegister')->willReturnCallback(
			function (string $register) use (&$reads, $objectService): ObjectService {
				$reads[] = ['setRegister', $register];
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$reads, $objectService): ObjectService {
				$reads[] = ['setSchema', $schema];
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (mixed ...$args) use (&$reads, $page): array {
				$reads[] = ['findAll', $this->passed(method: 'findAll', args: $args)];
				return $page;
			}
		);

		return new BudgetService(
			objectService: $objectService,
			auditTrailMapper: $this->createMock(originalClassName: AuditTrailMapper::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			organisationMapper: $this->createMock(originalClassName: OrganisationMapper::class),
			deliveryService: $this->createMock(originalClassName: DeliveryService::class),
			analyticsService: $this->createMock(originalClassName: AnalyticsService::class),
			logger: new NullLogger()
		);

	}//end service()

	/**
	 * One page of budget rows.
	 *
	 * @return array<int, mixed>
	 */
	private function page(): array {
		$rows = [];
		for ($i = $this->random->getInt(0, 7); $i > 0; $i--) {
			if ($this->random->getInt(0, 9) === 0) {
				$rows[] = ['not' => 'an entity'];
				continue;
			}

			$payload = [
				'scope' => $this->pick(pool: self::SCOPES),
				'agentId' => $this->pick(pool: self::AGENTS),
				'enabled' => $this->pick(pool: [true, false]),
			];
			foreach (array_keys($payload) as $key) {
				if ($this->random->getInt(0, 6) === 0) {
					unset($payload[$key]);
				}
			}

			$entity = new ObjectEntity();
			$entity->setUuid('b' . $i);
			$entity->setOrganisation($this->pick(pool: self::ORGANISATIONS));
			$entity->setObject($payload);
			$rows[] = $entity;
		}

		return $rows;

	}//end page()

	/**
	 * Pages with two matches of the same kind, so the pick between them shows.
	 *
	 * @return array<int, array<int, ObjectEntity>>
	 */
	private function fixedPages(): array {
		$budget = static function (string $uuid, string $scope): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setUuid($uuid);
			$entity->setOrganisation('org-a');
			$entity->setObject(['scope' => $scope, 'agentId' => 'ag-1']);
			return $entity;
		};

		return [
			[$budget('first-agent', 'agent'), $budget('second-agent', 'agent')],
			[$budget('first-org', 'organisation'), $budget('second-org', 'organisation')],
			[$budget('org', 'organisation'), $budget('agent', 'agent'), $budget('last-org', 'organisation')],
		];

	}//end fixedPages()

	/**
	 * A page row as recorded: an entity as uuid, organisation and payload.
	 *
	 * @param mixed $row The row.
	 *
	 * @return mixed
	 */
	private function describe(mixed $row): mixed {
		if (($row instanceof ObjectEntity) === false) {
			return $row;
		}

		return [$row->getUuid(), $row->getOrganisation(), $row->getObject()];

	}//end describe()

	/**
	 * One value from a pool.
	 *
	 * @param array<int, mixed> $pool The pool.
	 *
	 * @return mixed
	 */
	private function pick(array $pool): mixed {
		return $pool[$this->random->getInt(0, count($pool) - 1)];

	}//end pick()

	/**
	 * The arguments a findAll() call passed, by parameter name, without the ones left at their default.
	 *
	 * @param string $method The method name.
	 * @param array<int, mixed> $args The positional arguments the mock received.
	 *
	 * @return array<string, mixed>
	 */
	private function passed(string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod(ObjectService::class, $method))->getParameters() as $index => $param) {
			if (array_key_exists($index, $args) === false) {
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$index]) {
				continue;
			}

			$out[$param->getName()] = $args[$index];
		}

		return $out;

	}//end passed()
}//end class
