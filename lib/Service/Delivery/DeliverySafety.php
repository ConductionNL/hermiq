<?php

/**
 * Hermiq DeliverySafety
 *
 * What keeps a delivery safe and observable: redaction, webhook secrets and connection reports.
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

use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleWebhookSecretService;

/**
 * What keeps a delivery safe and observable: redaction, webhook secrets and connection reports.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class DeliverySafety {

	/**
	 * Constructor.
	 *
	 * @param RedactionService $redactionService Redacts output before it crosses the instance boundary (email/webhook only).
	 * @param ScheduleWebhookSecretService $scheduleWebhookSecretService Retrieves a schedule's outbound webhook signing secret (delivery-channels).
	 * @param ConnectionReporter|null $connectionReporter Tells integriq's connection registry what a
	 *                                                    webhook delivery met; null when built by hand
	 *                                                    in a test (adopt-connection-registry).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly RedactionService $redactionService,
		public readonly ScheduleWebhookSecretService $scheduleWebhookSecretService,
		public readonly ?ConnectionReporter $connectionReporter = null,
	) {
	}//end __construct()
}//end class
