<?php

/**
 * Hermiq PackagePayload.
 *
 * What a single package publish commits: the serialized package and the
 * already-selected auxiliary repo-root files (`{name, content}` entries).
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One package and its auxiliary files.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */
final class PackagePayload {

	/**
	 * Build the payload.
	 *
	 * @param string $package The serialized package string.
	 * @param array<int, mixed> $auxFiles The auxiliary repo-root files.
	 */
	public function __construct(
		public readonly string $package,
		public readonly array $auxFiles,
	) {
	}//end __construct()
}//end class
