<?php

/**
 * Hermiq GroupMembership
 *
 * A report joining a similarity group, and how the match was decided.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ReportSimilarity;

use DateTimeImmutable;

/**
 * A report joining a similarity group, and how the match was decided.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class GroupMembership {

	/**
	 * Constructor.
	 *
	 * @param string $reportId The report.
	 * @param float $score The score.
	 * @param array<int, string> $terms The shared terms.
	 * @param string $scorer Which judgement produced the score.
	 * @param string $decidedBy Whether the key or the model decided.
	 * @param DateTimeImmutable $at The moment.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $reportId,
		public readonly float $score,
		public readonly array $terms,
		public readonly string $scorer,
		public readonly string $decidedBy,
		public readonly DateTimeImmutable $at,
	) {
	}//end __construct()
}//end class
