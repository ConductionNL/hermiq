<?php

/**
 * Hermiq ReportEvaluation.
 *
 * One incoming report as ReportSimilarityService judges it: which report, its type,
 * text and key, the moment of evaluation and the comparison window in force for its
 * type. Built by that service and never handed out of it.
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
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-two-hundred-reports-of-one-power-cut-answer-as-one-group
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ReportSimilarity;

use DateTimeImmutable;

/**
 * One report under evaluation.
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-an-old-report-is-out-of-scope
 */
final class ReportEvaluation {

	/**
	 * The report type, as the owning app names it.
	 *
	 * @var string
	 */
	public string $reportType = '';

	/**
	 * The owning app's key for this report, '' when it has none.
	 *
	 * @var string
	 */
	public string $deterministicKey = '';

	/**
	 * The moment of evaluation.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $at;

	/**
	 * The comparison window in force for the report type, in minutes.
	 *
	 * @var int
	 */
	public int $windowMinutes = 0;

	/**
	 * The moment before which a group's last member makes it closed: the window
	 * in force, counted back from the moment of evaluation (as `modify()` answers it).
	 *
	 * @var DateTimeImmutable|false
	 */
	public DateTimeImmutable|false $cutoff = false;

	/**
	 * Start the evaluation.
	 *
	 * @param string $reportId The owning app's identifier for the report.
	 * @param string $text     The report's text.
	 */
	public function __construct(
		public readonly string $reportId,
		public readonly string $text,
	) {
	}//end __construct()

}//end class
