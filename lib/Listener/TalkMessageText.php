<?php

/**
 * Hermiq Talk message text.
 *
 * The human-readable text of a Talk bot invocation's `object.content`, which spreed
 * sends as a parsed message envelope rather than as the text itself. Split out of
 * TalkBotInvokeListener, which builds it where it reads a message.
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

use Throwable;

/**
 * Reads the text out of a Talk message envelope.
 *
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
 */
final class TalkMessageText {

	/**
	 * Extract the human-readable text from a bot invocation's `object.content`.
	 *
	 * Spreed does NOT put the message text there: `ActivityPubHelper::generateNote()`
	 * sets `content` to `json_encode(['message' => …, 'parameters' => …])`, the
	 * PARSED message envelope. Handing that straight to the engine would feed
	 * the agent a JSON blob as its prompt — which is exactly what happened on
	 * the first live round-trip.
	 *
	 * Rich mentions arrive as `{mention-user1}`-style placeholders in `message`
	 * with their real values in `parameters`, so those are substituted back to
	 * their display names to keep the prompt readable.
	 *
	 * Falls back to the raw string when it is not the expected envelope, so a
	 * future spreed change degrades to "slightly odd prompt" rather than to a
	 * dropped turn.
	 *
	 * @param string $raw The invocation payload's `object.content`.
	 *
	 * @return string The human-readable message text.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
	 */
	public function plainText(string $raw): string {
		if ($raw === '' || str_starts_with(trim($raw), '{') === false) {
			return $raw;
		}

		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable) {
			return $raw;
		}

		if (is_array($decoded) === false || is_string($decoded['message'] ?? null) === false) {
			return $raw;
		}

		$parameters = ($decoded['parameters'] ?? []);
		if (is_array($parameters) === false) {
			return (string)$decoded['message'];
		}

		return $this->substituteParameters(text: (string)$decoded['message'], parameters: $parameters);
	}//end plainText()

	/**
	 * Replace `{mention-user1}`-style placeholders with their display names.
	 *
	 * @param string $text The message text carrying placeholders.
	 * @param array $parameters The invocation payload's parameter map.
	 *
	 * @return string The text with placeholders substituted.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
	 */
	private function substituteParameters(string $text, array $parameters): string {
		foreach ($parameters as $key => $parameter) {
			if (is_array($parameter) === false) {
				continue;
			}

			$name = ($parameter['name'] ?? ($parameter['id'] ?? null));
			if (is_string($name) === true && $name !== '') {
				$text = str_replace('{' . $key . '}', $name, $text);
			}
		}

		return $text;
	}//end substituteParameters()
}//end class
