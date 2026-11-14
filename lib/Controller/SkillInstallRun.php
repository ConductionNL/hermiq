<?php

/**
 * Hermiq SkillInstallRun.
 *
 * One install of a skill package into quarantine, as
 * SkillMarketplaceController::installFromSource() carries it through its
 * steps: the package and the requested source, then the auxiliary files once
 * read. Built by that method and never handed out of the controller.
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
 * @spec openspec/changes/archive/2026-09-06-skills-marketplace/tasks.md#4-controller-routes
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

/**
 * The state of one skill install from a source.
 *
 * @spec openspec/changes/archive/2026-09-06-skills-marketplace/tasks.md#4-controller-routes
 */
final class SkillInstallRun {

	/**
	 * The auxiliary files to install with the package, once read.
	 *
	 * @var array<mixed>
	 */
	public array $auxFiles = [];

	/**
	 * Start the run.
	 *
	 * @param string $package The skill package.
	 * @param string $source The requested source, as given.
	 */
	public function __construct(
		public readonly string $package,
		public readonly string $source,
	) {
	}//end __construct()

	/**
	 * The provenance to install with: `local`, `org` or `hub`, `hub` for anything else.
	 *
	 * Hermiq-skill-conversational-authoring: 'local' is the honest provenance for a
	 * skill authored inside this instance (the chat "Save as skill" seam) — an
	 * already-valid `source` enum value, no schema change. installFromSource() still
	 * ALWAYS lands the skill `quarantined` regardless of source.
	 *
	 * @return string The source.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-marketplace/tasks.md#4-controller-routes
	 */
	public function installSource(): string {
		if (in_array($this->source, ['local', 'org', 'hub'], true) === false) {
			return 'hub';
		}

		return $this->source;
	}//end installSource()
}//end class
