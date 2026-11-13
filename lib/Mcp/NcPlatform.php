<?php

/**
 * Hermiq NcPlatform
 *
 * The Nextcloud platform services the native agent tools reach: files, contacts, calendars, mail and apps.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
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

namespace OCA\Hermiq\Mcp;

use OCP\App\IAppManager;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Files\IRootFolder;
use OCP\Mail\IMailer;

/**
 * The Nextcloud platform services the native agent tools reach: files, contacts, calendars, mail and apps.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class NcPlatform {

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Files root (scoped per user).
	 * @param IContactsManager $contactsManager Contacts search (acting user's books).
	 * @param ICalendarManager $calendarManager Calendar query (acting user's calendars).
	 * @param IMailer $mailer Outbound email.
	 * @param IAppManager $appManager App availability (Deck) + describe.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
		public readonly IContactsManager $contactsManager,
		public readonly ICalendarManager $calendarManager,
		public readonly IMailer $mailer,
		public readonly IAppManager $appManager,
	) {
	}//end __construct()
}//end class
