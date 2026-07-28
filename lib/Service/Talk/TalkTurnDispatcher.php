<?php

/**
 * Hermiq TalkTurnDispatcher.
 *
 * Chooses how a Talk-originated turn leaves the inbound request, and is the
 * reason the bridge can be called event-driven rather than merely queued.
 *
 * "Make it event-driven instead of queue-driven" cannot be satisfied with
 * `IEventDispatcher`: NC event dispatch is SYNCHRONOUS, so its listeners run
 * inside the same request — swapping the queue for an event would put the LLM
 * call straight back into the Talk sender's message-send request, which is the
 * one thing the bridge must never do.
 *
 * Nextcloud's genuinely asynchronous seam is `OCP\TaskProcessing`. Core's
 * `Manager::scheduleTask()` calls `trigger()` on an `ITriggerableProvider`
 * WITHIN the originating request — a cheap nudge — and the runner then pulls
 * the task out-of-band, so the answer lands in seconds instead of waiting for
 * a cron tick.
 *
 * The load-bearing caveat: `ISynchronousProvider` is NOT the fast path. Core
 * schedules those via `SynchronousBackgroundJob extends QueuedJob` — the same
 * cron tick as the fallback, with more indirection. Hermiq's existing
 * providers are all synchronous, so registering the turn against one would
 * look like an optimisation and change nothing.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/talk-chat-bridge/tasks.md#4-out-of-request-turn-execution-one-service-two-hand-offs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\Hermiq\Cron\TalkTurnJob;
use OCP\BackgroundJob\IJobList;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands a Talk turn off for out-of-request execution.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
 */
class TalkTurnDispatcher
{

    /**
     * Core's triggerable-provider interface, referenced BY NAME.
     *
     * Deliberately a string rather than an `instanceof` on the imported
     * interface: `ITriggerableProvider` ships in the Nextcloud 34 runtime but
     * is absent from the OCP package this app pins, and from older Nextcloud
     * releases altogether. `is_a()` with a string degrades to false where the
     * interface does not exist, which is exactly the fallback behaviour wanted,
     * whereas importing it would tie the app to a newer OCP for no gain.
     *
     * @var string
     */
    private const TRIGGERABLE_PROVIDER = 'OCP\\TaskProcessing\\ITriggerableProvider';

    /**
     * Constructor.
     *
     * The TaskProcessing manager is optional so the dispatcher still
     * constructs (and falls back to the queue) on an instance where it cannot
     * be resolved.
     *
     * @param IJobList                    $jobList     The background job list (fallback path).
     * @param LoggerInterface             $logger      PSR-3 logger.
     * @param ITaskProcessingManager|null $taskManager Core TaskProcessing manager (fast path).
     */
    public function __construct(
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
        private readonly ?ITaskProcessingManager $taskManager=null,
    ) {
    }//end __construct()

    /**
     * Hand the turn off for execution outside this request.
     *
     * @param string $conversationUuid The bound conversation.
     * @param string $speakerUid       The uid whose turn this is.
     * @param string $message          The message text.
     * @param string $roomToken        The room to answer in.
     *
     * @return string The path taken: `triggered` or `queued`.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
     */
    public function dispatch(string $conversationUuid, string $speakerUid, string $message, string $roomToken): string
    {
        $argument = [
            'conversationUuid' => $conversationUuid,
            'speakerUid'       => $speakerUid,
            'message'          => $message,
            'roomToken'        => $roomToken,
        ];

        // The queued job is the durable hand-off and today it is the ONLY one
        // that actually executes a turn: the fast path needs a registered
        // ITriggerableProvider that pulls Hermiq turns, and no such runner
        // ships yet (hermiq's existing TaskProcessing providers are all
        // ISynchronousProvider, which core runs on the same cron tick).
        //
        // `triggerFastPath()` is the seam that activates when one does. It is
        // deliberately a nudge only — the queued job stays the durable record
        // either way, so a turn can never be lost between the two mechanisms.
        $this->jobList->add(TalkTurnJob::class, $argument);

        if ($this->triggerFastPath() === true) {
            return 'triggered';
        }

        return 'queued';

    }//end dispatch()

    /**
     * Nudge a triggerable runner so the queued turn is picked up immediately.
     *
     * Returns false — the honest answer — whenever no triggerable provider is
     * registered, which is the case on every instance until such a runner
     * ships. Callers use the result for reporting only; the turn is already
     * durably enqueued by the time this runs.
     *
     * @return bool True when a triggerable runner was actually nudged.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
     */
    private function triggerFastPath(): bool
    {
        if ($this->taskManager === null) {
            return false;
        }

        $triggered = false;

        try {
            foreach ($this->taskManager->getProviders() as $provider) {
                if (is_a($provider, self::TRIGGERABLE_PROVIDER) === false) {
                    continue;
                }

                // Called dynamically because ITriggerableProvider is absent from
                // the pinned OCP (see TRIGGERABLE_PROVIDER). The is_a() check
                // above is what guarantees the method exists here.
                $trigger = [$provider, 'trigger'];
                if (is_callable($trigger) === true) {
                    $trigger();
                    $triggered = true;
                }
            }
        } catch (Throwable $e) {
            // A runner that cannot be nudged is not an error: the queued job
            // still carries the turn.
            $this->logger->debug(
                message: '[TalkTurnDispatcher] Could not nudge a triggerable runner; the queued turn stands',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
        }//end try

        return $triggered;

    }//end triggerFastPath()

    /**
     * Whether a triggerable TaskProcessing provider is registered.
     *
     * Only `ITriggerableProvider` counts: an `ISynchronousProvider` is run by
     * core's own QueuedJob and so is no faster than the fallback.
     *
     * @return bool True when a triggerable provider is available.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
     */
    public function hasTriggerableProvider(): bool
    {
        if ($this->taskManager === null) {
            return false;
        }

        try {
            foreach ($this->taskManager->getProviders() as $provider) {
                if (is_a($provider, self::TRIGGERABLE_PROVIDER) === true) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[TalkTurnDispatcher] Could not enumerate TaskProcessing providers; using the queued fallback',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
        }

        return false;

    }//end hasTriggerableProvider()
}//end class
