<?php

/**
 * Hermiq DeliveryResult.
 *
 * Immutable value object describing the outcome of a single delivery attempt. It is
 * the return type of DeliveryService::deliver(): a delivery never throws, it reports
 * its outcome here so the dispatcher can record a warning (and persist
 * lastDeliveryError) without ever failing the run.
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
 * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Outcome of a delivery attempt.
 *
 * @spec openspec/changes/talk-delivery/tasks.md#1-deliveryservice-core
 */
class DeliveryResult {
	/**
	 * Constructor.
	 *
	 * @param bool $delivered Whether the output reached a channel.
	 * @param string $channel The channel actually used (talk|notification|none).
	 * @param bool $fellBack Whether a fallback was taken from the requested channel.
	 * @param string|null $warning A human-readable warning to record, or null on clean success.
	 */
	public function __construct(
		private readonly bool $delivered,
		private readonly string $channel,
		private readonly bool $fellBack,
		private readonly ?string $warning,
	) {
	}//end __construct()

	/**
	 * Whether the output was delivered to some channel.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/talk-delivery/tasks.md#task-1-2
	 */
	public function isDelivered(): bool {
		return $this->delivered;
	}//end isDelivered()

	/**
	 * The channel actually used.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/talk-delivery/tasks.md#task-1-2
	 */
	public function getChannel(): string {
		return $this->channel;
	}//end getChannel()

	/**
	 * Whether a fallback was taken from the originally requested channel.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/talk-delivery/tasks.md#task-1-2
	 */
	public function didFallBack(): bool {
		return $this->fellBack;
	}//end didFallBack()

	/**
	 * The warning to record on the schedule (lastDeliveryError), or null on clean success.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/talk-delivery/tasks.md#task-1-2
	 */
	public function getWarning(): ?string {
		return $this->warning;
	}//end getWarning()
}//end class
