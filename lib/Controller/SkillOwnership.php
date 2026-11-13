<?php

/**
 * Hermiq skill ownership guard.
 *
 * The skill reads the skill review and maturity routes guard on: a skill within the
 * caller's RBAC visibility, a skill the caller owns (or, for a system-seeded skill,
 * acts as custodian-owner of), and the refusals they answer when neither holds.
 * Built by those controllers from their own collaborators.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use Throwable;

/**
 * Visibility and ownership reads for one skill.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
 */
final class SkillOwnership {

	/**
	 * The guard.
	 *
	 * @param SkillServiceInterface $skillService Tenant-scoped skill visibility reads.
	 * @param SeedCustodyServiceInterface $seedCustody Owner-or-seed-custodian check.
	 */
	public function __construct(
		private readonly SkillServiceInterface $skillService,
		private readonly SeedCustodyServiceInterface $seedCustody,
	) {
	}//end __construct()

	/**
	 * The skill the signed-in user owns, or the refusal: 401 with no user, 404 (never
	 * 403) when it is absent, invisible or not theirs, so a non-owner cannot even
	 * confirm the skill exists.
	 *
	 * @param IUser|null $user The signed-in user.
	 * @param string $skillId The Skill UUID.
	 *
	 * @return ObjectEntity|JSONResponse
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
	 */
	public function ownedBy(?IUser $user, string $skillId): ObjectEntity|JSONResponse {
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$skill = $this->owned(skillId: $skillId, uid: $user->getUID());
		if ($skill === null) {
			return new JSONResponse(['error' => 'Skill not found'], Http::STATUS_NOT_FOUND);
		}

		return $skill;
	}//end ownedBy()

	/**
	 * Load the skill only if the given user OWNS it (IDOR guard). Fetches WITH RBAC
	 * enabled and additionally asserts owner identity — mirrors
	 * `EvalRunController::loadOwnedDataset()` — with ONE widening: an instance
	 * admin acts as custodian-owner of system-seeded skills (owner `__system__`,
	 * which no human owns; see `SeedCustodyService`).
	 *
	 * @param string $skillId The Skill UUID.
	 * @param string $uid The requesting user's UID.
	 *
	 * @return ObjectEntity|null The owned skill, or null when absent/not owned.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
	 */
	public function owned(string $skillId, string $uid): ?ObjectEntity {
		$skill = $this->visible(skillId: $skillId);
		if ($skill === null) {
			return null;
		}

		if ($this->seedCustody->actsAsOwner(owner: $skill->getOwner(), uid: $uid) === false) {
			return null;
		}

		return $skill;
	}//end owned()

	/**
	 * Load the skill within the caller's RBAC visibility (the review and attest
	 * actions are gated separately by the action matrix).
	 *
	 * @param string $skillId The Skill UUID.
	 *
	 * @return ObjectEntity|null The visible skill, or null when absent/invisible.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function visible(string $skillId): ?ObjectEntity {
		try {
			return $this->skillService->getSkill(skillId: $skillId);
		} catch (Throwable $e) {
			return null;
		}
	}//end visible()
}//end class
