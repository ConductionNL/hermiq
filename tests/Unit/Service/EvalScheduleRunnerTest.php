<?php

/**
 * EvalScheduleRunner: due selection, commit before run and the regression
 * notification (reuse-scheduled-bias-evals, task 3).
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Hermiq\Kernel\Contracts\EvalRunServiceInterface;
use OCA\Hermiq\Service\EvalRegressionNotifier;
use OCA\Hermiq\Service\EvalScheduleClock;
use OCA\Hermiq\Service\EvalScheduleRunner;
use OCA\Hermiq\Service\EvalSchedules;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\Agent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EvalScheduleRunner.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleRunnerTest extends TestCase {

	/**
	 * Every call in order: ['save', data] or ['run', threshold].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $calls = [];

	/**
	 * The notifications sent, as [owner, runId, parameters].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $sent = [];

	/**
	 * A runner over recording doubles.
	 *
	 * @param array<string, mixed>     $result    What the eval run returns.
	 * @param bool                     $hasTarget Whether the schedule's owner owns the dataset and the agent.
	 * @param bool                     $runFails  Whether the eval run throws.
	 * @param array<int, ObjectEntity> $enabled   What the store lists as enabled.
	 *
	 * @return EvalScheduleRunner
	 */
	private function runner(array $result = [], bool $hasTarget = true, bool $runFails = false, array $enabled = []): EvalScheduleRunner {
		$dataset = new ObjectEntity();
		$dataset->setUuid('ds-1');
		$dataset->setObject(['name' => 'Bias check']);
		$agent = new Agent();
		$agent->setName('Permit intake helper');

		$schedules = $this->createMock(originalClassName: EvalSchedules::class);
		$schedules->method('enabled')->willReturn($enabled);
		$schedules->method('targets')->willReturn(['dataset' => $dataset, 'agent' => $agent]);
		if ($hasTarget === false) {
			$schedules = $this->createMock(originalClassName: EvalSchedules::class);
			$schedules->method('targets')->willReturn(null);
		}

		$schedules->method('save')->willReturnCallback(
			function (ObjectEntity $schedule, array $data): void {
				$this->calls[] = ['save', $data];
			}
		);

		$runs = $this->createMock(originalClassName: EvalRunServiceInterface::class);
		$runs->method('run')->willReturnCallback(
			function (
				ObjectEntity $dataset,
				Agent $agent,
				?string $agentVersionId = null,
				?int $regressionThresholdOverride = null
			) use (
				$result,
				$runFails
			): array {
				$this->calls[] = ['run', $regressionThresholdOverride];
				if ($runFails === true) {
					throw new RuntimeException(message: 'engine down');
				}

				return $result + [
					'evalRunId'            => 'run-7',
					'status'               => 'completed',
					'passRate'             => 0.9,
					'regressionGateResult' => 'passed',
					'previousPassRate'     => 0.9,
				];
			}
		);

		$notifier = $this->createMock(originalClassName: EvalRegressionNotifier::class);
		$notifier->method('notify')->willReturnCallback(
			function (string $owner, string $runId, array $parameters): void {
				$this->sent[] = [$owner, $runId, $parameters];
			}
		);

		return new EvalScheduleRunner(schedules: $schedules, clock: new EvalScheduleClock(), evalRuns: $runs, notifier: $notifier);
	}//end runner()

	/**
	 * A schedule object.
	 *
	 * @param array<string, mixed> $data Payload overrides.
	 *
	 * @return ObjectEntity
	 */
	private static function schedule(array $data): ObjectEntity {
		$schedule = new ObjectEntity();
		$schedule->setUuid('sched-1');
		$schedule->setOwner('fenna');
		$schedule->setObject(
			$data + [
				'datasetId' => 'ds-1',
				'agentId'   => 'agent-1',
				'cronExpr'  => '0 6 1 * *',
				'enabled'   => true,
				'nextRun'   => '2026-12-01T06:00:00+00:00',
			]
		);
		return $schedule;
	}//end schedule()

	/**
	 * The tick moment: 1 December 2026, 07:00 UTC, an hour after the monthly run was due.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable(datetime: '2026-12-01T07:00:00+00:00');
	}//end now()

	/**
	 * A due schedule stores its next run BEFORE the eval runs, then the run id after.
	 *
	 * @return void
	 */
	public function testTheNextRunIsStoredBeforeTheRunStarts(): void {
		$result = $this->runner()->runIfDue(schedule: self::schedule(data: ['regressionThresholdPercent' => 10]), now: self::now());

		$this->assertSame(expected: 'run-7', actual: $result['evalRunId']);
		$this->assertSame(expected: ['save', 'run', 'save'], actual: array_column(array: $this->calls, column_key: 0));
		$this->assertSame(expected: '2027-01-01T06:00:00+00:00', actual: $this->calls[0][1]['nextRun']);
		$this->assertArrayNotHasKey(key: 'lastRunId', array: $this->calls[0][1]);
		$this->assertSame(expected: 10, actual: $this->calls[1][1]);
		$this->assertSame(expected: 'run-7', actual: $this->calls[2][1]['lastRunId']);
	}//end testTheNextRunIsStoredBeforeTheRunStarts()

	/**
	 * When the run throws, the next run has already been stored: it is not started twice.
	 *
	 * @return void
	 */
	public function testAFailingRunHasAlreadyAdvancedTheSchedule(): void {
		$failures = $this->runner(runFails: true, enabled: [self::schedule(data: [])])->runDue(now: self::now());

		$this->assertSame(expected: ['sched-1' => 'engine down'], actual: $failures);
		$this->assertSame(expected: ['save', 'run'], actual: array_column(array: $this->calls, column_key: 0));
		$this->assertSame(expected: '2027-01-01T06:00:00+00:00', actual: $this->calls[0][1]['nextRun']);
	}//end testAFailingRunHasAlreadyAdvancedTheSchedule()

	/**
	 * A schedule that is not due, or is disabled, does nothing at all.
	 *
	 * @return void
	 */
	public function testANotDueOrDisabledScheduleDoesNothing(): void {
		$runner = $this->runner();

		$this->assertNull(actual: $runner->runIfDue(schedule: self::schedule(data: ['nextRun' => '2027-01-01T06:00:00+00:00']), now: self::now()));
		$this->assertNull(actual: $runner->runIfDue(schedule: self::schedule(data: ['enabled' => false]), now: self::now()));
		$this->assertSame(expected: [], actual: $this->calls);
	}//end testANotDueOrDisabledScheduleDoesNothing()

	/**
	 * A schedule whose owner no longer owns what it runs is advanced but runs nothing.
	 *
	 * @return void
	 */
	public function testAScheduleWithoutAnOwnedTargetRunsNothing(): void {
		$this->assertNull(actual: $this->runner(hasTarget: false)->runIfDue(schedule: self::schedule(data: []), now: self::now()));

		$this->assertSame(expected: ['save'], actual: array_column(array: $this->calls, column_key: 0));
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAScheduleWithoutAnOwnedTargetRunsNothing()

	/**
	 * A cron expression that is not valid switches the schedule off without running it.
	 *
	 * @return void
	 */
	public function testAnInvalidCronSwitchesTheScheduleOff(): void {
		$this->assertNull(actual: $this->runner()->runIfDue(schedule: self::schedule(data: ['cronExpr' => 'monthly']), now: self::now()));

		$this->assertSame(expected: ['save'], actual: array_column(array: $this->calls, column_key: 0));
		$this->assertFalse(condition: $this->calls[0][1]['enabled']);
	}//end testAnInvalidCronSwitchesTheScheduleOff()

	/**
	 * A failed regression gate tells the owner, with both pass rates and the run.
	 *
	 * @return void
	 */
	public function testAFailedGateNotifiesTheOwner(): void {
		$this->runner(result: ['passRate' => 0.8, 'previousPassRate' => 0.95, 'regressionGateResult' => 'failed'])
			->runIfDue(schedule: self::schedule(data: []), now: self::now());

		$this->assertSame(
			expected: [
				[
					'fenna',
					'run-7',
					[
						'name'      => 'Permit intake helper',
						'dataset'   => 'Bias check',
						'datasetId' => 'ds-1',
						'previous'  => 0.95,
						'current'   => 0.8,
					],
				],
			],
			actual: $this->sent
		);
	}//end testAFailedGateNotifiesTheOwner()

	/**
	 * A passed or not applicable gate sends nothing.
	 *
	 * @return void
	 */
	public function testAPassedGateSendsNothing(): void {
		$this->runner()->runIfDue(schedule: self::schedule(data: []), now: self::now());
		$this->runner(result: ['regressionGateResult' => 'not_applicable', 'previousPassRate' => null])
			->runIfDue(schedule: self::schedule(data: []), now: self::now());

		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAPassedGateSendsNothing()
}//end class
