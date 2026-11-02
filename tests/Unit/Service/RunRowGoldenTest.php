<?php

/**
 * Golden test for the run rows of AnalyticsService::listRuns() and compareRuns().
 *
 * Builds generated worlds of visible agents (named, unnamed, padded) and run audit
 * entries of both channels, with every context field a run row reads present,
 * missing or oddly typed (status, times, duration, summary, attempt, the provider
 * disclosure with extra and missing labels, steps, agent version, provider, model),
 * and compares the run list and a comparison of two of its runs with a recorded
 * fixture. Set GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode
 * compares nothing.
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
 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
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

/**
 * Golden comparison of the run rows.
 *
 * @covers \OCA\Hermiq\Service\AnalyticsService
 * @uses   \OCA\Hermiq\Service\AgentFeedbackStats
 * @uses   \OCA\Hermiq\Service\RunCompareService
 * @uses   \OCA\Hermiq\Service\RunComparator
 *
 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
 */
class RunRowGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/run-rows.json';

	private const CASES = 160;

	private const AGENTS = ['a1', 'a2', 'a3'];

	private const RUN_AGENTS = ['a1', 'a2', 'a3', ' a2 ', 'x9', ''];

	private const STATUSES = ['ok', 'error', '', null, 3, 'timeout'];

	private const VALUES = ['2026-10-01T08:00:00+02:00', '', null, 0, 42, 'x', ['nested' => 1]];

	private const DISCLOSURES = [
		null,
		'not a block',
		[],
		['provider' => 'anthropic', 'model' => 'claude', 'residency' => 'eu', 'location' => 'NL', 'dataUse' => 'none', 'termsReference' => 't-1'],
		['provider' => 'openai', 'secret' => 'k', 'dataUse' => 'training', 'extra' => ['x']],
		['model' => null, 'location' => ''],
	];

	private const STEPS = [
		[],
		[['seq' => 1, 'type' => 'tool', 'name' => 'lookup', 'outcome' => 'ok', 'durationMs' => 5]],
		['k' => ['seq' => 2, 'type' => 'tool', 'name' => 'write', 'outcome' => 'error']],
		'none',
	];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every generated case gives the recorded run list and comparison.
	 *
	 * @return void
	 */
	public function testRunRowsMatchTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$runs = $this->runs();
			$service = $this->service(runs: $runs);
			$listed = $service->listRuns(agentId: $this->pick(list: [null, '', 'a1', 'x9']), status: $this->pick(list: [null, '', 'ok', 'unknown']));

			$ids = array_map(static fn (AuditTrail $run): string => (string) $run->getUuid(), $runs);
			$ids[] = 'missing';
			$ids[] = '';
			$compared = $service->compareRuns(leftId: $this->pick(list: $ids), rightId: $this->pick(list: $ids));

			$actual[] = ['listed' => $listed, 'compared' => $compared];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
			$this->markTestSkipped(message: 'Fixture recorded.');
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertCount(expectedCount: count($expected), haystack: $actual);
		foreach ($expected as $index => $one) {
			$this->assertSame(
				expected: json_encode($one, JSON_THROW_ON_ERROR),
				actual: json_encode($actual[$index], JSON_THROW_ON_ERROR),
				message: 'case ' . $index
			);
		}

	}//end testRunRowsMatchTheRecordedFixture()

	/**
	 * One element of a list, drawn by the case's generator.
	 *
	 * @param array<int, mixed> $list The choices.
	 *
	 * @return mixed
	 */
	private function pick(array $list): mixed {
		return $list[$this->random->getInt(0, count($list) - 1)];

	}//end pick()

	/**
	 * The service over a visible agent page and the given run entries.
	 *
	 * @param array<int, AuditTrail> $runs Every run audit entry the mapper holds.
	 *
	 * @return AnalyticsService
	 */
	private function service(array $runs): AnalyticsService {
		$page = [];
		foreach (self::AGENTS as $uuid) {
			$agent = new ObjectEntity();
			$agent->setUuid($uuid);
			$agent->setObject(['name' => $this->pick(list: ['Agent ' . $uuid, '', '  padded  ', null])]);
			$page[] = $agent;
		}

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('searchObjectsPaginated')->willReturn(['results' => $page, 'total' => count($page)]);

		$mapper = $this->createMock(originalClassName: AuditTrailMapper::class);
		$mapper->method('findAll')->willReturn($runs);

		return new AnalyticsService(objectService: $objectService, auditTrailMapper: $mapper);

	}//end service()

	/**
	 * The run audit entries of both channels.
	 *
	 * @return array<int, AuditTrail>
	 */
	private function runs(): array {
		$runs = [];
		for ($i = $this->random->getInt(1, 12); $i > 0; $i--) {
			$entry = new AuditTrail();
			$entry->setAction($this->pick(list: ['run', 'agent-run']));
			$entry->setObjectUuid($this->pick(list: ['o' . $i, null]));
			$entry->setUuid('r' . $i);
			$entry->setUser($this->pick(list: ['alice', null, '']));
			if ($this->random->getInt(0, 4) !== 0) {
				$entry->setCreated(new DateTime('@' . (1790000000 + $this->random->getInt(0, 5) * 1800)));
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
			'agentId' => $this->pick(list: self::RUN_AGENTS),
			'status' => $this->pick(list: self::STATUSES),
			'startedAt' => $this->pick(list: self::VALUES),
			'endedAt' => $this->pick(list: self::VALUES),
			'durationMs' => $this->pick(list: self::VALUES),
			'summary' => $this->pick(list: ['Checked the cases', '', null, 7]),
			'attempt' => $this->pick(list: [1, 2, null, '3']),
			'providerDisclosure' => $this->pick(list: self::DISCLOSURES),
			'steps' => $this->pick(list: self::STEPS),
			'agentVersion' => $this->pick(list: ['v1', null]),
			'provider' => $this->pick(list: ['anthropic', null]),
			'model' => $this->pick(list: ['claude', null]),
			'dryRun' => $this->pick(list: [false, false, false, true]),
		];
		foreach (array_keys($context) as $key) {
			if ($this->random->getInt(0, 5) === 0) {
				unset($context[$key]);
			}
		}

		return $context;

	}//end context()
}//end class
