<?php

/**
 * Hermiq PullRequester
 *
 * Who pulls an agent from git, and with which credential.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

/**
 * Who pulls an agent from git, and with which credential.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class PullRequester {

	/**
	 * Constructor.
	 *
	 * @param string $uid The caller.
	 * @param string $credentialId The caller's GitHub credential.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly string $credentialId,
	) {
	}//end __construct()
}//end class
