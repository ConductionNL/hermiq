<?php

/**
 * Hermiq MailMessageRead.
 *
 * One message being read: Mail's manager, account and client services, the message id and whether HTML was asked for.
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
 * One message read in progress; it never leaves MailReadService.
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
final class MailMessageRead {

	/**
	 * Mail's account service.
	 *
	 * @var object|null
	 */
	public ?object $accounts = null;

	/**
	 * Mail's IMAP client factory.
	 *
	 * @var object|null
	 */
	public ?object $clients = null;

	/**
	 * Whether the HTML body was asked for explicitly.
	 *
	 * @var bool
	 */
	public bool $includeHtml = false;

	/**
	 * The message, once found.
	 *
	 * @var object|null
	 */
	public ?object $message = null;

	/**
	 * Start the read.
	 *
	 * @param object $manager Mail's mailbox manager.
	 * @param int    $id      The message id.
	 */
	public function __construct(
		public readonly object $manager,
		public readonly int $id,
	) {
	}//end __construct()
}//end class
