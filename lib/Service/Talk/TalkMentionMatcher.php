<?php

/**
 * Hermiq TalkMentionMatcher.
 *
 * Decides whether a Talk message addresses a given name with an `@`.
 *
 * 🔴 This is its own unit because the rule stopped being trivial. It used to be
 * `stripos($content, '@Hermiq')` — one hard-coded, single-word, ASCII name. After
 * per-agent bots the target is an arbitrary user-authored agent name, so the
 * matcher has to survive multi-word names, case differences and trailing
 * punctuation, and it must not let `@Release` satisfy a mention of an agent
 * called `Release Notes Agent` merely by sharing a first word.
 *
 * Keeping it inline pushed TalkBotInvokeListener past its complexity budget; the
 * extraction is what brings the listener back under it, and it makes the rule
 * directly testable without constructing an invocation payload.
 *
 * A non-match is always a silent "not addressed" — never an exception. The only
 * caller decides whether an agent takes a turn, and a matcher that threw would
 * turn a badly-punctuated message into a failed turn.
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
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * Matches `@name` mentions in Talk message text.
 *
 * @psalm-api
 */
class TalkMentionMatcher {
	/**
	 * Whether the text addresses any of the given names.
	 *
	 * @param string $content The DECODED message text — not the raw
	 *                        envelope, which also carries the mention
	 *                        parameters and would match a message that
	 *                        merely quotes the name.
	 * @param array<string> $names Candidate names, most specific first.
	 *
	 * @return bool True when one of the names is addressed.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
	 */
	public function matchesAny(string $content, array $names): bool {
		foreach ($names as $name) {
			if ($this->matches(content: $content, name: $name) === true) {
				return true;
			}
		}

		return false;
	}//end matchesAny()

	/**
	 * Whether the text addresses one name.
	 *
	 * @param string $content The decoded message text.
	 * @param string $name The name to look for.
	 *
	 * @return bool True when the name is addressed.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
	 */
	public function matches(string $content, string $name): bool {
		$needle = trim($name);
		if ($needle === '' || $content === '') {
			return false;
		}

		$position = stripos($content, '@' . $needle);
		if ($position === false) {
			return false;
		}

		// The match must END at a word boundary. Without this, an agent named
		// "Release" would be addressed by "@Release Notes Agent", which is aimed
		// at a different agent entirely.
		$after = substr($content, ($position + strlen($needle) + 1), 1);

		return ($after === '' || preg_match('/[\p{L}\p{N}_-]/u', $after) !== 1);
	}//end matches()

	/**
	 * Whether any rendered mention parameter names one of the targets.
	 *
	 * A rendered mention arrives as a payload parameter rather than literal
	 * text. Bots are NOT a source in spreed's collaborator search, so `@` never
	 * autocompletes an agent and this path fires only when a human's display
	 * name happens to equal the agent's — kept because it costs nothing and
	 * would otherwise be a silent gap if spreed ever starts offering bots.
	 *
	 * @param array $parameters The payload's `object.parameters`.
	 * @param array<string> $names Candidate names.
	 *
	 * @return bool True when a parameter names one of the targets.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
	 */
	public function matchesParameters(array $parameters, array $names): bool {
		foreach ($parameters as $parameter) {
			if (is_array($parameter) === false) {
				continue;
			}

			$parameterName = (string)($parameter['name'] ?? '');
			foreach ($names as $name) {
				if ($parameterName !== '' && strcasecmp($parameterName, $name) === 0) {
					return true;
				}
			}
		}

		return false;
	}//end matchesParameters()
}//end class
