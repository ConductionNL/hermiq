<?php

/**
 * Hermiq TalkPost.
 *
 * One message DeliveryService posts into a Talk room as a user: who posts it and
 * what it says. The poster must be a member of the room it lands in.
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
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One Talk message and its poster.
 *
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 */
final class TalkPost {

	/**
	 * Address the message.
	 *
	 * @param string $owner   The UID the message is posted as (the actor).
	 * @param string $message The message body.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $message,
	) {
	}//end __construct()
}//end class
