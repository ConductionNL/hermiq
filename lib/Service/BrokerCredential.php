<?php

/**
 * Hermiq BrokerCredential.
 *
 * The OpenRegister broker credential a GitHub call is made with: its UUID (never
 * the secret behind it, which this app cannot read) and the user who owns it.
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
 * One broker credential and its owner.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */
final class BrokerCredential {

	/**
	 * Build the credential reference.
	 *
	 * @param string $credentialId Broker credential UUID for a `github` provider credential.
	 * @param string|null $actingUserId Credential owner; required when there is no user session.
	 */
	public function __construct(
		public readonly string $credentialId,
		public readonly ?string $actingUserId,
	) {
	}//end __construct()
}//end class
