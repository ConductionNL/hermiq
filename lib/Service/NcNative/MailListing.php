<?php

/**
 * Hermiq MailListing.
 *
 * One page of a mailbox being listed: Mail's manager and message services, the mailbox and the clamped page.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

/**
 * One mailbox page listing in progress; it never leaves MailReadService.
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
final class MailListing {

	/**
	 * The mailbox id asked for.
	 *
	 * @var int
	 */
	public int $mailboxId = 0;

	/**
	 * The page, from 1.
	 *
	 * @var int
	 */
	public int $page = 1;

	/**
	 * The page size, clamped to the server maximum.
	 *
	 * @var int
	 */
	public int $pageSize = 1;

	/**
	 * Start the listing.
	 *
	 * @param object $manager  Mail's mailbox manager.
	 * @param object $messages Mail's message mapper.
	 */
	public function __construct(
		public readonly object $manager,
		public readonly object $messages,
	) {
	}//end __construct()
}//end class
