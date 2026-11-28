<?php

/**
 * Runs due eval schedules.
 *
 * Each enabled `EvalSchedule` whose next run has come is advanced to its
 * following run and saved BEFORE the eval starts, as the agent schedule
 * dispatcher does, so a run that crashes or outlasts the tick is never started
 * twice. The run goes through `EvalRunService::run()`, so the kill switch and
 * budget gates apply exactly as to a run started by hand, and every run is kept
 * as an ordinary `EvalRun`. When the run's regression gate fails, the owner is
 * notified.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Hermiq\Kernel\Contracts\EvalRunServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\Agent;
use Throwable;

/**
 * Due eval schedules, run one by one.
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleRunner {

	/**
	 * Constructor.
	 *
	 * @param EvalSchedules           $schedules Reads, saves and resolves schedules.
	 * @param EvalScheduleClock       $clock     Due selection and the next run.
	 * @param EvalRunServiceInterface $evalRuns  Runs the dataset, gates included.
	 * @param EvalRegressionNotifier  $notifier  Tells the owner about a failed gate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function __construct(
		private readonly EvalSchedules $schedules,
		private readonly EvalScheduleClock $clock,
		private readonly EvalRunServiceInterface $evalRuns,
		private readonly EvalRegressionNotifier $notifier,
	) {
	}//end __construct()

	/**
	 * Run every due schedule. One failing schedule never stops the others.
	 *
	 * @param DateTimeImmutable $now The current moment.
	 *
	 * @return array<string, string> Why each failed schedule failed, by schedule uuid.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function runDue(DateTimeImmutable $now): array {
		$failures = [];
		foreach ($this->schedules->enabled() as $schedule) {
			try {
				$this->runIfDue(schedule: $schedule, now: $now);
			} catch (Throwable $e) {
				$failures[(string)$schedule->getUuid()] = $e->getMessage();
			}
		}

		return $failures;
	}//end runDue()

	/**
	 * Run one schedule when it is due: advance it, run the dataset, record the run.
	 *
	 * @param ObjectEntity      $schedule The `EvalSchedule` object.
	 * @param DateTimeImmutable $now      The current moment.
	 *
	 * @return array<string, mixed>|null The run result, or null when nothing ran.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function runIfDue(ObjectEntity $schedule, DateTimeImmutable $now): ?array {
		if ($this->clock->isDue(schedule: $schedule->getObject(), now: $now) === false) {
			return null;
		}

		$data = $this->advance(schedule: $schedule, now: $now);
		if ($data === null) {
			return null;
		}

		$targets = $this->schedules->targets(schedule: $schedule);
		if ($targets === null) {
			return null;
		}

		$result = $this->evalRuns->run(
			dataset: $targets['dataset'],
			agent: $targets['agent'],
			regressionThresholdOverride: $this->threshold(data: $data)
		);

		$data['lastRunId'] = $result['evalRunId'];
		$this->schedules->save(schedule: $schedule, data: $data);
		$this->warnOnRegression(schedule: $schedule, targets: $targets, result: $result);

		return $result;
	}//end runIfDue()

	/**
	 * Store the next run before this one starts. A cron expression that is not
	 * valid switches the schedule off instead.
	 *
	 * @param ObjectEntity      $schedule The schedule.
	 * @param DateTimeImmutable $now      The current moment.
	 *
	 * @return array<string, mixed>|null The stored payload, or null when the schedule was switched off.
	 */
	private function advance(ObjectEntity $schedule, DateTimeImmutable $now): ?array {
		$data = $schedule->getObject();
		$next = $this->clock->nextRun(cronExpr: (string)($data['cronExpr'] ?? ''), now: $now);
		if ($next === null) {
			$data['enabled'] = false;
			$this->schedules->save(schedule: $schedule, data: $data);
			return null;
		}

		$data['nextRun'] = $next->format(format: DateTimeInterface::ATOM);
		$this->schedules->save(schedule: $schedule, data: $data);

		return $data;
	}//end advance()

	/**
	 * The schedule's regression threshold, when it sets one.
	 *
	 * @param array<string, mixed> $data The schedule payload.
	 *
	 * @return int|null The threshold in percentage points.
	 */
	private function threshold(array $data): ?int {
		$threshold = $data['regressionThresholdPercent'] ?? null;
		if (is_int(value: $threshold) === false) {
			return null;
		}

		return $threshold;
	}//end threshold()

	/**
	 * Notify the schedule's owner when the run's regression gate failed.
	 *
	 * @param ObjectEntity                                $schedule The schedule.
	 * @param array{dataset: ObjectEntity, agent: Agent} $targets  The dataset and the agent.
	 * @param array<string, mixed>                        $result   The run result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
	 */
	private function warnOnRegression(ObjectEntity $schedule, array $targets, array $result): void {
		if ($result['regressionGateResult'] !== 'failed') {
			return;
		}

		$this->notifier->notify(
			owner: (string)$schedule->getOwner(),
			runId: (string)$result['evalRunId'],
			parameters: [
				'name'      => (string)$targets['agent']->getName(),
				'dataset'   => (string)($targets['dataset']->getObject()['name'] ?? ''),
				'datasetId' => (string)$targets['dataset']->getUuid(),
				'previous'  => $result['previousPassRate'],
				'current'   => $result['passRate'],
			]
		);
	}//end warnOnRegression()
}//end class
