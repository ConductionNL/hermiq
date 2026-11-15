<?php

/**
 * Hermiq TalkSafeText.
 *
 * Decides which names may be put in a Talk message. Talk reads mentions, links
 * and markdown in message text, and tool, flow, schedule and skill names are free
 * text from whoever registered them.
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
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * Plain-text checks for names in Talk messages.
 *
 * @psalm-api
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
 */
final class TalkSafeText {

	/**
	 * A label Talk shows as plain text: letters, digits, spaces and `, ' ( ) -`,
	 * at most 80 characters. No `@`, no markdown, no dots, so no mention, link,
	 * autolinked domain or formatting can form.
	 *
	 * @var string
	 */
	private const LABEL = '/^[\\p{L}\\p{M}\\p{N} ,\'’()\\-]{1,80}$/uD';

	/**
	 * An id Talk shows as plain text: ASCII letters and digits, then also `.`,
	 * `_` and `-`, at most 160 characters. No `@`, `:`, `/`, `*` or backtick, so
	 * no mention or link can form; Talk links only with a scheme or a leading
	 * `www.`, and `_` inside a word is not emphasis.
	 *
	 * @var string
	 */
	private const ID = '/^[A-Za-z0-9][A-Za-z0-9._\\-]{0,159}$/D';

	/**
	 * A name Talk autolinks without a scheme: `www.` at the start of a word.
	 *
	 * @var string
	 */
	private const AUTOLINKED = '/(^|[^A-Za-z0-9])www\\./i';

	/**
	 * A free-text name with its whitespace collapsed, when it is a plain label.
	 *
	 * @param string $text The name.
	 *
	 * @return string|null The label; null when Talk could read it as more than text.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	public function label(string $text): ?string {
		$label = trim((string)preg_replace('/[\s\p{C}]+/u', ' ', $text));
		if ($label !== '' && preg_match(self::LABEL, $label) === 1) {
			return $label;
		}

		return null;
	}//end label()

	/**
	 * An id, when it is plain.
	 *
	 * @param string $identifier The id.
	 *
	 * @return string|null The id; null when Talk could read it as more than text.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	public function identifier(string $identifier): ?string {
		if (preg_match(self::ID, $identifier) === 1 && preg_match(self::AUTOLINKED, $identifier) !== 1) {
			return $identifier;
		}

		return null;
	}//end identifier()

	/**
	 * A name that is either a plain label or a plain id, in quotes.
	 *
	 * @param string $name The name.
	 * @param string $fallback The words to use when the name is neither.
	 *
	 * @return string The quoted name, or the fallback.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	public function quoted(string $name, string $fallback): string {
		$plain = ($this->label(text: $name) ?? $this->identifier(identifier: $name));
		if ($plain === null) {
			return $fallback;
		}

		return '“' . $plain . '”';
	}//end quoted()
}//end class
