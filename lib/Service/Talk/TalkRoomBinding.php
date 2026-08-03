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
     * `talkRoomOrigin` for a room Hermiq created for a session.
     *
     * @var string
     */
    public const ORIGIN_CREATED = 'created';

    /**
     * `talkRoomOrigin` for a room Hermiq was invited into or delivered a report
     * into. Also the meaning of an ABSENT value, which is why nothing needed
     * backfilling when the property was introduced.
     *
     * @var string
     */
    public const ORIGIN_BOUND = 'bound';

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
     * Whether Hermiq created this room for a session, rather than being invited into it.
     *
     * 🔴 Read from stored data, never inferred from the room's current shape.
     * The value decides whether the agent answers messages it was not addressed
     * in, so a heuristic ("the room has just the owner and one bot") would
     * silently flip that the moment somebody invites a second person.
     *
     * Absent, unreadable, or no bound conversation all mean `bound` — the
     * pre-change behaviour, which keeps the mention gate ON. Failing closed
     * matters here: the wrong default would make an agent start answering every
     * message in somebody else's team room.
     *
     * @param string $roomToken The Talk room token.
     *
     * @return bool True when Hermiq created the room.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
     */
    public function isCreatedRoom(string $roomToken): bool
    {
        $conversation = $this->findByRoomToken(roomToken: $roomToken);
        if (($conversation instanceof ObjectEntity) === false) {
            return false;
        }

        $data = $conversation->getObject();

        return ((string) ($data['talkRoomOrigin'] ?? '') === self::ORIGIN_CREATED);

    }//end isCreatedRoom()

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

    /**
     * Bring a bound session's roster up to date with the room's membership.
     *
     * 🔴 This is what lets somebody INVITED to a session room afterwards take a
     * turn. Authorization reads the STORED roster and deliberately never live
     * room membership (talk-shared-sessions), so without this a late joiner is
     * a room member who is not on the list, and their first message is refused.
     *
     * Additive within the room's membership: the owner is implicit and stays
     * off the list, bots are excluded, and a user who has LEFT the room drops
     * off the roster by the same path — which is the point, since the roster is
     * the permission.
     *
     * Returns the session unchanged when nothing moved, so the common case
     * costs a comparison rather than a write. Never throws: a sync failure must
     * not cost the turn that triggered it.
     *
     * @param ObjectEntity $conversation The bound session.
     * @param array        $participants Current room member uids.
     *
     * @return ObjectEntity The session, with its roster updated when it changed.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-room-participants-become-session-participants
     */
    public function syncParticipants(ObjectEntity $conversation, array $participants): ObjectEntity
    {
        try {
            $payload = $conversation->getObject();
            $owner   = (string) ($payload['userId'] ?? '');

            $roster = [];
            foreach ($participants as $participant) {
                if (is_string($participant) === true && $participant !== '' && $participant !== $owner) {
                    $roster[] = $participant;
                }
            }

            $roster  = array_values(array_unique($roster));
            $current = array_values((array) ($payload['participants'] ?? []));
            sort($roster);
            sort($current);

            if ($roster === $current) {
                return $conversation;
            }

            // SaveObject is PUT-semantic: carry the whole payload forward, or
            // the fields not mentioned here are deleted.
            $payload['participants'] = $roster;

            return $this->objectService->saveObject(
                object: $payload,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA,
                uuid: (string) $conversation->getUuid()
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkRoomBinding] Could not sync the session roster (the turn is unaffected)',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'conversation' => (string) $conversation->getUuid(),
                    'error'        => $e->getMessage(),
                ]
            );
            return $conversation;
        }//end try

    }//end syncParticipants()

    /**
     * Create the conversation object bound to a room Hermiq just created.
     *
     * The binding records `origin: created`, which is what later tells
     * `isAddressed()` to answer freely in this room rather than waiting to be
     * mentioned. That origin is STORED here, never inferred later from the
     * room's shape — inferring it would silently flip the agent's behaviour the
     * moment somebody invited a second participant.
     *
     * The owner is excluded from the participant roster: they are the room's
     * owner, not one of the people invited into it.
     *
     * @param string             $roomToken    The Talk room token.
     * @param string             $agentId      The agent bound to this room.
     * @param string             $ownerUid     The session owner's uid.
     * @param array<int, string> $participants Additional invited uids.
     *
     * @return ObjectEntity|null The stored conversation, or null when it could not be written.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
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
