<?php

/**
 * Test stub for OCA\Talk\Manager (spreed 24.0.1).
 *
 * Only getRoomForUserByToken() is modelled — the owner-scoped, membership-checked
 * room resolution Hermiq uses (throws RoomNotFoundException when the owner has no
 * access). Real signature verified against spreed 24.0.1 lib/Manager.php.
 *
 * @category Stub
 * @package  OCA\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Talk;

/**
 * Minimal stub of the spreed room manager.
 */
class Manager {
	/**
	 * Resolve a room by token scoped to a user (membership-checked).
	 *
	 * @param string $token The room token.
	 * @param string|null $userId The user the room is resolved for.
	 * @param string|null $sessionId Unused in the stub.
	 * @param bool $includeLastMessage Unused in the stub.
	 * @param bool $isSIPBridgeRequest Unused in the stub.
	 *
	 * @return Room The resolved room.
	 */
	public function getRoomForUserByToken(
		string $token,
		?string $userId,
		?string $sessionId = null,
		bool $includeLastMessage = false,
		bool $isSIPBridgeRequest = false,
	): Room {
		return new Room();
	}//end getRoomForUserByToken()
}//end class
