<?php

/**
 * Golden test for BudgetService::status().
 *
 * Runs the budget status over generated budgets (scopes, periods, token and
 * EUR limits in every shape, soft thresholds), scope scans that fail or match
 * by organisation or agent, run entries in and out of the period window, and
 * EUR rates, and compares the result and every collaborator call with a
 * recorded fixture. Run dates are generated relative to the current period, and
 * the period key is compared against the current date, so the fixture does not
 * age. Set GOLDEN_RECORD=1 to (re)write the fixture; a run in record mode
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
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\AnalyticsService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\DeliveryService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Golden comparison of the budget status.
 *
 * @covers \OCA\Hermiq\Service\BudgetService
 * @covers \OCA\Hermiq\Service\BudgetLimits
 *
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
 */
class BudgetStatusGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/Golden/budget-status.json';

	private const CASES = 200;

	private const PERIODS = ['daily', 'weekly', 'monthly', 'yearly', ''];

	private const TOKEN_LIMITS = [null, '', 0, 50, 100, 300, 500, '800', 2000, -5, 'abc'];

	private const EUR_LIMITS = [null, '', 0, 0.1, 0.5, 2, '1.5', -1, 10];

	private const SOFT = [null, 50, 80, 100, 0, '70'];

	private const RATES = ['', '0.002', '1', '1', 'abc', '0', '0.5', '3'];

	/**
	 * Offsets in seconds from the period start: before it, inside it, past it.
	 */
	private const OFFSETS = [-1, 0, 1, 3600, 'end-1', 'end', 'end+1'];

	/**
	 * Fixed worlds on the threshold edges: budget fields, run tokens, EUR rate.
	 */
	private const EDGES = [
		[['tokenLimit' => 100], 100, ''],
		[['tokenLimit' => 100], 99, ''],
		[['eurLimit' => 0.1], 100, '1'],
		[['eurLimit' => 0.1], 99, '1'],
		[['eurLimit' => 0.2, 'tokenLimit' => 1000], 300, '1'],
		[['tokenLimit' => 1000], 850, ''],
		[['tokenLimit' => 1000], 799, ''],
		[['tokenLimit' => 1000], 800, ''],
		[['eurLimit' => 1], 850, '1'],
		[['eurLimit' => 1], 800, '1'],
		[['tokenLimit' => 1000, 'softThresholdPercent' => 80], 800, ''],
		[['eurLimit' => 1, 'softThresholdPercent' => 80], 800, '1'],
	];

	/**
	 * The seeded generator of the current case.
	 *
	 * @var Randomizer
	 */
	private Randomizer $random;

	/**
	 * Every generated case gives the recorded status and calls.
	 *
	 * @return void
	 */
	public function testStatusMatchesTheRecordedFixture(): void {
		$actual = [];
		for ($case = 0; $case < self::CASES; $case++) {
			$this->random = new Randomizer(new Mt19937($case + 1));
			$payload = $this->payload();
			$window = $this->window(period: (string)($payload['period'] ?? 'monthly'));
			$world = [
				'payload' => $payload,
				'organisation' => $this->pick(pool: ['org1', 'org1', 'org2', null]),
				'schemas' => [
					'schedule' => $this->scopeObjects(prefix: 's'),
					'evalrun' => $this->scopeObjects(prefix: 'e'),
					'agentskilldraft' => $this->scopeObjects(prefix: 'd'),
				],
				'runs' => $this->runs(window: $window),
				'rate' => $this->pick(pool: self::RATES),
			];
			$actual[] = $this->runWorld(world: $world);
		}

		foreach (self::EDGES as $edge) {
			$actual[] = $this->runWorld(world: $this->edgeWorld(edge: $edge));
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

	}//end testStatusMatchesTheRecordedFixture()

	/**
	 * Run status() over one world and record the result, the calls and the log.
	 *
	 * @param array<string, mixed> $world The budget payload, organisation, scan answers, runs and rate.
	 *
	 * @return array<string, mixed>
	 */
	private function runWorld(array $world): array {
		$calls = [];
		$schemaAnswers = $world['schemas'];
		$schema = '';
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('setRegister')->willReturnCallback(
			function (string $register) use (&$calls, $objectService): ObjectService {
				$calls[] = ['setRegister', $register];
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnCallback(
			function (string $slug) use (&$calls, &$schema, $objectService): ObjectService {
				$calls[] = ['setSchema', $slug];
				$schema = $slug;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (mixed ...$args) use (&$calls, &$schema, $schemaAnswers): array {
				$calls[] = ['findAll', $schema, $this->passed(class: ObjectService::class, method: 'findAll', args: $args)];
				$answer = ($schemaAnswers[$schema] ?? []);
				if ($answer === 'throws') {
					throw new RuntimeException('read failed for ' . $schema);
				}

				return $answer;
			}
		);

		$runs = $world['runs'];
		$mapper = $this->createMock(originalClassName: AuditTrailMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (mixed ...$args) use (&$calls, $runs): array {
				$calls[] = ['auditFindAll', $this->passed(class: AuditTrailMapper::class, method: 'findAll', args: $args), count($runs)];
				return $runs;
			}
		);

		$rate = $world['rate'];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) use (&$calls, $rate): string {
				$calls[] = ['getValueString', $app, $key, $default];
				return $rate;
			}
		);

		$logger = $this->logger();
		$service = new BudgetService(
			objectService: $objectService,
			auditTrailMapper: $mapper,
			appConfig: $appConfig,
			organisationMapper: $this->createMock(originalClassName: OrganisationMapper::class),
			deliveryService: $this->createMock(originalClassName: DeliveryService::class),
			analyticsService: $this->createMock(originalClassName: AnalyticsService::class),
			logger: $logger
		);

		$budget = $this->budget(payload: $world['payload'], organisation: $world['organisation']);
		$period = (string)($world['payload']['period'] ?? 'monthly');
		$result = $service->status(budget: $budget);
		if ($result['periodKey'] === $this->window(period: $period)['key']) {
			$result['periodKey'] = '(current ' . $period . ' key)';
		}

		return ['budget' => $world['payload'], 'organisation' => $world['organisation'], 'result' => $result, 'calls' => $calls, 'log' => $logger->lines];

	}//end runWorld()

	/**
	 * A fixed world: one in-scope schedule and one run inside the current period.
	 *
	 * @param array{0: array<string, mixed>, 1: int, 2: string} $edge The budget payload, the run's tokens and the rate.
	 *
	 * @return array<string, mixed>
	 */
	private function edgeWorld(array $edge): array {
		$schedule = new ObjectEntity();
		$schedule->setUuid('s0');
		$schedule->setOrganisation('org1');
		$schedule->setObject(['agentId' => 'ag1']);

		$window = $this->window(period: 'monthly');
		$run = new AuditTrail();
		$run->setAction('run');
		$run->setObjectUuid('s0');
		$run->setCreated($this->at(window: $window, offset: 60));
		$run->setChanged(['usage' => ['promptTokens' => $edge[1], 'completionTokens' => 0]]);

		return [
			'payload' => ['scope' => 'organisation', 'period' => 'monthly'] + $edge[0],
			'organisation' => 'org1',
			'schemas' => ['schedule' => [$schedule]],
			'runs' => [$run],
			'rate' => $edge[2],
		];

	}//end edgeWorld()

	/**
	 * A generated budget payload with keys left out at random.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$payload = [
			'scope' => $this->pick(pool: ['organisation', 'organisation', 'agent', 'agent', '']),
			'period' => $this->pick(pool: self::PERIODS),
			'agentId' => $this->pick(pool: ['ag1', 'ag2', '']),
			'tokenLimit' => $this->pick(pool: self::TOKEN_LIMITS),
			'eurLimit' => $this->pick(pool: self::EUR_LIMITS),
			'softThresholdPercent' => $this->pick(pool: self::SOFT),
		];
		foreach (array_keys($payload) as $key) {
			if ($this->random->getInt(0, 6) === 0) {
				unset($payload[$key]);
			}
		}

		return $payload;

	}//end payload()

	/**
	 * A budget object over a payload.
	 *
	 * @param array<string, mixed> $payload The budget payload.
	 * @param string|null $organisation The budget's organisation.
	 *
	 * @return ObjectEntity
	 */
	private function budget(array $payload, ?string $organisation): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('b1');
		$entity->setOrganisation($organisation);
		$entity->setObject($payload);
		return $entity;

	}//end budget()

	/**
	 * One schema's scan answer: a failed read, or objects of two organisations and agents.
	 *
	 * @param string $prefix The uuid prefix of the schema's objects.
	 *
	 * @return string|array<int, mixed>
	 */
	private function scopeObjects(string $prefix): string|array {
		if ($this->random->getInt(0, 7) === 0) {
			return 'throws';
		}

		$out = [];
		for ($i = $this->random->getInt(0, 3); $i >= 0; $i--) {
			if ($this->random->getInt(0, 9) === 0) {
				$out[] = ['not' => 'an entity'];
				continue;
			}

			$entity = new ObjectEntity();
			$entity->setUuid($prefix . $i);
			$entity->setOrganisation($this->pick(pool: ['org1', 'org1', 'org2', null]));
			$entity->setObject(['agentId' => $this->pick(pool: ['ag1', 'ag1', 'ag2', ''])]);
			$out[] = $entity;
		}

		return $out;

	}//end scopeObjects()

	/**
	 * Run entries on in-scope and other objects, around the period window.
	 *
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable, key: string} $window The current window.
	 *
	 * @return array<int, AuditTrail>
	 */
	private function runs(array $window): array {
		$objects = ['s0', 's1', 's2', 'e0', 'e1', 'd0', 'd1', 'zz'];
		$runs = [];
		for ($i = $this->random->getInt(0, 12); $i > 0; $i--) {
			$entry = new AuditTrail();
			$entry->setAction('run');
			$entry->setObjectUuid($this->pick(pool: $objects));
			$offset = $this->pick(pool: self::OFFSETS);
			if ($offset !== null && $this->random->getInt(0, 9) !== 0) {
				$entry->setCreated($this->at(window: $window, offset: $offset));
			}

			$usages = [
				['promptTokens' => $this->random->getInt(0, 400), 'completionTokens' => $this->random->getInt(0, 400)],
				['promptTokens' => '120'],
				['completionTokens' => $this->random->getInt(0, 900)],
				'not a block',
				null,
			];
			$context = ['usage' => $this->pick(pool: $usages)];
			if ($this->random->getInt(0, 8) === 0) {
				$context = [];
			}

			$entry->setChanged($context);
			$runs[] = $entry;
		}//end for

		return $runs;

	}//end runs()

	/**
	 * A moment relative to the window.
	 *
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable, key: string} $window The window.
	 * @param int|string $offset Seconds from the start, or `end`/`end-1`/`end+1`.
	 *
	 * @return DateTime
	 */
	private function at(array $window, int|string $offset): DateTime {
		$moment = match ($offset) {
			'end' => $window['end'],
			'end-1' => $window['end']->modify('-1 second'),
			'end+1' => $window['end']->modify('+1 second'),
			default => $window['start']->modify(sprintf('%+d seconds', $offset)),
		};

		return DateTime::createFromImmutable($moment);

	}//end at()

	/**
	 * The current UTC window of a period kind.
	 *
	 * @param string $period The period kind (anything else is monthly).
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable, key: string}
	 */
	private function window(string $period): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($period === 'daily') {
			$start = $now->setTime(0, 0, 0);
			return ['start' => $start, 'end' => $start->modify('+1 day'), 'key' => $start->format('Y-m-d')];
		}

		if ($period === 'weekly') {
			$start = $now->setTime(0, 0, 0)->modify('monday this week');
			return ['start' => $start, 'end' => $start->modify('+7 days'), 'key' => $now->format('o-\WW')];
		}

		$start = $now->setTime(0, 0, 0)->modify('first day of this month');
		return ['start' => $start, 'end' => $start->modify('+1 month'), 'key' => $now->format('Y-m')];

	}//end window()

	/**
	 * A logger that keeps its lines.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * Recorded log lines.
			 *
			 * @var array<int, string>
			 */
			public array $lines = [];

			/**
			 * Record one line.
			 *
			 * @param mixed $level The level.
			 * @param string|Stringable $message The message.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ' ' . $message . ' ' . implode(',', array_keys($context));
			}//end log()
		};

	}//end logger()

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
}//end class
