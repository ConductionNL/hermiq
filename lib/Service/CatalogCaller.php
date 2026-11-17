<?php

/**
 * Hermiq CatalogCaller.
 *
 * Who a GitHub catalogue read is made for: the session user the credential broker
 * owner-guards the call to, and the allowed `github` credential that upgrades it.
 * Without a credential the read is anonymous.
 *
 * @category ValueObject
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
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * The broker identity of one catalogue read.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 */
final class CatalogCaller {

	/**
	 * Name the caller.
	 *
	 * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
	 * @param string|null $credentialId The allowed `github` credential, or null/'' to read anonymously.
	 */
	public function __construct(
		public readonly ?string $actingUserId,
		public readonly ?string $credentialId,
	) {
	}//end __construct()

	/**
	 * Whether the read carries a credential for the broker.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	public function hasCredential(): bool {
		return ($this->credentialId !== null && $this->credentialId !== '');
	}//end hasCredential()
}//end class
