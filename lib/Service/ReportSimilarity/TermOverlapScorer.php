<?php

/**
 * Hermiq TermOverlapScorer.
 *
 * How alike two reports are, and which words made them so. The score is a Jaccard
 * overlap of the significant terms in each text, which has one property the whole
 * feature depends on: it can say why. A citizen told their melding was folded into an
 * existing one will sometimes disagree, and the answer cannot be that the model said
 * so; a handler needs enough to defend the grouping or to undo it, which are the only
 * two useful outcomes of that conversation.
 *
 * It is deliberately the default rather than the only judgement. Where a richer
 * similarity is configured it produces the score instead, and the group records which
 * one did, by name.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ReportSimilarity
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
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#requirement-a-group-must-carry-the-reasons-its-members-were-grouped
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ReportSimilarity;

/**
 * Scores two report texts and names the terms they share.
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-why-these-are-one-thing-is-answerable
 */
class TermOverlapScorer {

	/**
	 * The name this scorer is recorded under, so a group says which judgement
	 * produced its scores rather than implying one.
	 *
	 * @var string
	 */
	public const NAME = 'term-overlap';

	/**
	 * Words carried by every report and therefore evidence of nothing. Dutch
	 * first, because the reports are, with the English equivalents beside them for
	 * a bilingual instance.
	 *
	 * @var array<int, string>
	 */
	private const STOPWORDS = [
		'de', 'het', 'een', 'en', 'van', 'in', 'op', 'is', 'er', 'dat', 'die', 'niet', 'aan', 'bij',
		'voor', 'met', 'te', 'ik', 'we', 'wij', 'u', 'ook', 'al', 'om', 'maar', 'als', 'naar', 'dit',
		'the', 'a', 'an', 'and', 'of', 'in', 'on', 'is', 'it', 'that', 'not', 'at', 'for', 'with',
		'to', 'i', 'we', 'you', 'also', 'but', 'as', 'this',
	];

	/**
	 * The shortest word worth counting. A two-letter token is noise in both
	 * languages this sees.
	 *
	 * @var int
	 */
	private const MINIMUM_TERM_LENGTH = 3;

	/**
	 * Score two texts and name what they share.
	 *
	 * @param string $left One report's text.
	 * @param string $right The other's.
	 *
	 * @return array{score: float, terms: array<int, string>, scorer: string} The judgement.
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-why-these-are-one-thing-is-answerable
	 */
	public function score(string $left, string $right): array {
		$a = $this->terms(text: $left);
		$b = $this->terms(text: $right);

		if ($a === [] || $b === []) {
			return ['score' => 0.0, 'terms' => [], 'scorer' => self::NAME];
		}

		$shared = array_values(array_intersect($a, $b));
		$union = array_values(array_unique(array_merge($a, $b)));

		$score = 0.0;
		if ($union !== []) {
			$score = round((count($shared) / count($union)), 4);
		}

		sort($shared);

		return [
			'score' => $score,
			'terms' => $shared,
			'scorer' => self::NAME,
		];

	}//end score()

	/**
	 * The significant terms of one text: lower-cased, punctuation dropped, stop
	 * words and very short tokens removed, each counted once.
	 *
	 * @param string $text The report text.
	 *
	 * @return array<int, string> The terms.
	 */
	public function terms(string $text): array {
		$normalised = mb_strtolower(trim($text));
		$normalised = (string)preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalised);

		$tokens = preg_split('/\s+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY);
		if (is_array($tokens) === false) {
			return [];
		}

		$terms = [];
		foreach ($tokens as $token) {
			if (mb_strlen($token) < self::MINIMUM_TERM_LENGTH) {
				continue;
			}

			if (in_array($token, self::STOPWORDS, true) === true) {
				continue;
			}

			// A run of digits is an identifier, not a word: a telephone number, a
			// burgerservicenummer, a case number. It is no use as evidence that two
			// reports describe one event, and a group is read by handlers and kept
			// as a reason, so it has no business being in one.
			if (preg_match('/^\d+$/', $token) === 1) {
				continue;
			}

			$terms[$token] = true;
		}

		return array_keys($terms);
	}//end terms()
}//end class
