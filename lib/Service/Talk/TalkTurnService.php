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
use OCP\IURLGenerator;
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) carried in with instant-talk; splitting it is follow-up
 * work, not part of this merge.
 */
class TalkTurnService implements TalkTurnServiceInterface {

	/**
	 * The acknowledging reaction the listener adds, resolved when the turn ends.
	 *
	 * @var string
	 */
	private const ACK_REACTION = '⏳';

	/**
	 * Talk availability and room I/O.
	 *
	 * @var TalkBridge
	 */
	private readonly TalkBridge $bridge;

	/**
	 * Resolves the conversation bound to a room.
	 *
	 * @var TalkRoomBinding
	 */
	private readonly TalkRoomBinding $roomBinding;

	/**
	 * Resolves the speaker and their display name.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * Impersonates the speaker for the turn.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * The course requirement (compliance-ai-literacy), when present.
	 *
	 * @var LiteracyRequirement|null
	 */
	private readonly ?LiteracyRequirement $literacy;

	/**
	 * Reads the edit throttle (instant-talk), when present.
	 *
	 * @var IAppConfig|null
	 */
	private readonly ?IAppConfig $appConfig;

	/**
	 * Follows an answer to the agents it mentions (instant-talk), when present.
	 *
	 * @var TalkAgentChain|null
	 */
	private readonly ?TalkAgentChain $agentChain;

	/**
	 * Builds the link to the session on the Chat page, when present.
	 *
	 * @var IURLGenerator|null
	 */
	private readonly ?IURLGenerator $urls;

	/**
	 * Constructor.
	 *
	 * @param Engine                $engine  The agent engine.
	 * @param TalkTurnCollaborators $talk    The room I/O, the room binding and the speaker's identity.
	 * @param LoggerInterface       $logger  PSR-3 logger.
	 * @param TalkTurnOptions|null  $options The literacy requirement, edit throttle, agent chain and URLs.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		private readonly Engine $engine,
		TalkTurnCollaborators $talk,
		private readonly LoggerInterface $logger,
		?TalkTurnOptions $options = null,
	) {
		$this->bridge = $talk->bridge;
		$this->roomBinding = $talk->roomBinding;
		$this->userManager = $talk->userManager;
		$this->userSession = $talk->userSession;
		$this->literacy = $options?->literacy;
		$this->appConfig = $options?->appConfig;
		$this->agentChain = $options?->agentChain;
		$this->urls = $options?->urls;
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
	 * @param TalkTurn $turn The turn: conversation, speaker, message, room, and the
	 *                       optional agent ('' resolves it from the room's session),
	 *                       addressing message (its ⏳ is resolved) and chain.
	 *
	 * @return bool True when an answer was posted.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-acknowledgement-is-immediate-and-resolves-with-the-answer
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function runTurn(TalkTurn $turn): bool {
		try {
			return $this->runAsSpeaker(turn: $turn);
		} finally {
			// The answer or the failure notice is in the room now, so the ⏳ is
			// no longer the latest signal and must not linger as if still working.
			$this->resolveAcknowledgement(turn: $turn, messageId: $turn->messageId);
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
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	private function answerTurn(TalkTurn $turn): bool {
		$this->literacy?->assertMayUseAgents(uid: $turn->speakerUid);

		$target = new TalkReplyTarget(
			roomToken: $turn->roomToken,
			agentId: $this->answeringAgent(turn: $turn),
			canEdit: $this->bridge->supportsEditing(),
			throttleSeconds: $this->editThrottleSeconds()
		);
		$reply = new TalkProgressiveReply(bridge: $this->bridge, target: $target, logger: $this->logger);
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
			authorDisplayName: $this->displayNameOf(uid: $turn->speakerUid),
			turnChannel: 'talk'
		);

		$answer = (string)$result['message'];
		if (trim($answer) === '') {
			return $this->reportFailure(roomToken: $turn->roomToken, reason: 'the agent returned an empty answer');
		}

		if ($reply->finish(answer: $this->withSessionLink(answer: $answer, roomToken: $turn->roomToken)) === false) {
			return false;
		}

		// The answer is in the room; an agent it mentions takes the next turn,
		// within the chain's limits. Still impersonating the speaker here, and
		// the chained turn runs as the chain's rooting human, never as an agent.
		$this->agentChain?->follow(turn: $turn, answer: $answer, messageId: $reply->postedMessageId());

		return true;
	}//end answerTurn()

	/**
	 * The answer, ending with the link to the session when it is the agent's
	 * first answer in the room (chat-carry-a-conversation-across-channels D3).
	 *
	 * @param string $answer    The answer.
	 * @param string $roomToken The room.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-4-the-doors-on-the-chat-page-and-in-the-room
	 */
	private function withSessionLink(string $answer, string $roomToken): string {
		if ($this->urls === null) {
			return $answer;
		}

		$sessionId = $this->roomBinding->claimFirstAnswerLink(roomToken: $roomToken);
		if ($sessionId === null) {
			return $answer;
		}

		return self::continueInHermiq(
			answer: $answer,
			url: $this->urls->getAbsoluteURL('/apps/hermiq/chat?session=' . rawurlencode($sessionId))
		);
	}//end withSessionLink()

	/**
	 * An answer ending with "Continue in Hermiq:" and the link.
	 *
	 * @param string $answer The answer.
	 * @param string $url    The session on the Chat page.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-4-the-doors-on-the-chat-page-and-in-the-room
	 */
	public static function continueInHermiq(string $answer, string $url): string {
		return rtrim($answer) . "\n\nContinue in Hermiq: " . $url;
	}//end continueInHermiq()

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
