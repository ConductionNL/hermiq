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
 * @spec openspec/changes/archive/2026-07-30-talk-chat-bridge/tasks.md#4-out-of-request-turn-execution-one-service-two-hand-offs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\Hermiq\BackgroundJob\TalkTurnJob;
use OCA\Hermiq\Kernel\Contracts\TalkTurnDispatcherInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands a Talk turn off for out-of-request execution.
 *
 * @psalm-api
 *
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
 */
class TalkTurnDispatcher implements TalkTurnDispatcherInterface {

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
	 * @param IJobList $jobList The background job list (fallback path).
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ITaskProcessingManager|null $taskManager Core TaskProcessing manager (fast path).
	 * @param TalkTurnExecutor|null $executor Claims and runs a turn (instant path).
	 * @param TalkTurnClaims|null $claims The claim store (instant path).
	 * @param TalkPostResponseRunner|null $postResponse Runs work after the response (instant path).
	 * @param IAppConfig|null $appConfig Reads the instant-path switch and cap.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly ?ITaskProcessingManager $taskManager = null,
		private readonly ?TalkTurnExecutor $executor = null,
		private readonly ?TalkTurnClaims $claims = null,
		private readonly ?TalkPostResponseRunner $postResponse = null,
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * Hand the turn off for execution outside this request.
	 *
	 * The durable enqueue always comes first. Then the immediate path: a
	 * registered triggerable runner is nudged (path A); otherwise the turn is
	 * run in this process once the sender's response is out (path B). Neither
	 * path holds the sender's request, and the queued job stays behind as the
	 * safety net either way — the turn's claim keeps the two from both running it.
	 *
	 * @param string $conversationUuid The bound conversation.
	 * @param string $speakerUid The uid whose turn this is.
	 * @param string $message The message text.
	 * @param string $roomToken The room to answer in.
	 * @param string $turnId The turn id (`TalkTurnId::derive()`); '' runs it unclaimed.
	 * @param string $agentId The addressed agent; '' resolves it from the room's session.
	 * @param string $messageId The Talk message that addressed the agent.
	 * @param array $chain The agent-to-agent chain descriptor; empty for a human-rooted turn.
	 *
	 * @return string The path taken: `triggered`, `instant` or `queued`.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function dispatch(
		string $conversationUuid,
		string $speakerUid,
		string $message,
		string $roomToken,
		string $turnId = '',
		string $agentId = '',
		string $messageId = '',
		array $chain = [],
	): string {
		$argument = [
			'conversationUuid' => $conversationUuid,
			'speakerUid' => $speakerUid,
			'message' => $message,
			'roomToken' => $roomToken,
		];
		$extra = ['turnId' => $turnId, 'agentId' => $agentId, 'messageId' => $messageId];
		foreach ($extra as $key => $value) {
			if ($value !== '') {
				$argument[$key] = $value;
			}
		}

		if ($chain !== []) {
			$argument['chain'] = $chain;
		}

		// The queued job is the durable hand-off: it is enqueued for EVERY turn,
		// whatever path runs it, so a turn can never be lost between mechanisms.
		$this->jobList->add(TalkTurnJob::class, $argument);

		if ($this->triggerFastPath() === true) {
			return 'triggered';
		}

		if ($this->deferInstant(argument: $argument) === true) {
			return 'instant';
		}

		return 'queued';
	}//end dispatch()

	/**
	 * Schedule the turn to run in this process after the response (path B).
	 *
	 * Only with a turn id and atomic claims: without them the instant run and
	 * the queued job could both answer. At run time a slot of the concurrent
	 * instant-turns cap is taken; with the cap full the turn simply stays on the
	 * queue, which is logged.
	 *
	 * @param array $argument The hand-off argument, as enqueued.
	 *
	 * @return bool True when the turn was scheduled to run after the response.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
	 */
	private function deferInstant(array $argument): bool {
		if ($this->executor === null || $this->claims === null || $this->postResponse === null) {
			return false;
		}

		if (($argument['turnId'] ?? '') === '' || $this->claims->isAtomic() === false || $this->instantEnabled() === false) {
			return false;
		}

		$executor = $this->executor;
		$claims = $this->claims;
		$cap = $this->instantCap();
		$this->postResponse->defer(
			work: function () use ($executor, $claims, $cap, $argument): void {
				if ($claims->takeSlot(cap: $cap) === false) {
					$this->logger->info(
						message: '[TalkTurnDispatcher] Instant-turn cap reached; the turn stays on the queue',
						context: ['file' => __FILE__, 'line' => __LINE__, 'roomToken' => $argument['roomToken'], 'cap' => $cap]
					);
					return;
				}

				try {
					$executor->execute(argument: $argument);
				} finally {
					$claims->releaseSlot();
				}
			},
			budgetSeconds: $claims->timeoutSeconds()
		);

		return true;
	}//end deferInstant()

	/**
	 * Whether post-response instant execution is switched on (default on).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
	 */
	private function instantEnabled(): bool {
		if ($this->appConfig === null) {
			return true;
		}

		return $this->appConfig->getValueBool('hermiq', 'talk.instantTurns', true);
	}//end instantEnabled()

	/**
	 * The most instant turns one instance runs at once (default 2).
	 *
	 * @return int
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
	 */
	private function instantCap(): int {
		if ($this->appConfig === null) {
			return 2;
		}

		return $this->appConfig->getValueInt('hermiq', 'talk.instantTurnsMax', 2);
	}//end instantCap()

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
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
	 */
	private function triggerFastPath(): bool {
		if ($this->taskManager === null) {
			return false;
		}

		$triggered = false;

		try {
			foreach ($this->taskManager->getProviders() as $provider) {
				if (is_a($provider, self::TRIGGERABLE_PROVIDER) === false) {
					continue;
				}

				// Called dynamically because ITriggerableProvider was absent from
				// the OCP this app used to pin (see TRIGGERABLE_PROVIDER). The
				// pinned OCP now ships it, so the is_a() narrowing above makes
				// the callable concrete and the is_callable() guard this
				// replaces could never be false.
				$trigger = [$provider, 'trigger'];
				$trigger();
				$triggered = true;
			}
		} catch (Throwable $e) {
			// A runner that cannot be nudged is not an error: the queued job
			// still carries the turn.
			$this->logger->debug(
				message: '[TalkTurnDispatcher] Could not nudge a triggerable runner; the queued turn stands',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
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
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
	 */
	public function hasTriggerableProvider(): bool {
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
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}

		return false;
	}//end hasTriggerableProvider()
}//end class
