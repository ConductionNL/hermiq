<?php

/**
 * Hermiq RecipientNotice.
 *
 * What DeliveryService tells each recipient of one notice: how to fill in the
 * Nextcloud notification (object, subject, message, link) and, lazily, the
 * Talk Note-to-self text — built only when Talk is up, once per recipient.
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

use Closure;

/**
 * One notice to every recipient.
 *
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 */
final class RecipientNotice {

	/**
	 * Build the notice.
	 *
	 * @param Closure(\OCP\Notification\INotification): \OCP\Notification\INotification $configure Sets object, subject, message and link.
	 * @param Closure(): string $talkText The Note-to-self text.
	 */
	public function __construct(
		public readonly Closure $configure,
		public readonly Closure $talkText,
	) {
	}//end __construct()
}//end class
