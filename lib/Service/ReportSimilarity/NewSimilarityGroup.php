<?php

/**
 * Hermiq NewSimilarityGroup
 *
 * The report that opens a new similarity group, and the window it stays open.
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
 * The report that opens a new similarity group, and the window it stays open.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class NewSimilarityGroup {

	/**
	 * Constructor.
	 *
	 * @param string $reportType The report type.
	 * @param string $text The report's text.
	 * @param string $deterministicKey The owning app's key, when it has one.
	 * @param int $windowMinutes The window in force.
	 * @param DateTimeImmutable $at The moment.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $reportType,
		public readonly string $text,
		public readonly string $deterministicKey,
		public readonly int $windowMinutes,
		public readonly DateTimeImmutable $at,
	) {
	}//end __construct()
}//end class
