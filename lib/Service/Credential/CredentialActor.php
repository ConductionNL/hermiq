<?php

/**
 * Hermiq CredentialActor.
 *
 * Who a credential is being resolved for: the acting user and the agent's
 * organisation, either of which may be absent. A personal credential is only
 * ever the acting user's own; an organisation credential only ever the agent's
 * organisation's.
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
 * @spec openspec/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Credential;

/**
 * The acting user and organisation of one credential resolution.
 *
 * @spec openspec/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
 */
final class CredentialActor {

	/**
	 * Build the actor.
	 *
	 * @param string|null $userId The acting user's uid, null/'' when there is none.
	 * @param string|null $organisation The agent's organisation, null/'' when there is none.
	 */
	public function __construct(
		public readonly ?string $userId,
		public readonly ?string $organisation,
	) {
	}//end __construct()

	/**
	 * Whether there is an acting user to match a personal credential against.
	 *
	 * @return bool True for a non-empty uid.
	 *
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 */
	public function hasUser(): bool {
		return ($this->userId ?? '') !== '';
	}//end hasUser()

	/**
	 * Whether there is an organisation to match an organisation credential against.
	 *
	 * @return bool True for a non-empty organisation.
	 *
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-run-time-credential-resolution-precedence
	 */
	public function hasOrganisation(): bool {
		return ($this->organisation ?? '') !== '';
	}//end hasOrganisation()
}//end class
