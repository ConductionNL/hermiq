<?php

/**
 * Hermiq BundleInstallRun.
 *
 * One skill-bundle install, as SkillController::bundleInstall() carries it
 * through its steps: the acting user, the source repository once read from the
 * request, the fetched bundle, and what installing its skills and agents
 * produced. Built by that method and never handed out of the controller.
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
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCP\IUser;

/**
 * The state of one skill-bundle install.
 *
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
 */
final class BundleInstallRun {

	/**
	 * The source repository owner.
	 *
	 * @var string
	 */
	public string $owner = '';

	/**
	 * The source repository name.
	 *
	 * @var string
	 */
	public string $repo = '';

	/**
	 * The ref to install from, or null for the default branch.
	 *
	 * @var string|null
	 */
	public ?string $ref = null;

	/**
	 * The fetched bundle: its `files` and whether it was `truncated`.
	 *
	 * @var array<string, mixed>
	 */
	public array $bundle = [];

	/**
	 * What installing the bundle's skills produced: `counts` and `outcomes`.
	 *
	 * @var array<string, mixed>
	 */
	public array $skillResult = [];

	/**
	 * What installing the bundle's agents produced: `outcomes`.
	 *
	 * @var array<string, mixed>
	 */
	public array $agentResult = [];

	/**
	 * Start the run.
	 *
	 * @param IUser $user The acting user.
	 */
	public function __construct(
		public readonly IUser $user,
	) {
	}//end __construct()
}//end class
