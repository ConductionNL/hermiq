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
 * @spec openspec/changes/talk-chat-bridge/tasks.md#4-out-of-request-turn-execution-one-service-two-hand-offs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\Hermiq\Service\Engine\Engine;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a Talk-originated agent turn and answers in the room.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
 */
class TalkTurnService
{
    /**
     * Constructor.
     *
     * @param Engine          $engine      The agent engine.
     * @param TalkBridge      $bridge      Talk availability and room I/O.
     * @param IUserManager    $userManager Resolves the speaker and their display name.
     * @param IUserSession    $userSession Impersonates the speaker for the turn.
     * @param LoggerInterface $logger      PSR-3 logger.
     */
    public function __construct(
        private readonly Engine $engine,
        private readonly TalkBridge $bridge,
        private readonly TalkRoomBinding $roomBinding,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
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
     * @param string $speakerUid       The uid whose turn this is (the acting identity).
     * @param string $message          The message text.
     * @param string $roomToken        The room to answer in.
     *
     * @return bool True when an answer was posted.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
     */
    public function runTurn(string $conversationUuid, string $speakerUid, string $message, string $roomToken): bool
    {
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
            $result = $this->engine->processMessage(
                conversationId: $conversationUuid,
                userId: $speakerUid,
                userMessage: $message,
                authorId: $speakerUid,
                authorDisplayName: $this->displayNameOf(uid: $speakerUid)
            );

            $answer = (string) $result['message'];
            if (trim($answer) === '') {
                return $this->reportFailure(
                    roomToken: $roomToken,
                    reason: 'the agent returned an empty answer'
                );
            }

            // Post under the AGENT's own bot identity, not the shared one.
            // Without this the answer renders as the bare actor id
            // (`bot-<hash>-bot`) or as "Hermiq (Bot)" for every agent alike —
            // which is the whole problem per-agent bots exist to solve.
            return $this->bridge->postToRoom(
                roomToken: $roomToken,
                message: $answer,
                agentId: $this->agentForRoom(roomToken: $roomToken)
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[TalkTurnService] Talk-originated agent turn failed',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'conversation' => $conversationUuid,
                    'speaker'      => $speakerUid,
                    'roomToken'    => $roomToken,
                    'error'        => $e->getMessage(),
                ]
            );

            return $this->reportFailure(roomToken: $roomToken, reason: $e->getMessage());
        } finally {
            // Restore the prior identity whatever happened, so a failed turn
            // can never leave the process impersonating someone.
            $this->userSession->setUser($priorUser);
        }//end try

    }//end runTurn()

    /**
     * Tell the room the turn failed, so the reaction is not the only signal.
     *
     * @param string $roomToken The room to report in.
     * @param string $reason    The failure reason.
     *
     * @return bool Always false — a reported failure is still a failure.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
     */
    private function reportFailure(string $roomToken, string $reason): bool
    {
        $this->bridge->postToRoom(
            roomToken: $roomToken,
            message: '⚠️ I could not complete that turn: '.$reason
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
     * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-each-human-turn-records-its-author
     */
    private function displayNameOf(string $uid): ?string
    {
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
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
     */
    private function agentForRoom(string $roomToken): ?string
    {
        $conversation = $this->roomBinding->findByRoomToken(roomToken: $roomToken);
        if (($conversation instanceof ObjectEntity) === false) {
            return null;
        }

        $agentId = (string) (($conversation->getObject())['agentId'] ?? '');

        return ($agentId === '') ? null : $agentId;

    }//end agentForRoom()
}//end class
