<?php

/**
 * Contract of SkillLearningsCaptureService
 *
 * The public surface of SkillLearningsCaptureService that other components call, so they depend
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

/**
 * Contract of SkillLearningsCaptureService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SkillLearningsCaptureServiceInterface {

	/**
	 * Capture learnings candidates for one completed run — the whole pass is
	 * best-effort: every failure is caught and logged, and a failure on one skill
	 * never prevents capture for the run's other exercised skills.
	 *
	 * Argument shape (the queued job's payload): `runId` (the run's own identifier,
	 * recorded on the audit entry), `scheduleUuid` (trace + accounting anchor),
	 * `agentId`, `skillIds` (the run's `skillsUsed`), `organisation` (budget scope)
	 * and optional `evalFail` (`<evalRunUuid>#<caseId>` when the captured run is a
	 * failing eval-case run — design.md Decision 8 marker contract).
	 *
	 * @param array<string, mixed> $args The capture job payload.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The linear defensive-validation
	 *   ladder over the JSON-round-tripped job payload; each branch is a trivial guard.
	 *
	 * @spec openspec/specs/skill-learnings/spec.md#requirement-capture-is-failure-isolated-from-the-run
	 * @spec openspec/specs/skill-learnings/spec.md#requirement-the-engine-records-which-skills-were-exercised-in-a-run
	 */
	public function captureForRun(array $args): void;
}//end interface
