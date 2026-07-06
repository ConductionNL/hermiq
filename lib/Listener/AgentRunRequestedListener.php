<?php

/**
 * Hermiq AgentRunRequestedListener.
 *
 * Listens for OpenRegister's `AgentRunRequestedEvent` (ADR-041 cross-app command —
 * dispatched by a declarative `x-openregister-flows` action of `type: "agent"`) and
 * enqueues a background job to perform the governed run. The listener itself stays
 * FAST: it validates the dispatch mode and enqueues; the actual kill-switch/approval/
 * agent-turn/write-back work happens in AgentRunRequestedJob → FlowAgentRunService,
 * off the triggering request (`mode: "async"` — SPECTR-NEXTCLOUD-PLAN.md §5.2 point 5:
 * classification lands seconds later, never inline on the save that fired it).
 *
 * OpenRegister is a hard dependency of Hermiq already (ChatService, ObjectService,
 * AgentMapper, …), so this listener references OR's real event class directly — no
 * class_exists() guard is needed on the registration side, mirroring the existing
 * DeepLinkRegistrationListener precedent.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/flow-agent-listener/tasks.md#1-listener-and-queued-job
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Cron\AgentRunRequestedJob;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a governed-agent-run request into a queued background job.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/flow-agent-listener/tasks.md#1-listener-and-queued-job
 */
class AgentRunRequestedListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param IJobList        $jobList Enqueues the one-shot AgentRunRequestedJob.
     * @param LoggerInterface $logger  Logs unsupported modes / enqueue failures.
     */
    public function __construct(
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the agent-run-requested event.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-1-2
     */
    public function handle(Event $event): void
    {
        if ($event instanceof AgentRunRequestedEvent === false) {
            return;
        }

        $payload = $event->getPayload();

        $mode = (string) ($payload['mode'] ?? 'async');
        if ($mode !== 'async') {
            $this->logger->warning(
                sprintf('Hermiq ignoring flow agent-run request with unsupported mode "%s".', $mode)
            );
            return;
        }

        try {
            $this->jobList->add(AgentRunRequestedJob::class, $payload);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not enqueue flow agent-run job: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end handle()
}//end class
