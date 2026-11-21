<?php

/**
 * Hermiq EvalRunScores
 *
 * What an eval run scored, and how it compares with the previous run.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Eval
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

namespace OCA\Hermiq\Service\Eval;

/**
 * What an eval run scored, and how it compares with the previous run.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class EvalRunScores {

	/**
	 * Constructor.
	 *
	 * @param array<int,array<string,mixed>> $results Per-case results.
	 * @param float $passRate Aggregate pass-rate.
	 * @param string $regressionGateResult passed|failed|not_applicable.
	 * @param float|null $previousPassRate The compared-against prior run's pass-rate.
	 * @param int $regressionThresholdPercent The effective threshold applied.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) $regressionThresholdPercent mirrors the stored field name.
	 */
	public function __construct(
		public readonly array $results,
		public readonly float $passRate,
		public readonly string $regressionGateResult,
		public readonly ?float $previousPassRate,
		public readonly int $regressionThresholdPercent,
	) {
	}//end __construct()
}//end class
