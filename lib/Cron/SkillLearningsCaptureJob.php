<?php

/**
 * Hermiq SkillLearningsCaptureJob.
 *
 * One-shot QueuedJob that performs the post-run learnings capture pass for one
 * completed run (skill-learnings, ADR-068 §3). Enqueued by ScheduleService via
 * `IJobList::add()` AFTER the run's audit entry is written — capture never sits on
 * the run's critical path (the AgentRunRequestedJob pattern). All logic lives in
 * SkillLearningsCaptureService — this class stays a pure wrapper (ADR-002), with one
 * job-level catch-all so a capture fatal can only ever affect this job, never the
 * background-job pass around it (the poison-bg-job containment).
 *
 * @category Cron
 * @package  OCA\Hermiq\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
 */

declare(strict_types=1);

namespace OCA\Hermiq\Cron;

use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that captures learnings candidates for one completed run.
 *
 * @psalm-api
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
 */
class SkillLearningsCaptureJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory                 $time           Time factory for the base job.
     * @param SkillLearningsCaptureService $captureService Runs the capture pass (idempotent,
     *                                                     budget-gated, redaction-inherited).
     * @param LoggerInterface              $logger         PSR-3 logger (catch-all diagnostics).
     */
    public function __construct(
        ITimeFactory $time,
        private readonly SkillLearningsCaptureService $captureService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Run the job: delegate the capture pass to SkillLearningsCaptureService inside a
     * catch-all — a capture error of ANY kind is logged and swallowed; it can never
     * fail or delay anything (the run it observes is already complete and persisted).
     * The service records the pass's token usage through the same `action='run'`
     * audit channel `BudgetService` aggregates (`runType: 'skill-capture'`).
     *
     * @param mixed $argument The capture payload ({runId, scheduleUuid, agentId,
     *                        skillIds, organisation, evalFail?}). Defensively checked:
     *                        `IJobList` argument storage is a JSON round-trip, not a
     *                        compile-time-guaranteed array.
     *
     * @return void
     *
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
     * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-budget-gated-and-budget-counted
     */
    protected function run($argument): void
    {
        $payload = $argument;
        if (is_array($payload) === false) {
            $payload = [];
        }

        try {
            $this->captureService->captureForRun(args: $payload);
        } catch (Throwable $e) {
            // Best-effort by contract: log and swallow — never rethrow into the
            // background-job pass.
            $this->logger->warning(
                'Hermiq learnings capture job failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end run()
}//end class
