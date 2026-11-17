<?php

/**
 * Contract of TalkRoomBinding
 *
 * The public surface of TalkRoomBinding that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of TalkRoomBinding.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkRoomBindingInterface {

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
	 * @param string $roomToken The Talk room token.
	 * @param string $agentId The agent bound to this room.
	 * @param string $ownerUid The session owner's uid.
	 * @param array<int, string> $participants Additional invited uids.
	 *
	 * @return ObjectEntity|null The stored conversation, or null when it could not be written.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
	 */
	public function createBound(string $roomToken, string $agentId, string $ownerUid, array $participants = []): ?ObjectEntity;

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
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
	 */
	public function findByRoomToken(string $roomToken): ?ObjectEntity;

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
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
	 */
	public function isCreatedRoom(string $roomToken): bool;

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
	 * @param array $participants Current room member uids.
	 *
	 * @return ObjectEntity The session, with its roster updated when it changed.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-room-participants-become-session-participants
	 */
	public function syncParticipants(ObjectEntity $conversation, array $participants): ObjectEntity;
}//end interface
