<?php

/**
 * Hermiq TalkTurnService.
 *
 * Executes ONE Talk-originated agent turn and posts the answer back into the
 * originating room.
 *
 * This is the single place the turn is executed. BOTH hand-off paths — the
 * triggered TaskProcessing path and the queued-job fallback — converge here on
 * purpose: if the turn logic were duplicated per path, the two would drift and
 * only the fast one would ever really be exercised.
 *
 * The turn runs as the SPEAKER, never as the conversation owner, so context
 * files resolve from the speaker's own user folder and credentials are scoped
 * to them — one participant can never make the agent read another's files.
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

use OCA\Hermiq\Kernel\Contracts\TalkTurnServiceInterface;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a Talk-originated agent turn and answers in the room.
 *
 * @psalm-api
 *
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
 */
class TalkTurnService implements TalkTurnServiceInterface {

	/**
	 * The acknowledging reaction the listener adds, resolved when the turn ends.
	 *
	 * @var string
	 */
	private const ACK_REACTION = '⏳';

	/**
	 * Constructor.
	 *
	 * @param Engine $engine The agent engine.
	 * @param TalkBridge $bridge Talk availability and room I/O.
	 * @param TalkRoomBinding $roomBinding Resolves the conversation bound to a room.
	 * @param IUserManager $userManager Resolves the speaker and their display name.
	 * @param IUserSession $userSession Impersonates the speaker for the turn.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param LiteracyRequirement|null $literacy The course requirement (compliance-ai-literacy).
	 * @param IAppConfig|null $appConfig Reads the edit throttle (instant-talk).
	 */
	public function __construct(
		private readonly Engine $engine,
		private readonly TalkBridge $bridge,
		private readonly TalkRoomBinding $roomBinding,
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?LiteracyRequirement $literacy = null,
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * Run one turn and post the answer into the room.
	 *
	 * Never throws: a Talk turn is a best-effort side channel, and a failure
	 * here must not surface as an unhandled background-job error. Failures are
	 * reported INTO THE ROOM rather than only logged — a user who sees the
	 * acknowledging reaction and then nothing cannot tell "still working" from
	 * "died".
	 *
	 * @param string $conversationUuid The bound conversation.
	 * @param string $speakerUid The uid whose turn this is (the acting identity).
	 * @param string $message The message text.
	 * @param string $roomToken The room to answer in.
	 * @param string $agentId The addressed agent; '' resolves it from the room's session.
	 * @param string $messageId The Talk message that addressed the agent; its ⏳ is resolved.
	 * @param array $chain The agent-to-agent chain descriptor; empty for a human-rooted turn.
	 *
	 * @return bool True when an answer was posted.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-acknowledgement-is-immediate-and-resolves-with-the-answer
	 */
	public function runTurn(
		string $conversationUuid,
		string $speakerUid,
		string $message,
		string $roomToken,
		string $agentId = '',
		string $messageId = '',
		array $chain = [],
	): bool {
		$turn = new TalkTurn(conversationUuid: $conversationUuid, speakerUid: $speakerUid);
		$turn->message = $message;
		$turn->roomToken = $roomToken;
		$turn->agentId = $agentId;
		$turn->chain = $chain;

		try {
			return $this->runAsSpeaker(turn: $turn);
		} finally {
			// The answer or the failure notice is in the room now, so the ⏳ is
			// no longer the latest signal and must not linger as if still working.
			$this->resolveAcknowledgement(turn: $turn, messageId: $messageId);
		}
	}//end runTurn()

	/**
	 * Run the turn impersonating its speaker, and restore the identity after.
	 *
	 * @param TalkTurn $turn The turn.
	 *
	 * @return bool True when an answer was posted.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
	 */
	private function runAsSpeaker(TalkTurn $turn): bool {
		$speakerUid = $turn->speakerUid;
		$roomToken = $turn->roomToken;
		$speaker = $this->userManager->get($speakerUid);
		if ($speaker === null) {
			return $this->reportFailure(
				roomToken: $roomToken,
				reason: 'the sender could not be resolved to a Nextcloud user'
			);
		}

		// A background job carries NO session, so without impersonation every
		// OpenRegister write in the turn is attributed to "Anonymous" and
		// refused by RBAC — the turn dies before the model is ever reached.
		// Impersonating the SPEAKER (not the conversation owner) is also what
		// makes context files and credentials resolve as the person who typed.
		// Mirrors ScheduleService's impersonate → dispatch → restore sequence.
		$priorUser = $this->userSession->getUser();
		$this->userSession->setUser($speaker);

		try {
			return $this->answerTurn(turn: $turn);
		} catch (Throwable $e) {
			$this->logFailedTurn(turn: $turn, error: $e);
			return $this->reportFailure(roomToken: $roomToken, reason: $e->getMessage());
		} finally {
			// Restore the prior identity whatever happened, so a failed turn
			// can never leave the process impersonating someone.
			$this->userSession->setUser($priorUser);
		}//end try

	}//end runAsSpeaker()

	/**
	 * Remove the ⏳ the invoked bot put on the addressing message.
	 *
	 * The reaction was added by whichever bot spreed invoked: the agent's own
	 * bot, or the shared bot in a room bound before per-agent bots. Both are
	 * tried, best-effort; a reaction that cannot be removed costs nothing.
	 *
	 * @param TalkTurn $turn      The finished turn.
	 * @param string   $messageId The addressing message's id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-acknowledgement-is-immediate-and-resolves-with-the-answer
	 */
	private function resolveAcknowledgement(TalkTurn $turn, string $messageId): void {
		if ($messageId === '') {
			return;
		}

		$agentId = $this->answeringAgent(turn: $turn);
		if ($agentId !== null
			&& $this->bridge->removeReaction(roomToken: $turn->roomToken, messageId: $messageId, reaction: self::ACK_REACTION, agentId: $agentId) === true
		) {
			return;
		}

		$this->bridge->removeReaction(roomToken: $turn->roomToken, messageId: $messageId, reaction: self::ACK_REACTION);
	}//end resolveAcknowledgement()

	/**
	 * The agent answering this turn: the one the invocation named, else the
	 * one recorded on the room's session.
	 *
	 * @param TalkTurn $turn The turn.
	 *
	 * @return string|null The agent uuid, or null for the shared bot.
	 */
	private function answeringAgent(TalkTurn $turn): ?string {
		if ($turn->agentId !== '') {
			return $turn->agentId;
		}

		return $this->agentForRoom(roomToken: $turn->roomToken);
	}//end answeringAgent()

	/**
	 * Ask the engine and post its answer into the room.
	 *
	 * Compliance-ai-literacy: a speaker who must finish the course first is told
	 * so in the room, and no model is called. The answer is posted under the
	 * AGENT's own bot identity, not the shared one: without it the answer renders
	 * as the bare actor id (`bot-<hash>-bot`) or as "Hermiq (Bot)" for every agent
	 * alike — which is the whole problem per-agent bots exist to solve.
	 *
	 * @param TalkTurn $turn The turn, running as its speaker.
	 *
	 * @return bool True when an answer was posted.
	 */
	private function answerTurn(TalkTurn $turn): bool {
		$this->literacy?->assertMayUseAgents(uid: $turn->speakerUid);

		$reply = new TalkProgressiveReply(
			bridge: $this->bridge,
			roomToken: $turn->roomToken,
			agentId: $this->answeringAgent(turn: $turn),
			canEdit: $this->bridge->supportsEditing(),
			throttleSeconds: $this->editThrottleSeconds(),
			logger: $this->logger
		);
		$channel = new StreamYieldChannel();
		$channel->onToken(
			function (string $delta) use ($reply): void {
				$reply->append(delta: $delta);
			}
		);

		$result = $this->engine->processMessage(
			conversationId: $turn->conversationUuid,
			userId: $turn->speakerUid,
			userMessage: $turn->message,
			channel: $channel,
			authorId: $turn->speakerUid,
			authorDisplayName: $this->displayNameOf(uid: $turn->speakerUid)
		);

		$answer = (string)$result['message'];
		if (trim($answer) === '') {
			return $this->reportFailure(roomToken: $turn->roomToken, reason: 'the agent returned an empty answer');
		}

		return $reply->finish(answer: $answer);
	}//end answerTurn()

	/**
	 * The least time between two edits of a progressive reply, in seconds.
	 *
	 * @return float Default 2; never below half a second.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-replies-stream-progressively-where-talk-supports-message-editing
	 */
	private function editThrottleSeconds(): float {
		$seconds = 2;
		if ($this->appConfig !== null) {
			$seconds = $this->appConfig->getValueInt('hermiq', 'talk.editThrottleSeconds', 2);
		}

		return max(0.5, (float)$seconds);
	}//end editThrottleSeconds()

	/**
	 * Log a Talk-originated turn that failed.
	 *
	 * @param TalkTurn  $turn  The turn.
	 * @param Throwable $error The failure.
	 *
	 * @return void
	 */
	private function logFailedTurn(TalkTurn $turn, Throwable $error): void {
		$this->logger->error(
			message: '[TalkTurnService] Talk-originated agent turn failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'conversation' => $turn->conversationUuid,
				'speaker' => $turn->speakerUid,
				'roomToken' => $turn->roomToken,
				'error' => $error->getMessage(),
			]
		);
	}//end logFailedTurn()

