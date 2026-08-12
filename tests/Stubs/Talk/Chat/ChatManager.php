<?php

/**
 * Test stub for OCA\Talk\Chat\ChatManager (spreed 24.0.1).
 *
 * Only sendMessage() is modelled — the post used by Hermiq. Real signature verified
 * against spreed 24.0.1 lib/Chat/ChatManager.php:
 *   sendMessage(Room $chat, ?Participant $participant, string $actorType,
 *       string $actorId, string $message, \DateTime $creationDateTime, ...): IComment
 * Hermiq ignores the return value, so the stub returns void.
 *
 * @category Stub
 * @package  OCA\Talk\Chat
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

namespace OCA\Talk\Chat;

use DateTime;
use OCA\Talk\Participant;
use OCA\Talk\Room;

/**
 * Minimal stub of the spreed chat manager.
 */
class ChatManager {
	/**
	 * Post a message to a room.
	 *
	 * @param Room $chat The target room.
	 * @param Participant|null $participant The actor's participant, or null.
	 * @param string $actorType The actor type (e.g. 'users').
	 * @param string $actorId The actor id (owner UID).
	 * @param string $message The message body.
	 * @param DateTime $creationDateTime The creation time.
	 *
	 * @return void
	 */
	public function sendMessage(
		Room $chat,
		?Participant $participant,
		string $actorType,
		string $actorId,
		string $message,
		DateTime $creationDateTime,
	): void {

	}//end sendMessage()
}//end class
