<?php

/**
 * Contract of SkillMaturityService
 *
 * The public surface of SkillMaturityService that other components call, so they depend
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of SkillMaturityService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SkillMaturityServiceInterface {

	/**
	 * Stamp the human L4 attestation (`levelEvidence.l4`) and recompute. The ONLY code
	 * path that writes `l4` — L4 is never auto-detected; the caller (attest endpoint)
	 * is responsible for the `skill.attest-maturity` action gate (ADR-023).
	 *
	 * @param ObjectEntity $skill The stored Skill object.
	 * @param string $attestedBy The attesting curator's user id.
	 * @param string $note Optional curator note.
	 *
	 * @return array<string, mixed> The refreshed `{skillId, maturityLevel, targetLevel, scorecard}` payload.
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-l4-is-human-attested-only-behind-action-authorization
	 */
	public function attestL4(ObjectEntity $skill, string $attestedBy, string $note = ''): array;

	/**
	 * Qualify a skill: recompute L1–L7 from content + evidence, persist `maturityLevel`
	 * and the refreshed `levelEvidence.l1`–`l3`, and return the seven-level scorecard.
	 * All other stored fields (including `state`, the content plane, `l4`–`l7`
	 * evidence, and `targetLevel`) are carried forward unchanged — OR saveObject is
	 * PUT-semantic, so the full stored payload is re-sent.
	 *
	 * @param ObjectEntity $skill The stored Skill object (RBAC/owner checks are the caller's job).
	 *
	 * @return array<string, mixed> The `{skillId, maturityLevel, targetLevel, scorecard}` payload.
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
	 */
	public function qualify(ObjectEntity $skill): array;
}//end interface
