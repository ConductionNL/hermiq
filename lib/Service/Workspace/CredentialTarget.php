<?php

/**
 * Hermiq CredentialTarget.
 *
 * Which credential to look up: the provider, and the organisation it may come from (null for personal only).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-mismatched-credential-owners-are-refused
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * One credential lookup's provider and organisation.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-mismatched-credential-owners-are-refused
 */
final class CredentialTarget {

	/**
	 * Build the target.
	 *
	 * @param string      $provider     The provider id.
	 * @param string|null $organisation The agent organisation, or null for personal only.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly ?string $organisation,
	) {
	}//end __construct()
}//end class
