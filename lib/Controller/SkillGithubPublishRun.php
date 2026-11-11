<?php

/**
 * Hermiq SkillGithubPublishRun.
 *
 * One publish of a skill to a NEW tagged GitHub repository, as
 * SkillMarketplaceController::githubPublish() carries it through its steps:
 * the route id and the acting user, then the requested target and credential
 * once read from the request, and the exported package once exported. Built by
 * that method and never handed out of the controller.
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
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCP\IUser;

/**
 * The state of one skill publish to GitHub.
 *
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */
final class SkillGithubPublishRun {

	/**
	 * The requested repository owner.
	 *
	 * @var string
	 */
	public string $owner = '';

	/**
	 * The requested repository name.
	 *
	 * @var string
	 */
	public string $repo = '';

	/**
	 * The requested visibility, as given.
	 *
	 * @var string
	 */
	public string $visibility = 'private';

	/**
	 * The broker credential the push uses.
	 *
	 * @var string
	 */
	public string $credentialId = '';

	/**
	 * The exported skill package, once exported.
	 *
	 * @var string
	 */
	public string $package = '';

	/**
	 * Start the run.
	 *
	 * @param string $skillId The route's skill id.
	 * @param IUser $user The acting user.
	 */
	public function __construct(
		public readonly string $skillId,
		public readonly IUser $user,
	) {
	}//end __construct()

	/**
	 * The visibility to create the repository with: `public` or `private`, `private` for anything else.
	 *
	 * @return string The visibility.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	public function repositoryVisibility(): string {
		if (in_array($this->visibility, ['public', 'private'], true) === false) {
			return 'private';
		}

		return $this->visibility;
	}//end repositoryVisibility()
}//end class
