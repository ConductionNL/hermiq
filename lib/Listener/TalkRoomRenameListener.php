<?php

/**
 * Hermiq TalkRoomRenameListener.
 *
 * Follows the rename of a Talk room onto the title of the session bound to
 * it, so a session begun in Talk is listed on the Chat page under the room's
 * current name until its owner gives it a name of their own.
 *
 * Registered by event NAME (`OCA\Talk\Events\RoomModifiedEvent`), like the bot
 * listeners: Talk may not be autoloadable at register() time. Every value is
 * read through an `is_callable` getter, so an older, newer or absent Talk is
 * tolerated and simply does nothing.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md#requirement-a-session-begun-in-talk-can-be-continued-on-the-chat-page-req-schan-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * A renamed room renames its bound session, unless the owner named it.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md#requirement-a-session-begun-in-talk-can-be-continued-on-the-chat-page-req-schan-001
 */
class TalkRoomRenameListener implements IEventListener {

	/**
	 * The Talk event a room modification raises.
	 *
	 * @var string
	 */
	public const EVENT_ROOM_MODIFIED = 'OCA\\Talk\\Events\\RoomModifiedEvent';

	/**
	 * The property name Talk reports for a rename.
	 *
	 * @var string
	 */
	private const PROPERTY_NAME = 'name';

	/**
	 * Constructor.
	 *
	 * @param TalkRoomBinding $binding Finds the bound session and writes its title.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TalkRoomBinding $binding,
	) {
	}//end __construct()

	/**
	 * Follow a room rename onto the bound session's title.
	 *
	 * @param Event $event The room modification.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md#requirement-a-session-begun-in-talk-can-be-continued-on-the-chat-page-req-schan-001
	 */
	public function handle(Event $event): void {
		if (self::read(subject: $event, getter: 'getProperty') !== self::PROPERTY_NAME) {
			return;
		}

		$room  = self::read(subject: $event, getter: 'getRoom');
		$token = self::read(subject: $room, getter: 'getToken');
		if (is_string($token) === false || $token === '') {
			return;
		}

		$this->binding->followRoomRename(
			roomToken: $token,
			oldName: (string)self::read(subject: $event, getter: 'getOldValue'),
			newName: (string)self::read(subject: $event, getter: 'getNewValue')
		);
	}//end handle()

	/**
	 * Call a getter when the subject has it, else null.
	 *
	 * @param mixed  $subject The object to read.
	 * @param string $getter  The getter's name.
	 *
	 * @return mixed The value, or null.
	 */
	private static function read(mixed $subject, string $getter): mixed {
		if (is_object($subject) === false || is_callable([$subject, $getter]) === false) {
			return null;
		}

		$value = $subject->$getter();
		if (is_scalar($value) === true || is_object($value) === true || $value === null) {
			return $value;
		}

		return null;
	}//end read()
}//end class
