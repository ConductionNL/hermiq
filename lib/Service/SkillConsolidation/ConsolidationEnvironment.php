<?php

/**
 * Hermiq ConsolidationEnvironment
 *
 * The Nextcloud configuration, users and links a consolidation reads.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\SkillConsolidation
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

namespace OCA\Hermiq\Service\SkillConsolidation;

use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The Nextcloud configuration, users and links a consolidation reads.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ConsolidationEnvironment {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Threshold configuration.
	 * @param IUserSession $userSession Owner impersonation around draft persistence.
	 * @param IUserManager $userManager Resolves the skill owner for impersonation.
	 * @param IURLGenerator $urlGenerator SkillDetail deep link for the Approval payload.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly IUserSession $userSession,
		public readonly IUserManager $userManager,
		public readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()
}//end class