	/**
	 * Tell the room the turn failed, so the reaction is not the only signal.
	 *
	 * @param string $roomToken The room to report in.
	 * @param string $reason The failure reason.
	 *
	 * @return bool Always false — a reported failure is still a failure.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
	 */
	private function reportFailure(string $roomToken, string $reason): bool {
		$this->bridge->postToRoom(
			roomToken: $roomToken,
			message: '⚠️ I could not complete that turn: ' . $reason
		);

		return false;
	}//end reportFailure()

	/**
	 * Resolve a uid's current display name, for capture at send time.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string|null The display name, or null when unresolvable.
	 *
	 * @spec openspec/specs/talk-shared-sessions/spec.md#requirement-each-human-turn-records-its-author
	 *
	 * @psalm-suppress TypeDoesNotContainType OCP 35 types getDisplayName() as
	 *   non-empty-string; Nextcloud 32-34 can still return '', so the guard stays.
	 */
	private function displayNameOf(string $uid): ?string {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return null;
		}

		$displayName = $user->getDisplayName();

		if ($displayName === '') {
			return null;
		}

		return $displayName;
	}//end displayNameOf()

	/**
	 * The agent bound to a room, for posting under its own bot identity.
	 *
	 * Read from the bound session rather than the room→agent config map: a
	 * session room is created with its agent already recorded on the session,
	 * and the map is only populated for rooms somebody bound by hand.
	 *
	 * Null degrades to the shared bot, which is the pre-change rendering — a
	 * missing agent must not cost the answer.
	 *
	 * @param string $roomToken The Talk room token.
	 *
	 * @return string|null The agent uuid, or null.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
	 */
	private function agentForRoom(string $roomToken): ?string {
		$conversation = $this->roomBinding->findByRoomToken(roomToken: $roomToken);
		if (($conversation instanceof ObjectEntity) === false) {
			return null;
		}

		$agentId = (string)(($conversation->getObject())['agentId'] ?? '');
		if ($agentId === '') {
			return null;
		}

		return $agentId;
	}//end agentForRoom()
}//end class
