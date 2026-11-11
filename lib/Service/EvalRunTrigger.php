<?php

/**
 * Hermiq EvalRunTrigger.
 *
 * One request to run an EvalDataset against an Agent as EvalRunService carries
 * it from its entry point through the governance gates to the case execution:
 * the dataset, the agent the run executes as, when it started, and the
 * per-trigger choices (agent version, regression-threshold override, paired
 * baseline mode). Built by that service and never handed out of it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/specs/agent-evals/spec.md#requirement-kill-switch-and-budget-hard-cap-gate-an-eval-run-exactly-as-they-gate-a-schedule-tick
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One triggered eval run.
 *
 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evalrun-executes-each-case-through-the-agent-s-real-engine-path
 */
final class EvalRunTrigger {

	/**
	 * The agent UUID.
	 *
	 * @var string
	 */
	public string $agentId = '';

	/**
	 * The agent's organisation.
	 *
	 * @var string
	 */
	public string $organisation = '';

	/**
	 * The agent's owner uid (impersonation target).
	 *
	 * @var string
	 */
	public string $owner = '';

	/**
	 * Reserved for agent-versioning: stored verbatim, never resolved.
	 *
	 * @var string|null
	 */
	public ?string $agentVersionId = null;

	/**
	 * A per-trigger regression threshold (percentage points); null uses the app setting.
	 *
	 * @var int|null
	 */
	public ?int $regressionThresholdOverride = null;

	/**
	 * Whether the run is a PAIRED baseline run (skill-evals).
	 *
	 * @var bool
	 */
	public bool $baseline = false;

	/**
	 * Start the run.
	 *
	 * @param ObjectEntity      $dataset   The EvalDataset.
	 * @param DateTimeImmutable $startedAt When the run began.
	 */
	public function __construct(
		public readonly ObjectEntity $dataset,
		public readonly DateTimeImmutable $startedAt,
	) {
	}//end __construct()

	/**
	 * The dataset UUID.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evalrun-executes-each-case-through-the-agent-s-real-engine-path
	 */
	public function datasetId(): string {
		return (string)$this->dataset->getUuid();
	}//end datasetId()
}//end class
