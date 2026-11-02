<?php

/**
 * Golden test for AnalyticsService::computeAnalytics() and listRuns().
 *
 * Runs the run analytics and the run list over generated worlds of visible
 * agents, run audit entries (visible, foreign, unnamed, padded and missing agent
 * ids, dry runs, odd statuses, durations, usage and dates) and ratings, and
 * compares the results and every read they made with a recorded fixture. Set GOLDEN_RECORD=1 to (re)write
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
 * @spec openspec/changes/run-analytics/tasks.md#task-1-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use OCA\Hermiq\Service\AnalyticsService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;

/**
 * Golden comparison of the run analytics.
 *
 * @covers \OCA\Hermiq\Service\AnalyticsService
 * @uses   \OCA\Hermiq\Service\AgentFeedbackStats
 *
 * @spec openspec/changes/run-analytics/tasks.md#task-1-3
 */
class AnalyticsComputeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/analytics-compute.json';

	private const CASES = 120;

	private const AGENTS = ['a1', 'a2', 'a3', 'a4'];

	private const RUN_AGENTS = ['a1', 'a2', 'a3', 'a4', 'x9', ' a1 ', '', null, 7, 'a2'];

	private const STATUSES = ['ok', 'ok', 'error', 'timeout', '', null, 'OK', 3];

	private const DURATIONS = [100, 250, '400', 'fast', null, 1999, 0, 12.7];

	private const DRY_RUNS = [false, false, false, true, 'true', 1, null];

	private const SCOPES = [null, '', 'a1', 'a2', 'x9'];

	private const STATUS_FILTERS = [null, '', 'ok', 'error', 'unknown', 'none'];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every generated case gives the recorded result and reads.
	 *
	 * @return void
	 */
	public function testAnalyticsAndRunListMatchTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$agents = $this->agents();
			$feedback = $this->feedback();
			$runs = $this->runs();
			$scope = self::SCOPES[$this->random->getInt(0, count(self::SCOPES) - 1)];

			$reads = [];
			$schema = '';
			$objectService = $this->createMock(originalClassName: ObjectService::class);
			$objectService->method('setRegister')->willReturnCallback(
				function (string $register) use (&$reads, $objectService): ObjectService {
					$reads[] = ['setRegister', $register];
					return $objectService;
				}
			);
			$objectService->method('setSchema')->willReturnCallback(
				function (string $slug) use (&$reads, &$schema, $objectService): ObjectService {
					$reads[] = ['setSchema', $slug];
					$schema = $slug;
					return $objectService;
				}
			);
			$objectService->method('searchObjectsPaginated')->willReturnCallback(
				function (mixed ...$args) use (&$reads, &$schema, $agents, $feedback): array {
					$passed = $this->passed(class: ObjectService::class, method: 'searchObjectsPaginated', args: $args);
					$rows = ($schema === 'agent') ? $agents : $feedback;
					$offset = (int) ($passed['query']['_offset'] ?? 0);
					$result = ['results' => [], 'total' => count($rows['rows']) + $rows['extraTotal']];
					if ($offset === 0) {
						$result['results'] = $rows['rows'];
					}

					$reads[] = ['searchObjectsPaginated', $schema, $passed, count($result['results']), $result['total']];
					return $result;
				}
			);

			$mapper = $this->createMock(originalClassName: AuditTrailMapper::class);
			$mapper->method('findAll')->willReturnCallback(
				function (mixed ...$args) use (&$reads, $runs): array {
					$reads[] = ['findAll', $this->passed(class: AuditTrailMapper::class, method: 'findAll', args: $args), count($runs)];
					return $runs;
				}
			);

			$service = new AnalyticsService(objectService: $objectService, auditTrailMapper: $mapper);
			$result = $service->computeAnalytics(agentId: $scope);
			$analyticsReads = $reads;
			$reads = [];
			$list = ['status' => self::STATUS_FILTERS[$this->random->getInt(0, count(self::STATUS_FILTERS) - 1)], 'limit' => [1, 3, 50, 0][$this->random->getInt(0, 3)], 'offset' => [0, 0, 2, -1][$this->random->getInt(0, 3)]];
			$listed = $service->listRuns(agentId: $scope, status: $list['status'], limit: $list['limit'], offset: $list['offset']);

			$actual[] = ['agentId' => $scope, 'result' => $result, 'reads' => $analyticsReads, 'list' => $list, 'listed' => $listed, 'listReads' => $reads];
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

	}//end testAnalyticsAndRunListMatchTheRecordedFixture()

	/**
	 * The arguments a call actually passed, by parameter name, without the ones left at their default.
	 *
	 * @param string $class The mocked class.
	 * @param string $method The method name.
	 * @param array<int, mixed> $args The positional arguments the mock received.
	 *
	 * @return array<string, mixed>
	 */
	private function passed(string $class, string $method, array $args): array {
		$out = [];
		foreach ((new ReflectionMethod($class, $method))->getParameters() as $index => $param) {
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

	/**
	 * The visible agents page: named, unnamed and non-entity rows.
	 *
	 * @return array{rows: array<int, mixed>, extraTotal: int}
	 */
	private function agents(): array {
		$rows = [];
		foreach (self::AGENTS as $uuid) {
			if ($this->random->getInt(0, 3) === 0) {
				continue;
			}

			$entity = new ObjectEntity();
			$entity->setUuid($uuid);
			$names = ['Agent ' . $uuid, '', '  padded  ', null];
			$entity->setObject(['name' => $names[$this->random->getInt(0, 3)]]);
			$rows[] = $entity;
		}

		if ($this->random->getInt(0, 5) === 0) {
			$rows[] = ['not' => 'an entity'];
		}

		return ['rows' => $rows, 'extraTotal' => $this->random->getInt(0, 1) * 600];

	}//end agents()

	/**
	 * The ratings page: positive, negative and other types on any agent.
	 *
	 * @return array{rows: array<int, mixed>, extraTotal: int}
	 */
	private function feedback(): array {
		$rows = [];
		$types = ['positive', 'negative', 'neutral', '', null];
		for ($i = $this->random->getInt(0, 6); $i > 0; $i--) {
			$entity = new ObjectEntity();
			$entity->setUuid('f' . $i);
			$entity->setObject(
				[
					'agentId' => self::RUN_AGENTS[$this->random->getInt(0, 4)],
					'type' => $types[$this->random->getInt(0, 4)],
				]
			);
			$rows[] = $entity;
		}

		return ['rows' => $rows, 'extraTotal' => 0];

	}//end feedback()

	/**
	 * The run audit entries of both channels.
	 *
	 * @return array<int, AuditTrail>
	 */
	private function runs(): array {
		$runs = [];
		for ($i = $this->random->getInt(0, 14); $i > 0; $i--) {
			$entry = new AuditTrail();
			$entry->setAction(['run', 'agent-run'][$this->random->getInt(0, 1)]);
			$entry->setObjectUuid('o' . $i);
			$entry->setUuid('r' . $i);
			if ($this->random->getInt(0, 4) !== 0) {
				$entry->setCreated(new DateTime('@' . (1790000000 + $this->random->getInt(0, 3) * 3600)));
			}
			if ($this->random->getInt(0, 12) === 0) {
				$runs[] = $entry;
				continue;
			}

			$entry->setChanged($this->context());
			$runs[] = $entry;
		}

		return $runs;

	}//end runs()

	/**
	 * One run entry's context with keys left out at random.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		$context = [
			'agentId' => self::RUN_AGENTS[$this->random->getInt(0, count(self::RUN_AGENTS) - 1)],
			'status' => self::STATUSES[$this->random->getInt(0, count(self::STATUSES) - 1)],
			'durationMs' => self::DURATIONS[$this->random->getInt(0, count(self::DURATIONS) - 1)],
			'dryRun' => self::DRY_RUNS[$this->random->getInt(0, count(self::DRY_RUNS) - 1)],
			'usage' => $this->usage(),
			'summary' => 's' . $this->random->getInt(0, 9),
		];
		foreach (array_keys($context) as $key) {
			if ($this->random->getInt(0, 6) === 0) {
				unset($context[$key]);
			}
		}

		return $context;

	}//end context()

	/**
	 * A usage block in one of the shapes a run entry may carry.
	 *
	 * @return mixed
	 */
	private function usage(): mixed {
		$shapes = [
			['promptTokens' => $this->random->getInt(0, 900), 'completionTokens' => $this->random->getInt(0, 900)],
			['promptTokens' => $this->random->getInt(1, 50)],
			['completionTokens' => (string) $this->random->getInt(1, 50)],
			['promptTokens' => null, 'completionTokens' => null],
			['other' => 5],
			'not a block',
			null,
		];

		return $shapes[$this->random->getInt(0, count($shapes) - 1)];

	}//end usage()
}//end class
