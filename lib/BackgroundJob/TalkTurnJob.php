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
 * All logic lives in `TalkTurnService` — this class stays a pure wrapper
 * (ADR-002), exactly like `WebhookAgentRunJob` wraps `WebhookAgentRunService`.
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
 * @spec openspec/changes/talk-chat-bridge/tasks.md#4-out-of-request-turn-execution-one-service-two-hand-offs
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\Talk\TalkTurnService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Background job that runs one Talk-originated agent turn.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-bot-listener-never-runs-an-agent-turn-inline
 */
class TalkTurnJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Core time factory required by QueuedJob.
	 * @param TalkTurnService $talkTurnService The service holding all turn logic.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TalkTurnService $talkTurnService,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the queued turn.
	 *
	 * @param mixed $argument The enqueued argument shape.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			return;
		}

		$conversationUuid = (string)($argument['conversationUuid'] ?? '');
		$speakerUid = (string)($argument['speakerUid'] ?? '');
		$message = (string)($argument['message'] ?? '');
		$roomToken = (string)($argument['roomToken'] ?? '');

		if ($conversationUuid === '' || $speakerUid === '' || $roomToken === '' || $message === '') {
			return;
		}

		$this->talkTurnService->runTurn(
			conversationUuid: $conversationUuid,
			speakerUid: $speakerUid,
			message: $message,
			roomToken: $roomToken
		);

	}//end run()
}//end class
