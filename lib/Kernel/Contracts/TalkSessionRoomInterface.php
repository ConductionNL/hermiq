<?php

/**
 * Contract of TalkSessionRoom
 *
 * The public surface of TalkSessionRoom that other components call, so they depend
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
 * Contract of TalkSessionRoom.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkSessionRoomInterface {

	/**
	 * Create a room for a freshly created session and record it on the session.
	 *
	 * 🔴 Writing `talkRoomOrigin = created` is the whole point. The addressing
	 * rule reads that value to decide whether the agent answers unaddressed
	 * messages, and this is the ONLY place that writes it — everything else
	 * stays `bound` and keeps the mention gate.
	 *
	 * A second save rather than a pre-computed token: the room is named after
	 * the session, so until the session exists there is nothing to name it
	 * after. `saveObject()` is PUT-semantic, so the whole payload is carried
	 * forward — omitting a field would delete it.
	 *
	 * Returns the session unchanged when no room could be made. A session with
	 * no room is exactly a pre-Talk session, so this must never be fatal.
	 *
	 * @param ObjectEntity $conversation The freshly created session.
	 * @param string $title The session title, used as the room name.
	 * @param string $ownerUid The session owner.
	 * @param string $agentId The bound agent, whose bot joins the room.
	 *
	 * @return ObjectEntity The session, with its room recorded when one was created.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
	 */
	public function attachToSession(
		ObjectEntity $conversation,
		string $title,
		string $ownerUid,
		string $agentId,
	): ObjectEntity;

	/**
	 * Rename the session's room, but only if the session OWNS it.
	 *
	 * 🔴 The ownership test is the point of this method. A session may be bound
	 * to a room somebody else made — a team room the agent was invited into, or
	 * one a scheduled report was delivered to. Renaming a Hermiq session must
	 * not rewrite that room's title out from under the people using it, so only
	 * `talkRoomOrigin === created` is renamed.
	 *
	 * @param array $session The session payload, after the title change.
	 *
	 * @return bool True when a room was renamed.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-renaming-a-session-renames-its-room
	 */
	public function renameIfOwned(array $session): bool;
}//end interface
