<?php

/**
 * Hermiq TalkTurn.
 *
 * One Talk-originated turn: the bound conversation, the speaker it runs as, the
 * message and the room the answer goes to. Built and read only inside
 * TalkTurnService.
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
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * One Talk turn.
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
 */
final class TalkTurn {

	/**
	 * The message text.
	 *
	 * @var string
	 */
	public string $message = '';

	/**
	 * The room to answer in.
	 *
	 * @var string
	 */
	public string $roomToken = '';

	/**
	 * Constructor.
	 *
	 * @param string $conversationUuid The bound conversation.
	 * @param string $speakerUid       The uid whose turn this is (the acting identity).
	 */
	public function __construct(
		public readonly string $conversationUuid,
		public readonly string $speakerUid,
	) {
	}//end __construct()
}//end class
