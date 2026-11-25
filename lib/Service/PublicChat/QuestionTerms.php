<?php

/**
 * Hermiq QuestionTerms.
 *
 * The words of a visitor's question that a public chat turn searches on, and
 * the order of what they found. OpenRegister's `_search` asks for every word
 * it is given, so a whole sentence would match nothing.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\PublicChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\PublicChat;

/**
 * Splits a question into search words and ranks what they found.
 */
final class QuestionTerms {

	/**
	 * The most words of a question searched, longest first.
	 */
	public const MAX_TERMS = 4;

	/**
	 * Words shorter than this are not searched on their own.
	 */
	private const MIN_TERM_LENGTH = 3;

	/**
	 * Question words that carry no subject, Dutch and English. OpenRegister's
	 * `_search` asks for EVERY word it is given, so a visitor's whole sentence
	 * ("Wat staat er over de afvalkalender?") would match nothing.
	 */
	private const STOP_WORDS = [
		'aan', 'als', 'ben', 'bent', 'bij', 'dan', 'dat', 'den', 'der', 'deze', 'die', 'dit', 'een', 'geen', 'graag', 'heb', 'hebben',
		'heeft', 'het', 'hij', 'hoe', 'ik', 'jij', 'kan', 'kun', 'kunt', 'maar', 'mag', 'met', 'mijn', 'moet', 'naar',
		'niet', 'nog', 'ons', 'onze', 'ook', 'over', 'staan', 'staat', 'tot', 'uit', 'van', 'voor', 'waar', 'wanneer', 'waarom', 'was',
		'wat', 'waren', 'welk', 'welke', 'wel', 'wie', 'wij', 'wil', 'wordt', 'worden', 'zij', 'zijn',
		'about', 'also', 'and', 'any', 'are', 'been', 'can', 'could', 'did', 'does', 'for', 'from', 'had', 'has', 'have',
		'how', 'may', 'might', 'must', 'not', 'our', 'please', 'should', 'some', 'tell', 'that', 'the', 'there', 'these',
		'they', 'this', 'those', 'was', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would',
		'you', 'your',
	];

	/**
	 * The words of a question worth searching on their own, longest first.
	 *
	 * Lower-cased, split on anything that is not a letter or digit, without
	 * short words and question words, at most MAX_TERMS. A question of only
	 * such words is searched as it was asked.
	 *
	 * @param string $question The visitor's question.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function termsOf(string $question): array {
		$words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($question)), -1, PREG_SPLIT_NO_EMPTY);
		if (is_array($words) === false) {
			$words = [];
		}

		$terms = [];
		foreach ($words as $word) {
			if (mb_strlen($word) >= self::MIN_TERM_LENGTH && in_array($word, self::STOP_WORDS, true) === false) {
				$terms[$word] = mb_strlen($word);
			}
		}

		if ($terms === []) {
			return [trim($question)];
		}

		// Longest first: a long word says most about what is asked. Stable for equal lengths.
		$order = array_flip(array_keys($terms));
		uksort($terms, static fn (string $a, string $b): int => [$terms[$b], $order[$a]] <=> [$terms[$a], $order[$b]]);

		return array_slice(array_map('strval', array_keys($terms)), 0, self::MAX_TERMS);
	}//end termsOf()

	/**
	 * Items found by more search words first; ties keep the order they were found in.
	 *
	 * @param array<string, array<string, mixed>> $items The items, keyed by id, in the order found.
	 * @param array<string, int>                  $hits  How many words found each id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function rankByHits(array $items, array $hits): array {
		$order = array_flip(array_keys($items));
		uksort(
			$items,
			static fn (string|int $a, string|int $b): int => [($hits[$b] ?? 0), $order[$a]] <=> [($hits[$a] ?? 0), $order[$b]]
		);

		return array_values($items);
	}//end rankByHits()
}//end class
