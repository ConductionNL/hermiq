<?php

/**
 * Hermiq BundleKind
 *
 * One kind of bundle entry (skill or agent): its name in messages, its cap, and how a declared entry is loaded.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Closure;

/**
 * A bundle entry kind.
 *
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */
final class BundleKind {

	/**
	 * Build the kind.
	 *
	 * @param string       $name The kind's name in rejection messages (`skill`, `agent`).
	 * @param int          $cap  The most entries of this kind one bundle carries.
	 * @param Closure|null $load Loads a declared entry by its safe name (null when only writing).
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function __construct(
		public readonly string $name,
		public readonly int $cap,
		public readonly ?Closure $load = null,
	) {
	}//end __construct()
}//end class
