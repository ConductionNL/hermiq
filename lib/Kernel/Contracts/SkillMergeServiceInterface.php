<?php
/**
 * Contract of SkillMergeService
 *
 * The public surface of SkillMergeService that other components call, so they depend
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
 * Contract of SkillMergeService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SkillMergeServiceInterface {

	/**
	 * Propose one merge for each pair of active skills that duplicate each other.
	 *
	 * @return array{scanned: int, proposed: int}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function proposeMerges(): array;

	/**
	 * Approve the merge proposed on a duplicate skill.
	 *
	 * @param string $duplicateId The duplicate skill's uuid.
	 *
	 * @return array{merged: bool, survivor?: string, agentsMoved?: int, error?: string}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function approveMerge(string $duplicateId): array;

	/**
	 * Reject the merge proposed on a duplicate skill.
	 *
	 * @param string $duplicateId The duplicate skill's uuid.
	 *
	 * @return array{rejected: bool, error?: string}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function rejectMerge(string $duplicateId): array;
}//end interface
