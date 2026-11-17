<?php

/**
 * Hermiq CitationCheck: a report after its citations were checked, and the counts.
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
 * The checked report text and how many citations were checked and failed.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-every-citation-is-checked-against-what-was-read-req-research-003
 */
final class CitationCheck {

	/**
	 * Constructor.
	 *
	 * @param string $report  The report with failing citations removed and their sentences marked.
	 * @param int    $checked How many citations the draft carried.
	 * @param int    $failed  How many of them did not match what was read.
	 */
	public function __construct(
		public readonly string $report,
		public readonly int $checked,
		public readonly int $failed,
	) {
	}//end __construct()

	/**
	 * The counts as the conversation message words them, e.g. "14 sources, 31 citations checked, 2 unverified".
	 *
	 * @param int $sources How many sources the run read.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004
	 */
	public function countsLine(int $sources): string {
		return sprintf(
			'%d %s, %d %s checked, %d unverified',
			$sources,
			self::noun(count: $sources, singular: 'source'),
			$this->checked,
			self::noun(count: $this->checked, singular: 'citation'),
			$this->failed
		);
	}//end countsLine()

	/**
	 * A noun in the singular for one, else with an s.
	 *
	 * @param int    $count    The count.
	 * @param string $singular The singular noun.
	 *
	 * @return string The noun.
	 */
	private static function noun(int $count, string $singular): string {
		if ($count === 1) {
			return $singular;
		}

		return $singular . 's';
	}//end noun()
}//end class
