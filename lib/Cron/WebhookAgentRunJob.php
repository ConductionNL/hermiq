<?php

/**
 * Hermiq WebhookAgentRunJob.
 *
 * One-shot QueuedJob that performs the actual governed agent run for a
 * verified inbound webhook trigger (agent-webhook-trigger). Enqueued directly
 * by `WebhookTriggerController::trigger()` via `IJobList::add()` so the public
 * HTTP request never blocks on the agent turn — the controller's own public
 * attack surface stays auth-check-and-enqueue only (design.md Decision 5). All
 * logic lives in `WebhookAgentRunService` — this class stays a pure wrapper
 * (ADR-002), exactly like `AgentRunRequestedJob` wraps `FlowAgentRunService`.
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
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
 */

declare(strict_types=1);

namespace OCA\Hermiq\Cron;

use OCA\Hermiq\Service\WebhookAgentRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Background job that runs one governed webhook-triggered agent run.
 *
 * @psalm-api
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
 */
class WebhookAgentRunJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory           $time            Time factory for the base job.
     * @param WebhookAgentRunService $webhookAgentRun Runs the governed dispatch.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly WebhookAgentRunService $webhookAgentRun,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Run the job: delegate the whole governed dispatch to WebhookAgentRunService.
     *
     * @param mixed $argument The webhook trigger context (scalar/array-only —
     *                        `IJobList` argument storage is a JSON round-trip,
     *                        not a compile-time-guaranteed array).
     *
     * @return void
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-4-webhookagentrunjob-webhookagentrunservice-governed-dispatch
     */
    protected function run($argument): void
    {
        $context = $argument;
        if (is_array($context) === false) {
            $context = [];
        }

        $this->webhookAgentRun->run(context: $context);

    }//end run()
}//end class
