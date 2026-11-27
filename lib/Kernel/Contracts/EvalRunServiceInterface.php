<?php

/**
 * Contract of EvalRunService
 *
 * The public surface of EvalRunService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Db\Agent;

/**
 * Contract of EvalRunService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EvalRunServiceInterface {

	/**
	 * Run the given EvalDataset against the given Agent, applying the same
	 * governance gates a scheduled tick applies before ever invoking the agent.
	 *
	 * @param ObjectEntity $dataset The EvalDataset (already owner-guarded
	 *                              by the controller).
	 * @param Agent $agent The target Agent (already owner-guarded
	 *                     by the controller).
	 * @param string|null $agentVersionId Reserved for agent-versioning
	 *                                    (not yet built) — accepted
	 *                                    and stored verbatim, never
	 *                                    resolved or validated.
	 * @param int|null $regressionThresholdOverride A per-trigger override (percentage
	 *                                              points) for the regression-gate
	 *                                              threshold; null uses the
	 *                                              instance-wide IAppConfig default.
	 * @param bool $baseline Whether to run in PAIRED baseline
	 *                       mode (skill-evals): the with-half
	 *                       exposes installed ∪ linked skills,
	 *                       the without-half(s) detach them
	 *                       per the agent's evalBaselineMode
	 *                       — in-memory only. False (every
	 *                       pre-existing caller) is
	 *                       byte-identical to before.
	 *
	 * @return array{evalRunId:string,status:string,passRate:float,regressionGateResult:string,previousPassRate:?float}
	 *
	 * @throws InvalidArgumentException When `$baseline` is true but the dataset has no
	 *                                  linked skills (`skillRefs` empty) — the
	 *                                  controller maps this to 400.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable)        $regressionThresholdOverride mirrors the
	 *   documented regressionThresholdPercent contract field — the clarity IS the length.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $baseline is the documented paired-mode
	 *   API switch (skill-evals); false keeps every pre-existing caller byte-identical.
	 *
	 * @spec openspec/specs/agent-evals/spec.md#requirement-kill-switch-and-budget-hard-cap-gate-an-eval-run-exactly-as-they-gate-a-schedule-tick
	 * @spec openspec/specs/agent-evals/spec.md#requirement-every-half-of-a-paired-run-counts-toward-the-same-budgets-and-gates
	 */
	public function run(
		ObjectEntity $dataset,
		Agent $agent,
		?string $agentVersionId = null,
		?int $regressionThresholdOverride = null,
		bool $baseline = false,
	): array;
}//end interface
