<?php

/**
 * Hermiq CitationChecker.
 *
 * A report cites what it read as `[n: "exact passage"]` (design D2). A citation
 * passes when source n is in the ledger and the passage occurs in that source's
 * text once whitespace, case and quotation marks are normalised (design D3). A
 * passing citation is rendered as `[n]`; a failing one is removed and its
 * sentence ends with "(unverified)". A model that invents a plausible quote or
 * a number it never read is the failure a research report exists to avoid, so
 * nothing the model wrote is trusted without this check.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-every-citation-is-checked-against-what-was-read-req-research-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

/**
 * Checks every citation of a draft report against the source ledger.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-every-citation-is-checked-against-what-was-read-req-research-003
 */
final class CitationChecker {

	/**
	 * A citation: `[n: "passage"]`, with straight or typographic quotes.
	 *
	 * @var string
	 */
	private const CITATION = '/\[\s*(\d+)\s*:\s*["“”„‟«»\'‘’‚]([^\]]+?)["“”„‟«»\'‘’‚]\s*\]/u';

	/**
	 * What a failing citation's sentence ends with.
	 *
	 * @var string
	 */
	public const UNVERIFIED = '(unverified)';

	/**
	 * Check every citation of a draft report.
	 *
	 * @param string       $draft  The report as the model wrote it.
	 * @param SourceLedger $ledger What the run read.
	 *
	 * @return CitationCheck The checked report and its counts.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-every-citation-is-checked-against-what-was-read-req-research-003
	 */
	public function check(string $draft, SourceLedger $ledger): CitationCheck {
		$lines   = explode("\n", $draft);
		$checked = 0;
		$failed  = 0;
		foreach ($lines as $index => $line) {
			$result = $this->checkLine(line: $line, ledger: $ledger);
			$lines[$index] = $result['line'];
			$checked += $result['checked'];
			$failed  += $result['failed'];
		}

		return new CitationCheck(report: implode("\n", $lines), checked: $checked, failed: $failed);
	}//end check()

	/**
	 * Whether a passage occurs in a source's text, after normalising both.
	 *
	 * @param string $passage The quoted passage.
	 * @param string $text    The source's text.
	 *
	 * @return bool True when it occurs.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-every-citation-is-checked-against-what-was-read-req-research-003
	 */
	public static function occursIn(string $passage, string $text): bool {
		$needle = self::normalise(text: $passage);
		if ($needle === '') {
			return false;
		}

		return str_contains(self::normalise(text: $text), $needle);
	}//end occursIn()

	/**
	 * Check the citations of one line, one sentence at a time.
	 *
	 * @param string       $line   The line.
	 * @param SourceLedger $ledger The ledger.
	 *
	 * @return array{line: string, checked: int, failed: int}
	 */
	private function checkLine(string $line, SourceLedger $ledger): array {
		if (preg_match(self::CITATION, $line) !== 1) {
			return ['line' => $line, 'checked' => 0, 'failed' => 0];
		}

		$sentences = self::sentences(line: $line);
		$checked   = 0;
		$failed    = 0;
		foreach ($sentences as $index => $sentence) {
			$unverified = false;
			$sentence   = (string)preg_replace_callback(
				self::CITATION,
				function (array $match) use ($ledger, &$checked, &$failed, &$unverified): string {
					$checked++;
					$source = $ledger->source(number: (int)$match[1]);
					if ($source !== null && self::occursIn(passage: $match[2], text: $source['text']) === true) {
						return '[' . (int)$match[1] . ']';
					}

					$failed++;
					$unverified = true;
					return '';
				},
				$sentence
			);
			if ($unverified === true) {
				$sentence = self::markUnverified(sentence: $sentence);
			}

			$sentences[$index] = $sentence;
		}

		return ['line' => implode('', $sentences), 'checked' => $checked, 'failed' => $failed];
	}//end checkLine()

	/**
	 * A line cut into sentences, each keeping its closing punctuation and the space after it.
	 *
	 * A full stop inside a citation's quoted passage does not end a sentence.
	 *
	 * @param string $line The line.
	 *
	 * @return array<int, string> The sentences; joined they give the line back.
	 */
	private static function sentences(string $line): array {
		// Protect the citations, cut, then put them back.
		$citations = [];
		$masked    = (string)preg_replace_callback(
			self::CITATION,
			function (array $match) use (&$citations): string {
				$citations[] = $match[0];
				return "\u{E000}" . (count($citations) - 1) . "\u{E001}";
			},
			$line
		);

		$parts = preg_split('/(?<=[.!?])(\s+)/u', $masked, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) {
			$parts = [$masked];
		}

		$sentences = [];
		foreach ($parts as $part) {
			if ($sentences !== [] && preg_match('/^\s+$/u', $part) === 1) {
				$sentences[(count($sentences) - 1)] .= $part;
				continue;
			}

			$sentences[] = $part;
		}

		return array_map(
			static fn (string $sentence): string => (string)preg_replace_callback(
				'/\x{E000}(\d+)\x{E001}/u',
				static fn (array $m): string => $citations[(int)$m[1]],
				$sentence
			),
			$sentences
		);
	}//end sentences()

	/**
	 * A sentence whose citation was removed, ending with "(unverified)" before its closing punctuation.
	 *
	 * @param string $sentence The sentence, citation already removed, possibly with trailing space.
	 *
	 * @return string The marked sentence.
	 */
	private static function markUnverified(string $sentence): string {
		preg_match('/^(.*?)(\s*)([.!?]?)(\s*)$/us', $sentence, $parts);
		$body = rtrim((string)preg_replace('/[ \t]{2,}/u', ' ', $parts[1]));
		if (str_ends_with($body, self::UNVERIFIED) === false) {
			$body .= ' ' . self::UNVERIFIED;
		}

		return $body . $parts[3] . $parts[4];
	}//end markUnverified()

	/**
	 * Text with every quotation mark made one, whitespace collapsed and letters lower-cased.
	 *
	 * @param string $text The text.
	 *
	 * @return string The normalised text.
	 */
	private static function normalise(string $text): string {
		// Every quotation mark reads as one: a model quoting 'x' from a page that says "x" quoted it right.
		$text = strtr($text, ['“' => '"', '”' => '"', '„' => '"', '‟' => '"', '«' => '"', '»' => '"', '‘' => '"', '’' => '"', '‚' => '"', "'" => '"']);
		$text = (string)preg_replace('/\s+/u', ' ', $text);

		return trim(mb_strtolower($text));
	}//end normalise()
}//end class
