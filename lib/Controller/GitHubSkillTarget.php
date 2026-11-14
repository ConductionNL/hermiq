<?php

/**
 * Hermiq GitHubSkillTarget
 *
 * The GitHub repository a skill install fetches from, and for whom.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\Contracts\GitHubTemplateCatalogServiceInterface;

/**
 * Owner, repo and ref of a skill repository, with the caller and the credential.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
 */
final class GitHubSkillTarget {

	/**
	 * The ref, or null for the default branch.
	 *
	 * @var string|null
	 */
	public readonly ?string $ref;

	/**
	 * Build the target.
	 *
	 * @param string      $owner        The repository owner, as sent.
	 * @param string      $repo         The repository name, as sent.
	 * @param mixed       $ref          The ref as sent; an empty or non-string ref is the default branch.
	 * @param string      $actingUserId The caller.
	 * @param string|null $credentialId The broker credential, or null for none.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
		mixed $ref,
		public readonly string $actingUserId,
		public readonly ?string $credentialId,
	) {
		$given = null;
		if (is_string($ref) === true && $ref !== '') {
			$given = $ref;
		}

		$this->ref = $given;
	}//end __construct()

	/**
	 * Why the coordinates are refused: `invalid_repo`, `invalid_ref`, or null when valid.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 */
	public function refusal(): ?string {
		if ($this->validRepo() === false) {
			return 'invalid_repo';
		}

		if ($this->validRef() === false) {
			return 'invalid_ref';
		}

		return null;
	}//end refusal()

	/**
	 * Whether owner and repo both match the owner/repo pattern.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 */
	private function validRepo(): bool {
		return preg_match(SkillController::OWNER_REPO_PATTERN, $this->owner) === 1
			&& preg_match(SkillController::OWNER_REPO_PATTERN, $this->repo) === 1;
	}//end validRepo()

	/**
	 * Whether the ref is absent or matches the ref pattern.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 */
	private function validRef(): bool {
		return $this->ref === null || preg_match(SkillController::REF_PATTERN, $this->ref) === 1;
	}//end validRef()

	/**
	 * The named arguments of the catalog's package and auxiliary-file fetches.
	 *
	 * @return array{kind: string, owner: string, repo: string, ref: string|null, actingUserId: string, credentialId: string|null}
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
	 */
	public function fetchArguments(): array {
		return [
			'kind' => GitHubTemplateCatalogServiceInterface::KIND_SKILL,
			'owner' => $this->owner,
			'repo' => $this->repo,
			'ref' => $this->ref,
			'actingUserId' => $this->actingUserId,
			'credentialId' => $this->credentialId,
		];
	}//end fetchArguments()
}//end class
