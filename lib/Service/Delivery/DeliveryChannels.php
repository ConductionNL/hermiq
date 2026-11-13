<?php

/**
 * Hermiq DeliveryChannels
 *
 * The channels a result is delivered through: notifications, Talk, mail and HTTP.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Delivery
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

namespace OCA\Hermiq\Service\Delivery;

use OCP\Http\Client\IClientService;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Talk\IBroker;

/**
 * The channels a result is delivered through: notifications, Talk, mail and HTTP.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class DeliveryChannels {

	/**
	 * Constructor.
	 *
	 * @param INotificationManager $notificationManager Raises Nextcloud notifications (baseline + fallback).
	 * @param IBroker $talkBroker Core Talk availability probe (hasBackend()).
	 * @param IMailer $mailer Sends `deliver=email` output (delivery-channels) — never a bespoke SMTP
	 *                        client.
	 * @param IClientService $clientService Sends `deliver=webhook` POSTs (delivery-channels) — never a bespoke HTTP
	 *                                      client.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly INotificationManager $notificationManager,
		public readonly IBroker $talkBroker,
		public readonly IMailer $mailer,
		public readonly IClientService $clientService,
	) {
	}//end __construct()
}//end class
