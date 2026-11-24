<?php

/**
 * Unit tests for AnalyticsService (run-analytics).
 *
 * Covers the aggregation (success rate, status breakdown, latency, per-agent) over a fixed
 * set of run audit entries, and the tenant boundary: run entries belonging to a schedule
 * outside the caller's set are excluded.
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
 * @spec openspec/changes/run-analytics/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AnalyticsService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the run-analytics AnalyticsService.
 *
 * @spec openspec/changes/run-analytics/tasks.md#task-4-1
 */
class AnalyticsServiceTest extends TestCase {

	/**
	 * A schedule ObjectEntity with a uuid + agentId.
	 *
	 * @param string $uuid The schedule uuid.
	 * @param string $agentId The bound agent uuid.
	 *
	 * @return ObjectEntity
	 */
	private function schedule(string $uuid, string $agentId): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setObject(['agentId' => $agentId]);
		return $e;
	}//end schedule()

	/**
	 * A run AuditTrail entry.
	 *
	 * @param string $objectUuid The owning schedule uuid.
	 * @param string $status The run status.
	 * @param int $durationMs The run duration in ms.
	 * @param string $agentId The agent uuid.
	 *
	 * @return AuditTrail
	 */
	private function runEntry(string $objectUuid, string $status, int $durationMs, string $agentId): AuditTrail {
		$a = new AuditTrail();
		$a->setAction('run');
		$a->setObjectUuid($objectUuid);
		$a->setChanged(['status' => $status, 'durationMs' => $durationMs, 'agentId' => $agentId]);
		return $a;
	}//end runEntry()

	/**
	 * Build the service with fixed schedules + run entries.
	 *
	 * @param array<int, ObjectEntity> $schedules The caller's schedules.
	 * @param array<int, AuditTrail> $runs All run audit entries.
	 *
	 * @return AnalyticsService
	 */
	private function service(array $schedules, array $runs): AnalyticsService {
		// The tenant boundary is the set of AGENTS the caller can see, read
		// through searchObjectsPaginated() and paged to exhaustion — not the
		// schedule set this helper used to hand to findAll(). Mocking the old
		// call left $visibleAgents empty, so every run was scoped out and every
		// count came back 0 while the service was working correctly.
		//
		// The agents are derived from the fixtures' own agentIds so each test
		// still declares its world in one place.
		$agents = [];
		foreach ($schedules as $schedule) {
			$agentId = (string) ($schedule->getObject()['agentId'] ?? '');
			if ($agentId === '' || isset($agents[$agentId]) === true) {
				continue;
			}

			$agent = new ObjectEntity();
			$agent->setUuid($agentId);
			$agent->setObject(['name' => $agentId]);
			$agents[$agentId] = $agent;
		}

		$page = array_values($agents);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		// One full page, then the loop stops because offset has reached total.
		$objectService->method('searchObjectsPaginated')
			->willReturn(['results' => $page, 'total' => count($page)]);

		return new AnalyticsService(
			objectService: $objectService,
			auditTrailMapper: $this->boundedMapper(runs: $runs, calls: $this->mapperCalls)
		);
	}//end service()

	/**
	 * The findAll() arguments the service under test passed, one entry per call.
	 *
	 * @var \ArrayObject<int, array{filters: array|null, search: string|null, limit: int|null}>
	 */
	private \ArrayObject $mapperCalls;

	/**
	 * Reset the recorded mapper calls before every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapperCalls = new \ArrayObject();
	}//end setUp()

	/**
	 * An audit mapper that behaves like the real one for the query shape the service
	 * must use, and fails the test on any other.
	 *
	 * Every call must carry the run actions AND a non-empty search term (the bound); the
	 * mapper then returns only the fixture entries whose context mentions that term, the
	 * way OpenRegister's LIKE on `changed` does. A blanket `willReturn($runs)` here would
	 * hand every row to an unbounded call and could never fail (phantom green).
	 *
	 * @param array<int, AuditTrail>  $runs  Every run entry on the "instance".
	 * @param \ArrayObject<int, mixed> $calls Receives the arguments of each call.
	 *
	 * @return AuditTrailMapper
	 *
	 * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#2-tests-make-the-phantom-green-mock-assert-the-query-shape
	 */
	private function boundedMapper(array $runs, \ArrayObject $calls): AuditTrailMapper {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $sort = null, ?string $search = null) use ($runs, $calls): array {
				$calls->append(['filters' => $filters, 'search' => $search, 'limit' => $limit]);
				$this->assertSame(expected: 'run,agent-run,manual-run', actual: ($filters['action'] ?? null), message: 'Only run entries may be read.');
				$this->assertNotSame(expected: '', actual: trim((string) $search), message: 'An audit read without a tenant bound reads every organisation.');

				return array_values(
					array_filter(
						$runs,
						static fn (AuditTrail $run): bool => str_contains((string) json_encode($run->getChanged()), (string) $search)
					)
				);
			}
		);

		return $mapper;
	}//end boundedMapper()

	/**
	 * The audit read is bounded by the caller's own agents, and follows them when they change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#2-tests-make-the-phantom-green-mock-assert-the-query-shape
	 */
	public function testTheAuditReadIsBoundedByTheCallersAgents(): void {
		$runs = [
			$this->runEntry(objectUuid: 's1', status: 'ok', durationMs: 100, agentId: 'agentA'),
			$this->runEntry(objectUuid: 's2', status: 'ok', durationMs: 100, agentId: 'agentB'),
			$this->runEntry(objectUuid: 's3-foreign', status: 'ok', durationMs: 100, agentId: 'agentX'),
		];

		$this->service(schedules: [$this->schedule(uuid: 's1', agentId: 'agentA')], runs: $runs)->computeAnalytics();
		$first = array_column($this->mapperCalls->getArrayCopy(), 'search');
		$this->assertSame(expected: ['agentA'], actual: $first);

		$this->mapperCalls->exchangeArray([]);
		$both = [$this->schedule(uuid: 's1', agentId: 'agentA'), $this->schedule(uuid: 's2', agentId: 'agentB')];
		$this->service(schedules: $both, runs: $runs)->computeAnalytics();
		$second = array_column($this->mapperCalls->getArrayCopy(), 'search');
		sort(array: $second);
		$this->assertSame(expected: ['agentA', 'agentB'], actual: $second, message: 'The bound must follow the caller\'s own agents.');
		$this->assertNotContains(needle: 'agentX', haystack: $second);
		$this->assertSame(
			expected: [null, null],
			actual: array_column($this->mapperCalls->getArrayCopy(), 'limit'),
			message: 'A limit would turn every metric into a floor.'
		);
	}//end testTheAuditReadIsBoundedByTheCallersAgents()

	/**
	 * A caller without a visible agent issues no audit read at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#2-tests-make-the-phantom-green-mock-assert-the-query-shape
	 */
	public function testNoVisibleAgentIssuesNoAuditRead(): void {
		$foreign = $this->runEntry(objectUuid: 's3-foreign', status: 'ok', durationMs: 100, agentId: 'agentX');
		$this->service(schedules: [], runs: [$foreign])->computeAnalytics();
		$this->assertCount(expectedCount: 0, haystack: $this->mapperCalls);
	}//end testNoVisibleAgentIssuesNoAuditRead()

	/**
	 * A run whose context names two visible agents is counted once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#1-backend-bound-the-audit-trail-read
	 */
	public function testARunMentioningTwoVisibleAgentsIsCountedOnce(): void {
		$run = $this->runEntry(objectUuid: 's1', status: 'ok', durationMs: 100, agentId: 'agentA');
		$run->setUuid(uuid: 'run-1');
		$run->setChanged(changed: ['status' => 'ok', 'durationMs' => 100, 'agentId' => 'agentA', 'delegatedTo' => 'agentB']);

		$both = [$this->schedule(uuid: 's1', agentId: 'agentA'), $this->schedule(uuid: 's2', agentId: 'agentB')];
		$m    = $this->service(schedules: $both, runs: [$run])->computeAnalytics();

		$this->assertSame(expected: 1, actual: $m['totalRuns']);
	}//end testARunMentioningTwoVisibleAgentsIsCountedOnce()

	/**
	 * Aggregation computes success rate, status breakdown, latency and per-agent counts,
	 * and excludes runs outside the caller's schedule set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-analytics/tasks.md#task-1-3
	 */
	public function testAggregatesAndScopesToCallersSchedules(): void {
		$schedules = [
			$this->schedule('s1', 'agentA'),
			$this->schedule('s2', 'agentB'),
		];
		$runs = [
			$this->runEntry('s1', 'ok', 100, 'agentA'),
			$this->runEntry('s1', 'ok', 300, 'agentA'),
			$this->runEntry('s1', 'error', 200, 'agentA'),
			$this->runEntry('s2', 'ok', 400, 'agentB'),
			// Belongs to a schedule NOT in the caller's set — MUST be excluded.
			$this->runEntry('s3-foreign', 'ok', 999, 'agentX'),
		];

		$m = $this->service($schedules, $runs)->computeAnalytics();

		$this->assertSame(4, $m['totalRuns']);
		$this->assertSame(3, $m['successRuns']);
		$this->assertSame(75.0, $m['successRate']);
		$this->assertSame(['ok' => 3, 'error' => 1], $m['statusBreakdown']);
		// Latency avg over 100,300,200,400 = 250.
		$this->assertSame(250, $m['latency']['avgMs']);
		$this->assertSame(100, $m['latency']['minMs']);
		$this->assertSame(400, $m['latency']['maxMs']);
		// The seconds figure the Dashboard's declarative latency tile renders.
		$this->assertSame(0.3, $m['latency']['avgSeconds']);

		$perAgent = [];
		foreach ($m['perAgent'] as $row) {
			$perAgent[$row['agentId']] = $row;
		}

		$this->assertSame(3, $perAgent['agentA']['runs']);
		$this->assertSame(2, $perAgent['agentA']['success']);
		$this->assertSame(1, $perAgent['agentB']['runs']);
		$this->assertArrayNotHasKey('agentX', $perAgent);

		// No run entry carried usage in this fixture → tokens unavailable (not
		// fabricated). The total is NULL rather than 0 so a consumer that reads
		// it without checking `available` cannot print a confident "0 tokens".
		$this->assertFalse($m['tokens']['available']);
		$this->assertNull($m['tokens']['total']);

	}//end testAggregatesAndScopesToCallersSchedules()

	/**
	 * Scoping to one agent limits the schedule set (and thus the runs) to that agent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-analytics/tasks.md#task-1-1
	 */
	public function testAgentScopeFiltersSchedules(): void {
		$schedules = [
			$this->schedule('s1', 'agentA'),
			$this->schedule('s2', 'agentB'),
		];
		$runs = [
			$this->runEntry('s1', 'ok', 100, 'agentA'),
			$this->runEntry('s2', 'ok', 100, 'agentB'),
		];

		$m = $this->service($schedules, $runs)->computeAnalytics(agentId: 'agentA');

		$this->assertSame('agent', $m['scope']);
		$this->assertSame('agentA', $m['agentId']);
		// Only s1 (agentA) is in scope → only its one run counts.
		$this->assertSame(1, $m['totalRuns']);

	}//end testAgentScopeFiltersSchedules()

	/**
	 * No schedules → zeroed metrics, not an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-analytics/tasks.md#task-1-3
	 */
	public function testNoSchedulesYieldsZeroMetrics(): void {
		$m = $this->service([], [])->computeAnalytics();

		$this->assertSame(0, $m['totalRuns']);
		$this->assertSame(0.0, $m['successRate']);
		$this->assertNull($m['latency']['avgMs']);
		$this->assertNull($m['latency']['avgSeconds']);

	}//end testNoSchedulesYieldsZeroMetrics()

	/**
	 * A dry-run/replay preview entry (`dryRun: true`) is excluded entirely from
	 * the status/success-rate breakdown, latency, per-agent counts, and this
	 * aggregate's own token total — a preview must never inflate an agent's
	 * real metrics (run-replay-and-dry-run). BudgetService's spend total is a
	 * separate service/read path, unaffected by this exclusion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
	 */
	public function testDryRunEntriesAreExcludedFromTheBreakdown(): void {
		$schedules = [$this->schedule('s1', 'agentA')];

		$realRun = $this->runEntry('s1', 'ok', 100, 'agentA');

		$dryRun = new AuditTrail();
		$dryRun->setAction('run');
		$dryRun->setObjectUuid('s1');
		$dryRun->setChanged(
			[
				'status' => 'ok',
				'durationMs' => 999999,
				'agentId' => 'agentA',
				'dryRun' => true,
				'usage' => ['promptTokens' => 500, 'completionTokens' => 500],
			]
		);

		$m = $this->service($schedules, [$realRun, $dryRun])->computeAnalytics();

		$this->assertSame(1, $m['totalRuns'], 'The dry-run entry must not be counted.');
		$this->assertSame(1, $m['successRuns']);
		$this->assertSame(['ok' => 1], $m['statusBreakdown']);
		$this->assertSame(100, $m['latency']['maxMs'], "The dry-run's 999999ms duration must not skew latency.");
		$this->assertFalse($m['tokens']['available'], "The dry-run's token usage must not appear in this aggregate's own total.");

		$perAgent = [];
		foreach ($m['perAgent'] as $row) {
			$perAgent[$row['agentId']] = $row;
		}

		$this->assertSame(1, $perAgent['agentA']['runs']);

	}//end testDryRunEntriesAreExcludedFromTheBreakdown()

	/**
	 * listRuns() returns the caller's runs newest-first and keeps the SAME tenant
	 * boundary the metrics use.
	 *
	 * A list that disagrees with the KPIs above it about what the caller may see is
	 * worse than either being wrong alone, so the exclusions are asserted here too.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
	 */
	public function testListRunsIsNewestFirstAndTenantScoped(): void {
		$schedules = [
			$this->schedule('s1', 'agentA'),
			$this->schedule('s2', 'agentB'),
		];

		$older = $this->runEntry('s1', 'ok', 100, 'agentA');
		$older->setUuid('run-older');
		$older->setCreated(new \DateTime('2026-09-01 09:00:00'));

		$newer = $this->runEntry('s2', 'error', 200, 'agentB');
		$newer->setUuid('run-newer');
		$newer->setCreated(new \DateTime('2026-09-05 09:00:00'));

		// Another organisation's run. Its agent is not in the visible set, so it must
		// never appear.
		$foreign = $this->runEntry('s3-foreign', 'ok', 999, 'agentX');
		$foreign->setUuid('run-foreign');
		$foreign->setCreated(new \DateTime('2026-09-06 09:00:00'));

		$page = $this->service($schedules, [$older, $newer, $foreign])->listRuns();

		$this->assertSame(2, $page['total'], 'The foreign run must not be counted.');
		$this->assertCount(2, $page['results']);
		$this->assertSame(
			['run-newer', 'run-older'],
			array_column($page['results'], 'id'),
			'Runs must come back newest-first.'
		);
		$this->assertSame(
			'agentB',
			$page['results'][0]['agentId'],
			'The row must carry the agent that ran, which is the tenant key.'
		);
		// The sort key is internal bookkeeping and must not reach the caller.
		$this->assertArrayNotHasKey('createdSort', $page['results'][0]);

	}//end testListRunsIsNewestFirstAndTenantScoped()

	/**
	 * A flow-triggered run appears in the list and names its channel.
	 *
	 * 🔴 This is the case no other listing can produce. An `agent-run` entry hangs on
	 * the object that triggered the flow, not on a schedule, so
	 * `RunHistoryController::index()` — which reads `object_uuid = <schedule>` — never
	 * matches one. The dashboard KPIs counted these runs while every list denied they
	 * existed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
	 */
	public function testListRunsIncludesFlowTriggeredRunsAndNamesTheChannel(): void {
		$schedules = [$this->schedule('s1', 'agentA')];

		$scheduled = $this->runEntry('s1', 'ok', 100, 'agentA');
		$scheduled->setUuid('run-scheduled');
		$scheduled->setCreated(new \DateTime('2026-09-01 09:00:00'));

		// Hangs on a case object in another register entirely — exactly the entry a
		// schedule-scoped query cannot reach.
		$flowRun = $this->runEntry('case-in-another-register', 'ok', 150, 'agentA');
		$flowRun->setAction('agent-run');
		$flowRun->setUuid('run-from-flow');
		$flowRun->setCreated(new \DateTime('2026-09-02 09:00:00'));

		$page = $this->service($schedules, [$scheduled, $flowRun])->listRuns();

		$this->assertSame(2, $page['total']);

		$byId = array_column($page['results'], null, 'id');
		$this->assertArrayHasKey('run-from-flow', $byId, 'A flow-triggered run must be listed.');
		$this->assertSame('flow', $byId['run-from-flow']['trigger']);
		$this->assertSame('schedule', $byId['run-scheduled']['trigger']);

	}//end testListRunsIncludesFlowTriggeredRunsAndNamesTheChannel()

	/**
	 * A run by hand hangs on the agent and is listed with trigger `manual`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
	 */
	public function testListRunsNamesARunByHandManual(): void {
		$schedules = [$this->schedule('s1', 'agentA')];

		$manual = $this->runEntry('agentA', 'ok', 80, 'agentA');
		$manual->setAction('manual-run');
		$manual->setUuid('run-by-hand');
		$manual->setCreated(new \DateTime('2026-09-03 09:00:00'));

		$page = $this->service($schedules, [$manual])->listRuns();

		$this->assertSame(1, $page['total']);
		$this->assertSame(['run-by-hand', 'manual'], [$page['results'][0]['id'], $page['results'][0]['trigger']]);

	}//end testListRunsNamesARunByHandManual()

	/**
	 * A run row carries the provider disclosure its record holds, data-use term
	 * included, as it stood at run time; an older run without one reads empty.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/provider-data-use/spec.md#requirement-every-run-records-the-data-use-term-in-force-req-notrain-003
	 */
	public function testARunRowShowsTheDataUseTermInForce(): void {
		$schedules = [$this->schedule('s1', 'agentA')];

		$recorded = $this->runEntry('s1', 'ok', 100, 'agentA');
		$recorded->setUuid('run-with-term');
		$recorded->setCreated(new \DateTime('2026-08-01 09:00:00'));
		$recorded->setChanged(
			[
				'status' => 'ok',
				'agentId' => 'agentA',
				'providerDisclosure' => [
					'feature' => '',
					'provider' => 'anthropic',
					'model' => 'claude-sonnet-5',
					'residency' => 'eu',
					'location' => 'Frankfurt',
					'dataUse' => 'no-training',
					'termsReference' => 'Anthropic commercial terms, checked 2026-09-01',
				],
			]
		);
		$older = $this->runEntry('s1', 'ok', 100, 'agentA');
		$older->setUuid('run-before');
		$older->setCreated(new \DateTime('2026-07-01 09:00:00'));

		$byId = array_column($this->service($schedules, [$recorded, $older])->listRuns()['results'], null, 'id');

		$this->assertSame('no-training', $byId['run-with-term']['providerDisclosure']['dataUse']);
		$this->assertSame('Anthropic commercial terms, checked 2026-09-01', $byId['run-with-term']['providerDisclosure']['termsReference']);
		$this->assertSame([], $byId['run-before']['providerDisclosure']);

	}//end testARunRowShowsTheDataUseTermInForce()

	/**
	 * A run that fell back shows both attempts in order and every provider that received the prompt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
	 */
	public function testARunRowShowsItsProviderAttempts(): void {
		$schedules = [$this->schedule('s1', 'agentA')];
		$recorded = $this->runEntry('s1', 'ok', 100, 'agentA');
		$recorded->setUuid('run-fell-back');
		$recorded->setChanged(
			[
				'status' => 'ok',
				'agentId' => 'agentA',
				'providerDisclosure' => [
					'provider' => 'ollama',
					'model' => 'qwen2.5',
					'receivedBy' => [['provider' => 'anthropic', 'model' => 'claude-opus-4-8'], ['provider' => 'ollama', 'model' => 'qwen2.5'], 'junk'],
				],
				'providerAttempts' => [
					['provider' => 'anthropic', 'model' => 'claude-opus-4-8', 'outcome' => 'failed', 'reason' => 'rate_limit', 'durationMs' => 120, 'received' => true, 'extra' => ['x']],
					['provider' => 'ollama', 'model' => 'qwen2.5', 'outcome' => 'answered', 'reason' => '', 'durationMs' => 900, 'received' => true],
				],
			]
		);

		$row = $this->service($schedules, [$recorded])->listRuns()['results'][0];

		$this->assertSame(
			[
				['provider' => 'anthropic', 'model' => 'claude-opus-4-8', 'outcome' => 'failed', 'reason' => 'rate_limit', 'durationMs' => 120],
				['provider' => 'ollama', 'model' => 'qwen2.5', 'outcome' => 'answered', 'reason' => '', 'durationMs' => 900],
			],
			$row['providerAttempts']
		);
		$this->assertSame([['provider' => 'anthropic', 'model' => 'claude-opus-4-8'], ['provider' => 'ollama', 'model' => 'qwen2.5']], $row['receivedBy']);
		$this->assertArrayNotHasKey('receivedBy', $row['providerDisclosure']);
	}//end testARunRowShowsItsProviderAttempts()

	/**
	 * Tools-browser-and-computer-use REQ-COMPUTER-004: a run row lists the computer
	 * actions its steps recorded (machine, action, point, approval, screenshot hash
	 * and size), never anything else of the step; a run without one has no such list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-the-run-records-actions-and-never-stores-screenshots-req-computer-004
	 */
	public function testARunRowListsItsComputerActions(): void {
		$schedules = [$this->schedule('s1', 'agentA')];
		$used = $this->runEntry('s1', 'ok', 100, 'agentA');
		$used->setUuid('run-computer');
		$used->setCreated(new \DateTime('2026-10-20 10:00:00'));
		$used->setChanged(
			[
				'status' => 'ok',
				'agentId' => 'agentA',
				'steps' => [
					['seq' => 1, 'type' => 'tool', 'name' => 'hermiq.computerScreenshot', 'computer' => ['target' => 'Werkplek 03', 'action' => 'screenshot', 'screenshot' => ['sha256' => 'abc', 'bytes' => 2048, 'image' => 'UE5H']]],
					['seq' => 2, 'type' => 'llm', 'name' => 'answer'],
					['seq' => 3, 'type' => 'tool', 'name' => 'hermiq.computerClick', 'arguments' => ['secret' => 'x'], 'computer' => ['target' => 'Werkplek 03', 'action' => 'click', 'x' => 412, 'y' => 230, 'approvalId' => 'ap-1', 'raw' => 'dropped']],
				],
			]
		);
		$plain = $this->runEntry('s1', 'ok', 100, 'agentA');
		$plain->setUuid('run-plain');
		$plain->setCreated(new \DateTime('2026-10-19 10:00:00'));

		$byId = array_column($this->service($schedules, [$used, $plain])->listRuns()['results'], null, 'id');

		$this->assertSame(
			[
				['target' => 'Werkplek 03', 'action' => 'screenshot', 'screenshot' => ['sha256' => 'abc', 'bytes' => 2048]],
				['target' => 'Werkplek 03', 'action' => 'click', 'x' => 412, 'y' => 230, 'approvalId' => 'ap-1'],
			],
			$byId['run-computer']['computerActions']
		);
		$this->assertArrayNotHasKey('computerActions', $byId['run-plain']);

	}//end testARunRowListsItsComputerActions()

	/**
	 * A run row carries the run's token counts, cache counts included, and drops
	 * anything that is not a whole count; a run without usage gets an empty list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-3
	 */
	public function testARunRowCarriesTheRecordedTokenCounts(): void {
		$schedules = [$this->schedule('s1', 'agentA')];

		$cached = $this->runEntry('s1', 'ok', 100, 'agentA');
		$cached->setUuid('run-cached');
		$cached->setChanged(
			[
				'status' => 'ok',
				'agentId' => 'agentA',
				'usage' => [
					'promptTokens' => 2300,
					'completionTokens' => 120,
					'cacheReadTokens' => 1840,
					'cacheWriteTokens' => -1,
					'llmSeconds' => 1.5,
					'other' => 'x',
				],
			]
		);
		$plain = $this->runEntry('s1', 'ok', 100, 'agentA');
		$plain->setUuid('run-plain');

		$byId = array_column($this->service($schedules, [$cached, $plain])->listRuns()['results'], null, 'id');

		$this->assertSame(['promptTokens' => 2300, 'completionTokens' => 120, 'cacheReadTokens' => 1840], $byId['run-cached']['usage']);
		$this->assertSame([], $byId['run-plain']['usage']);

	}//end testARunRowCarriesTheRecordedTokenCounts()

	/**
	 * The status filter narrows the rows, and `total` counts the filtered set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
	 */
	public function testListRunsFiltersByStatus(): void {
		$schedules = [$this->schedule('s1', 'agentA')];

		$ok = $this->runEntry('s1', 'ok', 100, 'agentA');
		$ok->setUuid('run-ok');
		$ok->setCreated(new \DateTime('2026-09-01 09:00:00'));

		$failed = $this->runEntry('s1', 'error', 200, 'agentA');
		$failed->setUuid('run-error');
		$failed->setCreated(new \DateTime('2026-09-02 09:00:00'));

		$page = $this->service($schedules, [$ok, $failed])->listRuns(status: 'error');

		$this->assertSame(1, $page['total']);
		$this->assertSame(['run-error'], array_column($page['results'], 'id'));

	}//end testListRunsFiltersByStatus()

	/**
	 * Paging reports the UNPAGED total, so a pager can say "51 to 100 of 340".
	 *
	 * Counting the returned page instead is how a list quietly claims to be complete.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
	 */
	public function testListRunsReportsTheUnpagedTotal(): void {
		$schedules = [$this->schedule('s1', 'agentA')];

		$runs = [];
		for ($i = 0; $i < 5; $i++) {
			$entry = $this->runEntry('s1', 'ok', 100, 'agentA');
			$entry->setUuid('run-' . $i);
			$entry->setCreated(new \DateTime('2026-09-0' . ($i + 1) . ' 09:00:00'));
			$runs[] = $entry;
		}

		$page = $this->service($schedules, $runs)->listRuns(limit: 2, offset: 0);

		$this->assertCount(2, $page['results'], 'The page honours the limit.');
		$this->assertSame(5, $page['total'], 'The total counts every visible run, not the page.');

		$second = $this->service($schedules, $runs)->listRuns(limit: 2, offset: 2);
		$this->assertSame(5, $second['total']);
		$this->assertNotSame(
			array_column($page['results'], 'id'),
			array_column($second['results'], 'id'),
			'A later offset must return different rows.'
		);

	}//end testListRunsReportsTheUnpagedTotal()

	/**
	 * A caller who can see no agent gets an empty page, not every run on the instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
	 */
	public function testListRunsReturnsNothingWhenNoAgentIsVisible(): void {
		$run = $this->runEntry('s1', 'ok', 100, 'agentA');
		$run->setUuid('run-1');
		$run->setCreated(new \DateTime('2026-09-01 09:00:00'));

		$page = $this->service([], [$run])->listRuns();

		$this->assertSame(0, $page['total']);
		$this->assertSame([], $page['results']);

	}//end testListRunsReturnsNothingWhenNoAgentIsVisible()

	/**
	 * A dry-run or replay preview never appears in the list.
	 *
	 * The same rule `computeAnalytics()` applies, so the list and the KPIs above it
	 * count the same set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
	 */
	public function testListRunsExcludesDryRuns(): void {
		$schedules = [$this->schedule('s1', 'agentA')];

		$real = $this->runEntry('s1', 'ok', 100, 'agentA');
		$real->setUuid('run-real');
		$real->setCreated(new \DateTime('2026-09-01 09:00:00'));

		$dry = new AuditTrail();
		$dry->setAction('run');
		$dry->setObjectUuid('s1');
		$dry->setUuid('run-dry');
		$dry->setCreated(new \DateTime('2026-09-02 09:00:00'));
		$dry->setChanged(
			[
				'status' => 'ok',
				'durationMs' => 999999,
				'agentId' => 'agentA',
				'dryRun' => true,
			]
		);

		$page = $this->service($schedules, [$real, $dry])->listRuns();

		$this->assertSame(1, $page['total']);
		$this->assertSame(['run-real'], array_column($page['results'], 'id'));

	}//end testListRunsExcludesDryRuns()
}//end class
