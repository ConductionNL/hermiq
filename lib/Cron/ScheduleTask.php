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
 * @package  OCA\Hermiq\Cron
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

namespace OCA\Hermiq\Cron;

use OCA\Hermiq\Service\ScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

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
class ScheduleTask extends TimedJob
{
    /**
     * Constructor.
     *
     * Configures the polling cadence and delegates execution to the service.
     *
     * @param ITimeFactory    $time            Time factory for TimedJob scheduling.
     * @param ScheduleService $scheduleService Service that dispatches due schedules.
     *
     * @spec openspec/changes/agent-schedule-dispatcher/tasks.md#task-2-2
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ScheduleService $scheduleService,
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
     */
    public function run(mixed $argument): void
    {
        $this->scheduleService->run();

    }//end run()
}//end class
