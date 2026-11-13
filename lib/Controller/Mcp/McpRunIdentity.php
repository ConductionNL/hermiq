<?php

/**
 * Hermiq McpRunIdentity
 *
 * What establishes who an MCP run acts as.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller\Mcp
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

namespace OCA\Hermiq\Controller\Mcp;

use OCA\Hermiq\Kernel\Contracts\RunTokenServiceInterface;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;

/**
 * What establishes who an MCP run acts as.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class McpRunIdentity {

	/**
	 * Constructor.
	 *
	 * @param RunTokenServiceInterface $runTokenService Verifies the per-run bearer token.
	 * @param IUserManager $userManager Resolves the token's user to an `IUser`.
	 * @param IUserSession $userSession Impersonates that user for RBAC on dispatch.
	 * @param IThrottler $throttler Brute-force protection for run-token authentication.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly RunTokenServiceInterface $runTokenService,
		public readonly IUserManager $userManager,
		public readonly IUserSession $userSession,
		public readonly IThrottler $throttler,
	) {
	}//end __construct()
}//end class
