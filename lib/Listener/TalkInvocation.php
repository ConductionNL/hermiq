<?php

/**
 * Hermiq Talk invocation.
 *
 * One inbound Talk room message as TalkBotInvokeListener carries it from its read
 * to its hand-off: the spreed event, its payload, the turn read out of it, and the
 * agent and conversation the room resolves to. Built by that listener and never
 * handed out of it.
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
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * One inbound Talk invocation.
 *
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
 */
final class TalkInvocation {

	/**
	 * The opted-in agent bound to the room, once resolved.
	 *
	 * @var string
	 */
	public string $agentId = '';

	/**
	 * The conversation bound to the room, once resolved.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $conversation = null;

	/**
	 * Start the invocation.
	 *
	 * @param Event $event The spreed BotInvokeEvent.
	 * @param array $payload The ActivityPub-shaped invocation payload.
	 * @param array{roomToken: string, speakerUid: string, content: string} $turn The read turn.
	 */
	public function __construct(
		public readonly Event $event,
		public readonly array $payload,
		public readonly array $turn,
	) {
	}//end __construct()

	/**
	 * The Talk room token.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
	 */
	public function roomToken(): string {
		return $this->turn['roomToken'];
	}//end roomToken()
}//end class
