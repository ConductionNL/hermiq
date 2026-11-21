<?php

/**
 * Hermiq TalkTurnJob.
 *
 * One-shot QueuedJob that performs a Talk-originated agent turn out of the
 * inbound request. Enqueued by `TalkTurnDispatcher` from the `BotInvokeEvent`
 * listener, because spreed invokes bots from a SYNCHRONOUS listener inside the
 * message sender's request — running the turn there would hold that request
 * open for the length of an LLM call.
 *
 * Since instant-talk the job is the SAFETY NET, not the default executor: it is
 * still enqueued for every turn, and it runs the turn only when no other
 * executor holds or finished its claim (see `TalkTurnExecutor`).
 *
 * All logic lives in `TalkTurnExecutor` and `TalkTurnService` — this class stays
 * a pure wrapper (ADR-002), exactly like `WebhookAgentRunJob` wraps
 * `WebhookAgentRunService`.
 *
 * @category Cron
 * @package  OCA\Hermiq\BackgroundJob
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
 * @spec openspec/changes/instant-talk/tasks.md#1-claims-and-dedup
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Talk\TalkTurnExecutor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Background job that runs one Talk-originated agent turn.
 *
 * @psalm-api
 *
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-bot-listener-never-runs-an-agent-turn-inline
 */
class TalkTurnJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory     $time     Core time factory required by QueuedJob.
	 * @param TalkTurnExecutor $executor Claims and runs the turn.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TalkTurnExecutor $executor,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the queued turn, unless another executor claimed or finished it.
	 *
	 * @param mixed $argument The enqueued argument shape.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	protected function run($argument): void {
		$this->executor->execute(argument: $argument);

	}//end run()
}//end class
