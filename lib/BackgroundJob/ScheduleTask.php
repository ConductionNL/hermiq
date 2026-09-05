<?php

/**
 * Hermiq ScheduleTask.
 *
 * The single Nextcloud background job that fires Hermiq schedules. It is a thin
 * TimedJob wrapper (copying OpenConnector's JobTask -> JobService pattern) that
 * polls all due Schedule objects on each tick and delegates every decision to
 * ScheduleService::run(). Hermiq registers exactly one job class; internal
 * polling — not one IJobList entry per schedule — keeps the primitive idiomatic
 * (ADR-002).
 *
 * @category Cron
 * @package  OCA\Hermiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#2-scheduletask-timedjob-wrapper
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCA\Hermiq\Service\ScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that dispatches due Hermiq schedules.
 *
 * Runs every 5 minutes, time-sensitive, never in parallel. All logic lives in
 * ScheduleService so the TimedJob stays a pure wrapper (ADR-002).
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#2-scheduletask-timedjob-wrapper
 */
class ScheduleTask extends TimedJob {
	/**
	 * Constructor.
	 *
	 * Configures the polling cadence and delegates execution to the service.
	 *
	 * @param ITimeFactory $time Time factory for TimedJob scheduling.
	 * @param ScheduleService $scheduleService Service that dispatches due schedules.
	 * @param ScheduleFlowBridge $flowBridge Arms eligible schedules on the engine's
	 *                                       schedule trigger before each tick
	 *                                       (schedules-onto-engine-triggers).
	 * @param LoggerInterface $logger Isolates a bridge failure from the tick.
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-2-2
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ScheduleService $scheduleService,
		private readonly ScheduleFlowBridge $flowBridge,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Poll every 5 minutes — cron granularity is capped by this cadence
		// and the system-cron poll interval (ADR-002).
		$this->setInterval(seconds: 300);

		// Honour the schedule's declared cron/interval timing.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_SENSITIVE);

		// At-most-once safety: never run two dispatch ticks concurrently.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute one dispatch tick.
	 *
	 * Delegates all business logic to ScheduleService::run(); the argument is
	 * unused because the job polls every due schedule per tick rather than
	 * carrying per-schedule state.
	 *
	 * @param mixed $argument The (unused) background-job argument.
	 *
	 * @return void
	 *
	 * @phpstan-param mixed $argument
	 *
	 * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-2-2
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function run(mixed $argument): void {
		// Arm first: a schedule created since the last tick delegates its clock
		// to the engine within one poll interval, and a mirrored schedule whose
		// timing drifted is refreshed. Failure-isolated on purpose — a bridge
		// error must never block the tick, whose schedules still fire locally.
		try {
			$this->flowBridge->syncAll();
		} catch (Throwable $e) {
			$this->logger->error(
				'[hermiq] Schedule flow sync failed; this tick dispatches locally: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

		$this->scheduleService->run();

	}//end run()
}//end class
