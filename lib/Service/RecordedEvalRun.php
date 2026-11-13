<?php

/**
 * Hermiq RecordedEvalRun.
 *
 * One persisted EvalRun with the facts its audit entry and its failed-eval
 * learnings captures carry: status, pass rate, start time, token usage, the
 * exposed skills, the case results and the agent it ran as. Built by
 * EvalRunService after the persist and never handed out of it.
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
 * @spec openspec/specs/agent-evals/spec.md#requirement-an-evalrun-executes-each-case-through-the-agent-s-real-engine-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A persisted eval run, as its audit entry and fail captures see it.
 *
 * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
 */
final class RecordedEvalRun {

	/**
	 * The run's final status.
	 *
	 * @var string
	 */
	public string $status = '';

	/**
	 * The run's aggregate pass rate.
	 *
	 * @var float
	 */
	public float $passRate = 0.0;

	/**
	 * Aggregated prompt/completion token usage (for a paired run, ALL halves').
	 *
	 * @var array<string, int>
	 */
	public array $usage = [];

	/**
	 * The skill uuids the run actually exposed.
	 *
	 * @var array<int, string>
	 */
	public array $skillsUsed = [];

	/**
	 * The run's (with-half) case results.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $results = [];

	/**
	 * The run's agent UUID.
	 *
	 * @var string
	 */
	public string $agentId = '';

	/**
	 * The run's organisation (budget scope).
	 *
	 * @var string
	 */
	public string $organisation = '';

	/**
	 * Record the run.
	 *
	 * @param ObjectEntity      $evalRun   The persisted EvalRun.
	 * @param DateTimeImmutable $startedAt When the run began.
	 */
	public function __construct(
		public readonly ObjectEntity $evalRun,
		public readonly DateTimeImmutable $startedAt,
	) {
	}//end __construct()
}//end class
