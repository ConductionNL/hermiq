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
 *
 * @SuppressWarnings(PHPMD.LongVariable) $regressionThresholdPercent mirrors the stored field name.
 */
final class EvalRunRecord {

	/**
	 * The dataset id.
	 *
	 * @var string
	 */
	public readonly string $datasetId;

	/**
	 * The agent id.
	 *
	 * @var string
	 */
	public readonly string $agentId;

	/**
	 * The organisation.
	 *
	 * @var string
	 */
	public readonly string $organisation;

	/**
	 * The agent version id.
	 *
	 * @var ?string
	 */
	public readonly ?string $agentVersionId;

	/**
	 * The results.
	 *
	 * @var array
	 */
	public readonly array $results;

	/**
	 * The pass rate.
	 *
	 * @var float
	 */
	public readonly float $passRate;

	/**
	 * The regression gate result.
	 *
	 * @var string
	 */
	public readonly string $regressionGateResult;

	/**
	 * The previous pass rate.
	 *
	 * @var ?float
	 */
	public readonly ?float $previousPassRate;

	/**
	 * The regression threshold percent.
	 *
	 * @var int
	 */
	public readonly int $regressionThresholdPercent;

	/**
	 * Constructor.
	 *
	 * @param EvalRunSubject $subject The dataset, agent and organisation an eval run belongs to.
	 * @param string $status completed|failed.
	 * @param DateTimeImmutable $startedAt When the run began.
	 * @param DateTimeImmutable $endedAt When the run finished.
	 * @param EvalRunScores $scores What an eval run scored, and how it compares with the previous run.
	 * @param array<string,mixed> $extra Paired-mode fields (skill-evals:
	 *                                   baselineMode, attributionMode,
	 *                                   skillResults, joint-mode
	 *                                   baselineResults/baselinePassRate);
	 *                                   empty for plain runs.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		EvalRunSubject $subject,
		public readonly string $status,
		public readonly DateTimeImmutable $startedAt,
		public readonly DateTimeImmutable $endedAt,
		EvalRunScores $scores,
		public readonly array $extra = [],
	) {
		$this->results = $scores->results;
		$this->passRate = $scores->passRate;
		$this->regressionGateResult = $scores->regressionGateResult;
		$this->previousPassRate = $scores->previousPassRate;
		$this->regressionThresholdPercent = $scores->regressionThresholdPercent;
		$this->datasetId = $subject->datasetId;
		$this->agentId = $subject->agentId;
		$this->organisation = $subject->organisation;
		$this->agentVersionId = $subject->agentVersionId;
	}//end __construct()
}//end class
