<?php

/**
 * Hermiq ArchiveWorkspace
 *
 * The two temporary paths one GitHub archive extraction stages into.
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
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Where the archive bytes are staged, and the directory they are unpacked into.
 *
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
 */
final class ArchiveWorkspace {

	/**
	 * Build the workspace.
	 *
	 * @param string $archivePath Where the archive bytes are staged.
	 * @param string $extractDir  The directory the archive is unpacked into.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
	 */
	public function __construct(
		public readonly string $archivePath,
		public readonly string $extractDir,
	) {
	}//end __construct()
}//end class
