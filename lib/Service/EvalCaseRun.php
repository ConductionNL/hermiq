<?php

/**
 * Hermiq EvalCaseRun.
 *
 * The case execution of one eval run as EvalRunService carries it through its
 * halves: the dataset's cases, the agent the turns run as, the skill set the
 * half in progress exposes, and the token usage and exposed skills that
 * accumulate across every half. Built by that service and never handed out of it.
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

/**
 * The state of one eval run's case execution.
 *
 * @spec openspec/specs/agent-evals/spec.md#requirement-a-paired-baseline-run-executes-with-and-without-halves-per-evalbaselinemode
 */
final class EvalCaseRun {

	/**
	 * The dataset's cases, reindexed from zero (the index is the case index).
	 *
	 * @var array<int, mixed>
	 */
	public array $cases = [];

	/**
	 * The agent's owner uid (impersonation target).
	 *
	 * @var string
	 */
	public string $owner = '';

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
	 * The effective skill set of the half in progress (null = the plain path:
	 * the agent's stored installs).
	 *
	 * @var array<int, string>|null
	 */
	public ?array $skillSetOverride = null;

	/**
	 * Running prompt/completion token totals across every half.
	 *
	 * @var array<string, int>
	 */
	public array $usage = [
		'promptTokens' => 0,
		'completionTokens' => 0,
	];

	/**
	 * Skill uuids actually exposed across every half (deduplicated).
	 *
	 * @var array<int, string>
	 */
	public array $skillsUsed = [];
}//end class
