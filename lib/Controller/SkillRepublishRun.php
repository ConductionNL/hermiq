<?php

/**
 * Hermiq SkillRepublishRun.
 *
 * One one-click republish of a skill to its own provenance repository, as
 * SkillVersionController::republish() carries it through its steps: the route
 * id and the visible skill, plus the credential and package once they have
 * been read. Built by that method and never handed out of the controller.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The state of one skill republish.
 *
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */
final class SkillRepublishRun {

	/**
	 * The broker credential the push uses, once read from the request.
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
	 * @param ObjectEntity $skill The visible skill.
	 */
	public function __construct(
		public readonly string $skillId,
		public readonly ObjectEntity $skill,
	) {
	}//end __construct()

	/**
	 * The GitHub owner stamped on the skill at first publish ('' when never published).
	 *
	 * @return string The owner.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	public function owner(): string {
		return (string)($this->skill->getObject()['githubOwner'] ?? '');
	}//end owner()

	/**
	 * The GitHub repository stamped on the skill at first publish ('' when never published).
	 *
	 * @return string The repository name.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	public function repo(): string {
		return (string)($this->skill->getObject()['githubRepo'] ?? '');
	}//end repo()

	/**
	 * Whether both provenance coordinates are stamped, so there is a repo to republish to.
	 *
	 * @return bool True when owner and repo are both non-empty.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	public function isPublished(): bool {
		return $this->owner() !== '' && $this->repo() !== '';
	}//end isPublished()
}//end class
