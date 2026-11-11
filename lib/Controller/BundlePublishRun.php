<?php

/**
 * Hermiq BundlePublishRun.
 *
 * One skill-bundle publish, as SkillController::bundlePublish() carries it
 * through its steps: the acting user, the target and the requested skills and
 * agents once read from the request, what resolved of them, and what the
 * serialiser dropped. Built by that method and never handed out of the controller.
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
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCP\IUser;

/**
 * The state of one skill-bundle publish.
 *
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */
final class BundlePublishRun {

	/**
	 * The target repository owner.
	 *
	 * @var string
	 */
	public string $owner = '';

	/**
	 * The target repository name.
	 *
	 * @var string
	 */
	public string $repo = '';

	/**
	 * Visibility for a freshly created repository.
	 *
	 * @var string
	 */
	public string $visibility = 'private';

	/**
	 * The requested skill ids.
	 *
	 * @var array<int|string, mixed>
	 */
	public array $skillIds = [];

	/**
	 * The requested agent ids.
	 *
	 * @var array<int|string, mixed>
	 */
	public array $agentIds = [];

	/**
	 * The resolved skills: `payloads` and per-skill `outcomes`.
	 *
	 * @var array{payloads: array<int, mixed>, outcomes: array<int, mixed>}
	 */
	public array $skills = ['payloads' => [], 'outcomes' => []];

	/**
	 * The resolved agents: `payloads` and per-agent `outcomes`.
	 *
	 * @var array{payloads: array<int, mixed>, outcomes: array<int, mixed>}
	 */
	public array $agents = ['payloads' => [], 'outcomes' => []];

	/**
	 * The skills the serialiser dropped, with why.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $dropped = [];

	/**
	 * The agents the serialiser dropped, with why.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $droppedAgents = [];

	/**
	 * Start the run.
	 *
	 * @param IUser $user The acting user.
	 */
	public function __construct(
		public readonly IUser $user,
	) {
	}//end __construct()

	/**
	 * Whether any skill or agent was requested.
	 *
	 * @return bool True when either set has an entry.
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function hasRequestedContent(): bool {
		return $this->skillIds !== [] || $this->agentIds !== [];
	}//end hasRequestedContent()

	/**
	 * Whether the requested visibility is `public` or `private`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function hasKnownVisibility(): bool {
		return in_array($this->visibility, ['public', 'private'], true);
	}//end hasKnownVisibility()
}//end class
