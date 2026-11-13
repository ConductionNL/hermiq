<?php

/**
 * Hermiq ScheduleEnvironment
 *
 * The Nextcloud users and configuration a scheduled run reads.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
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

namespace OCA\Hermiq\Service\Schedule;

use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The Nextcloud users and configuration a scheduled run reads.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ScheduleEnvironment {

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Session used to impersonate the schedule owner.
	 * @param IUserManager $userManager Resolves the owner UID to an IUser.
	 * @param IConfig $config Reads owner/instance timezone.
	 * @param IAppConfig $appConfig Reads the `hermiq`.`engine.enabled` feature flag (agent-engine-port).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IUserManager $userManager,
		public readonly IConfig $config,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
