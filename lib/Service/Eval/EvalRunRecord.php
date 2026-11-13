<?php

/**
 * Hermiq EvalRunRecord
 *
 * What one stored eval run records.
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

use DateTimeImmutable;

/**
 * What one stored eval run records.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class EvalRunRecord {

	/**
	 * Constructor.
	 *
	 * @param string $datasetId The dataset UUID.
	 * @param string $agentId The agent UUID.
	 * @param string $organisation The agent's organisation.
	 * @param string|null $agentVersionId Inert field.
	 * @param string $status completed|failed.
	 * @param DateTimeImmutable $startedAt When the run began.
	 * @param DateTimeImmutable $endedAt When the run finished.
	 * @param array<int,array<string,mixed>> $results Per-case results.
	 * @param float $passRate Aggregate pass-rate.
	 * @param string $regressionGateResult passed|failed|not_applicable.
	 * @param float|null $previousPassRate The compared-against prior run's pass-rate.
	 * @param int $regressionThresholdPercent The effective threshold applied.
	 * @param array<string,mixed> $extra Paired-mode fields (skill-evals:
	 *                                   baselineMode, attributionMode,
	 *                                   skillResults, joint-mode
	 *                                   baselineResults/baselinePassRate);
	 *                                   empty for plain runs.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $datasetId,
		public readonly string $agentId,
		public readonly string $organisation,
		public readonly ?string $agentVersionId,
		public readonly string $status,
		public readonly DateTimeImmutable $startedAt,
		public readonly DateTimeImmutable $endedAt,
		public readonly array $results,
		public readonly float $passRate,
		public readonly string $regressionGateResult,
		public readonly ?float $previousPassRate,
		public readonly int $regressionThresholdPercent,
		public readonly array $extra = [],
	) {
	}//end __construct()
}//end class
