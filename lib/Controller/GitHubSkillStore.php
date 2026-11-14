<?php

/**
 * Hermiq GitHubSkillStore
 *
 * The GitHub skill store steps behind `SkillController::githubSearch()` and
 * `SkillController::githubInstall()`: the search answer, and the fetch-then-install
 * through the unchanged quarantine gate.
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
use OCA\Hermiq\Kernel\Contracts\SkillMarketplaceServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Search answer and install for GitHub skill repositories.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
 */
final class GitHubSkillStore {

	/**
	 * The search result a failed search degrades to: never a 5xx for a third-party outage.
	 *
	 * @var array{outcome: string, cards: array{}, brokerUsed: bool, rateLimited: bool}
	 */
	public const UNREACHABLE = [
		'outcome' => GitHubTemplateCatalogServiceInterface::OUTCOME_UNREACHABLE,
		'cards' => [],
		'brokerUsed' => false,
		'rateLimited' => false,
	];

	/**
	 * Build the store steps.
	 *
	 * @param GitHubTemplateCatalogServiceInterface $catalog     Searches and fetches GitHub repositories.
	 * @param SkillMarketplaceServiceInterface      $marketplace Installs a package through the quarantine gate.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
	 */
	public function __construct(
		private readonly GitHubTemplateCatalogServiceInterface $catalog,
		private readonly SkillMarketplaceServiceInterface $marketplace,
	) {
	}//end __construct()

	/**
	 * The search answer's fields, with whether a broker credential is available.
	 *
	 * @param array<string, mixed> $result The catalog's search result, or UNREACHABLE.
	 *
	 * @return array{outcome: mixed, cards: mixed, brokerCredentialAvailable: bool, brokerUsed: mixed, rateLimited: mixed}
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 */
	public function searchAnswer(array $result): array {
		return [
			'outcome' => $result['outcome'],
			'cards' => $result['cards'],
			'brokerCredentialAvailable' => $this->catalog->isBrokerAvailable(),
			'brokerUsed' => $result['brokerUsed'],
			'rateLimited' => $result['rateLimited'],
		];
	}//end searchAnswer()

	/**
	 * Fetch the skill package and its auxiliary files, and install them quarantined.
	 *
	 * Skill-package-multifile: a published skill repo carries its auxiliary files as
	 * sibling blobs. Fetching only the package file reconstructed a bare SKILL.md and
	 * silently dropped every references/ and learnings.md entry — install must mirror
	 * what publish emitted.
	 *
	 * @param GitHubSkillTarget $target The validated target.
	 *
	 * @return ObjectEntity|null The created (quarantined) skill, or null when the package cannot be fetched.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
	 */
	public function install(GitHubSkillTarget $target): ?ObjectEntity {
		$arguments = $target->fetchArguments();
		$package = $this->catalog->fetchPackageFile(...$arguments);
		if ($package === null) {
			return null;
		}

		return $this->marketplace->installFromSource(
			package: $package,
			source: 'hub',
			createdBy: $target->actingUserId,
			auxFiles: $this->catalog->fetchAuxFiles(...$arguments)
		);
	}//end install()
}//end class
