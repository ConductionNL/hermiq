<?php

/**
 * Hermiq ScheduleAlert.
 *
 * One proactive alert to a schedule's owner (dead letter, circuit breaker):
 * the channel its degraded delivery is logged under, and the notice it sends,
 * built from the schedule's UUID and name.
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
 * One alert to a schedule's owner.
 *
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 */
final class ScheduleAlert {

	/**
	 * Build the alert.
	 *
	 * @param string $logChannel The channel a degraded delivery is logged under.
	 * @param Closure(string, string): RecipientNotice $notice The notice for a schedule UUID and name.
	 */
	public function __construct(
		public readonly string $logChannel,
		public readonly Closure $notice,
	) {
	}//end __construct()
}//end class
