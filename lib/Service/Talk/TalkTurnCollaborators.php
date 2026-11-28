<?php

/**
 * Hermiq TalkTurnCollaborators
 *
 * The room and identity side of a Talk turn: the Talk bridge that reads and
 * writes the room, the binding that resolves the room's session, and the user
 * manager and session the turn impersonates its speaker through. Grouped so the
 * turn service takes them as one collaborator.
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
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The room I/O, the room binding and the speaker's identity.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */
final class TalkTurnCollaborators {

	/**
	 * Constructor.
	 *
	 * @param TalkBridge      $bridge      Talk availability and room I/O.
	 * @param TalkRoomBinding $roomBinding Resolves the conversation bound to a room.
	 * @param IUserManager    $userManager Resolves the speaker and their display name.
	 * @param IUserSession    $userSession Impersonates the speaker for the turn.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		public readonly TalkBridge $bridge,
		public readonly TalkRoomBinding $roomBinding,
		public readonly IUserManager $userManager,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
