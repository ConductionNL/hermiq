<?php

/**
 * Hermiq WebhookDelivery.
 *
 * One HMAC-authenticated webhook delivery as DeliveryService carries it through its
 * retries: the target URL and HMAC secret, the exact (redacted, size-capped) body the
 * HMAC covers and that is sent, the attempt and backoff bounds in force, the attempts made
 * so far with the last failure. Built by that service and never handed out of it.
 *
 * @category Service
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
 * @spec openspec/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One webhook delivery and its retries.
 *
 * @spec openspec/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp
 */
final class WebhookDelivery {

	/**
	 * The exact, final JSON body the HMAC covers and that is sent.
	 *
	 * @var string
	 */
	public string $body = '';

	/**
	 * The most attempts this delivery makes.
	 *
	 * @var int
	 */
	public int $maxAttempts = 1;

	/**
	 * The base of the exponential backoff between attempts, in seconds.
	 *
	 * @var int
	 */
	public int $backoffBase = 1;

	/**
	 * The attempts made so far.
	 *
	 * @var int
	 */
	public int $attempts = 0;

	/**
	 * Why the last attempt failed ('' when it succeeded or none was made).
	 *
	 * @var string
	 */
	public string $lastError = '';

	/**
	 * Start the delivery.
	 *
	 * @param string $url    The destination URL.
	 * @param string $secret The schedule's webhook HMAC secret.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $secret,
	) {
	}//end __construct()
}//end class
