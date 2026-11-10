<?php

/**
 * Hermiq GitHubSkillTarget
 *
 * The validated GitHub repository a skill install fetches from, and for whom.
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

use OCA\Hermiq\Service\GitHubTemplateCatalogService;

/**
 * Owner, repo and ref of a skill repository, with the caller and the credential.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
 */
final class GitHubSkillTarget {

	/**
	 * Build the target.
	 *
	 * @param string      $owner        The validated repository owner.
	 * @param string      $repo         The validated repository name.
	 * @param string|null $ref          The validated ref, or null for the default branch.
	 * @param string      $actingUserId The caller.
	 * @param string|null $credentialId The broker credential, or null for none.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
		public readonly ?string $ref,
		public readonly string $actingUserId,
		public readonly ?string $credentialId,
	) {
	}//end __construct()

	/**
	 * The named arguments of the catalog's package and auxiliary-file fetches.
	 *
	 * @return array{kind: string, owner: string, repo: string, ref: string|null, actingUserId: string, credentialId: string|null}
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
	 */
	public function fetchArguments(): array {
		return [
			'kind' => GitHubTemplateCatalogService::KIND_SKILL,
			'owner' => $this->owner,
			'repo' => $this->repo,
			'ref' => $this->ref,
			'actingUserId' => $this->actingUserId,
			'credentialId' => $this->credentialId,
		];
	}//end fetchArguments()
}//end class
