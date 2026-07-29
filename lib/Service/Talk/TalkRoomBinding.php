<?php

/**
 * Hermiq TalkRoomBinding.
 *
 * Resolves a Talk room token to the `Conversation` bound to it, and binds one
 * when a room is first used.
 *
 * The resolve is a FILTER QUERY on the top-level `talkRoomToken` property. That
 * placement is load-bearing, not stylistic: OpenRegister's dot-path filters on
 * nested JSON match nothing, so a `metadata.talkRoomToken` lookup returns zero
 * rows SILENTLY — every inbound message would open a fresh blank session, the
 * bridge would look wired and be dead, and unit tests with in-memory doubles
 * would stay green throughout. Verified live: the top-level filter returns the
 * bound conversation; the nested equivalent returns `total: 0`.
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
 * @spec openspec/changes/talk-chat-bridge/tasks.md#3-room-conversation-resolution
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves and creates the Conversation bound to a Talk room.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
 */
class TalkRoomBinding
{

    /**
     * OpenRegister register slug.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for conversation objects.
     *
     * @var string
     */
    private const CONVERSATION_SCHEMA = 'conversation';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object read/write.
     * @param LoggerInterface $logger        PSR-3 logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Find the conversation bound to a room, if any.
     *
     * The register cannot express uniqueness on `talkRoomToken`, so more than
     * one conversation MAY claim a room. Resolution is therefore deterministic
     * — most recently created wins — rather than assuming a single row or
     * failing.
     *
     * @param string $roomToken The Talk room token.
     *
     * @return ObjectEntity|null The bound conversation, or null when unbound.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
     */
    public function findByRoomToken(string $roomToken): ?ObjectEntity
    {
        if ($roomToken === '') {
            return null;
        }

        try {
            $matches = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::CONVERSATION_SCHEMA)
                ->findAll(
                    config: [
                        'filters' => ['talkRoomToken' => $roomToken],
                        'sort'    => ['created' => 'DESC'],
                        'limit'   => 1,
                    ]
                );

            foreach ($matches as $match) {
                if ($match instanceof ObjectEntity) {
                    return $match;
                }
            }

            return null;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkRoomBinding] Could not resolve the conversation bound to the room',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'roomToken' => $roomToken,
                    'error'     => $e->getMessage(),
                ]
            );
            return null;
        }//end try

    }//end findByRoomToken()

    /**
     * Record the room binding on an existing conversation.
     *
     * Used by the delivery layer so a report posted into a room can be replied
     * to. Best-effort by contract: binding MUST NOT be able to fail a delivery
     * or a run, so every failure is logged and swallowed.
     *
     * @param ObjectEntity $conversation The conversation the run produced.
     * @param string       $roomToken    The room the output was delivered into.
     *
     * @return bool True when the binding was persisted.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-delivery/spec.md#requirement-talk-delivery-binds-the-delivered-for-conversation-to-the-room
     */
    public function bind(ObjectEntity $conversation, string $roomToken): bool
    {
        if ($roomToken === '') {
            return false;
        }

        try {
            $data = $conversation->getObject();
            if (($data['talkRoomToken'] ?? null) === $roomToken) {
                return true;
            }

            // The saveObject call is PUT-semantic — carry every existing field forward
            // or the omitted ones are nulled.
            $data['talkRoomToken'] = $roomToken;

            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA,
                uuid: (string) $conversation->getUuid()
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkRoomBinding] Could not bind the conversation to the room (delivery is unaffected)',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'roomToken'    => $roomToken,
                    'conversation' => (string) $conversation->getUuid(),
                    'error'        => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end bind()

    /**
     * Record the room binding on a conversation identified by uuid.
     *
     * The delivery layer knows the room token but holds only the conversation's
     * uuid, so this is the seam it uses. Best-effort exactly like `bind()`: a
     * binding failure must never fail the delivery or the run.
     *
     * @param string $conversationUuid The conversation the run produced.
     * @param string $roomToken        The room the output was delivered into.
     *
     * @return bool True when the binding was persisted.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-delivery/spec.md#requirement-talk-delivery-binds-the-delivered-for-conversation-to-the-room
     */
    public function bindByUuid(string $conversationUuid, string $roomToken): bool
    {
        if ($conversationUuid === '' || $roomToken === '') {
            return false;
        }

        try {
            $conversation = $this->objectService->find(
                id: $conversationUuid,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );

            if (($conversation instanceof ObjectEntity) === false) {
                return false;
            }

            return $this->bind(conversation: $conversation, roomToken: $roomToken);
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkRoomBinding] Could not load the conversation to bind (delivery is unaffected)',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'conversation' => $conversationUuid,
                    'error'        => $e->getMessage(),
                ]
            );
            return false;
        }//end try

    }//end bindByUuid()

    /**
     * Create a conversation bound to a room, for a room's first agent message.
     *
     * @param string   $roomToken    The Talk room token.
     * @param string   $agentId      UUID of the agent bound to the room.
     * @param string   $ownerUid     The uid opening the session (becomes the owner).
     * @param string[] $participants Other room members permitted to take a turn.
     *
     * @return ObjectEntity|null The created conversation, or null on failure.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
     */
    public function createBound(string $roomToken, string $agentId, string $ownerUid, array $participants=[]): ?ObjectEntity
    {
        try {
            $roster = [];
            foreach ($participants as $participant) {
                if (is_string($participant) === true && $participant !== '' && $participant !== $ownerUid) {
                    $roster[] = $participant;
                }
            }

            $created = $this->objectService->saveObject(
                object: [
                    'title'         => 'Talk conversation',
                    'userId'        => $ownerUid,
                    'agentId'       => $agentId,
                    'talkRoomToken' => $roomToken,
                    'participants'  => $roster,
                ],
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );

            $this->logger->info(
                message: '[TalkRoomBinding] Opened a conversation bound to a Talk room',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'roomToken'    => $roomToken,
                    'conversation' => (string) $created->getUuid(),
                    'participants' => count($roster),
                ]
            );

            return $created;
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[TalkRoomBinding] Could not open a conversation for the room',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'roomToken' => $roomToken,
                    'error'     => $e->getMessage(),
                ]
            );
            return null;
        }//end try

    }//end createBound()
}//end class
