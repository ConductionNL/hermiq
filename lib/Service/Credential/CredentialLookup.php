<?php

/**
 * Hermiq CredentialLookup.
 *
 * One run-time credential resolution: the provider of the turn and the actor
 * it is resolved for. CredentialScopeResolver builds it in `resolve()` and its
 * helpers read it; it never leaves that class.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Credential
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
 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Credential;

/**
 * The provider and actor of one credential resolution.
 *
 * @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
 */
final class CredentialLookup {

	/**
	 * Build the lookup.
	 *
	 * @param string $provider The provider of the turn.
	 * @param CredentialActor $actor Who the credential is resolved for.
	 */
	public function __construct(
		public readonly string $provider,
		public readonly CredentialActor $actor,
	) {
	}//end __construct()
}//end class
