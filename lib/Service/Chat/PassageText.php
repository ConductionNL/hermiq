<?php

/**
 * Hermiq PassageText.
 *
 * The text side of a scoped chat turn: a page of text split into passages of a
 * bounded size, the words of a question worth matching, and how well a passage
 * matches them.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
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
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/tasks.md#task-2-retrieval-honours-a-file-or-folder-scope
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

/**
 * Splits text into passages and scores them against a question.
 *
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
 */
final class PassageText {

	/**
	 * The most characters one passage holds.
	 *
	 * @var int
	 */
	public const PASSAGE_CHARS = 1200;

	/**
	 * The shortest word of the question that is matched.
	 *
	 * @var int
	 */
	public const MIN_TERM_CHARS = 4;

	/**
	 * The text's paragraphs joined into chunks of at most PASSAGE_CHARS; a longer
	 * paragraph is cut at that length.
	 *
	 * @param string $text The text of one page.
	 *
	 * @return list<string> The chunks.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	public function chunks(string $text): array {
		$chunks = [];
		$current = '';
		foreach ($this->splitOn(pattern: '/\R\s*\R/u', text: $text) as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph !== '') {
				$current = $this->appendParagraph(chunks: $chunks, current: $current, paragraph: $paragraph);
			}
		}

		if ($current !== '') {
			$chunks[] = $current;
		}

		return $chunks;
	}//end chunks()

	/**
	 * Add a paragraph to the passage being built, closing passages that are full.
	 *
	 * @param list<string> $chunks    The closed passages, filled in place.
	 * @param string       $current   The passage being built.
	 * @param string       $paragraph The next non-empty paragraph.
	 *
	 * @return string The passage being built afterwards.
	 */
	private function appendParagraph(array &$chunks, string $current, string $paragraph): string {
		if ($current !== '' && mb_strlen($current) + 2 + mb_strlen($paragraph) > self::PASSAGE_CHARS) {
			$chunks[] = $current;
			$current = '';
		}

		$current = ltrim($current . "\n\n" . $paragraph);
		while (mb_strlen($current) > self::PASSAGE_CHARS) {
			$chunks[] = mb_substr($current, 0, self::PASSAGE_CHARS);
			$current = mb_substr($current, self::PASSAGE_CHARS);
		}

		return $current;
	}//end appendParagraph()

	/**
	 * The distinct words of the question long enough to match on, lower-cased.
	 *
	 * @param string $text The question.
	 *
	 * @return list<string> The terms.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	public function terms(string $text): array {
		$words = $this->splitOn(pattern: '/[^\p{L}\p{N}]+/u', text: mb_strtolower($text));
		$terms = array_filter($words, static fn (string $word): bool => mb_strlen($word) >= self::MIN_TERM_CHARS);

		return array_values(array_unique($terms));
	}//end terms()

	/**
	 * The text split on a pattern; no parts when the pattern fails on the text.
	 *
	 * @param string $pattern The separator pattern.
	 * @param string $text    The text.
	 *
	 * @return list<string> The parts.
	 */
	private function splitOn(string $pattern, string $text): array {
		$parts = preg_split($pattern, $text);
		if ($parts === false) {
			return [];
		}

		return $parts;
	}//end splitOn()

	/**
	 * The share of the question's terms the passage contains, 0 to 1.
	 *
	 * @param string       $text  The passage.
	 * @param list<string> $terms The question's terms, non-empty.
	 *
	 * @return float The score.
	 *
	 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001
	 */
	public function score(string $text, array $terms): float {
		$haystack = mb_strtolower($text);
		$found = 0;
		foreach ($terms as $term) {
			if (str_contains($haystack, $term) === true) {
				$found++;
			}
		}

		return round($found / count($terms), 4);
	}//end score()
}//end class
