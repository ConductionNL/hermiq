<?php

/**
 * Hermiq ScheduleDelivery.
 *
 * One delivery of a schedule run's output as DeliveryService carries it through
 * the channel and its fallbacks: the schedule, the output, and the conversation
 * the run produced so a Talk room delivery can bind it. Built by that service
 * and never handed out of it.
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
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One schedule output delivery.
 *
 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
 */
final class ScheduleDelivery {

	/**
	 * The conversation this run produced, when the caller knows it; bound to the
	 * Talk room the output reaches (talk-chat-bridge). Null leaves binding a no-op.
	 *
	 * @var string|null
	 */
	public ?string $conversationUuid = null;

	/**
	 * Start the delivery.
	 *
	 * @param ObjectEntity $schedule The schedule the output belongs to.
	 * @param string       $output   The agent output to deliver.
	 */
	public function __construct(
		public readonly ObjectEntity $schedule,
		public readonly string $output,
	) {
	}//end __construct()

	/**
	 * The schedule UUID.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	public function uuid(): string {
		return (string)$this->schedule->getUuid();
	}//end uuid()

	/**
	 * The schedule owner UID.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	public function owner(): string {
		return (string)($this->schedule->getOwner() ?? '');
	}//end owner()

	/**
	 * The schedule's configured delivery target, trimmed ('' for none).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	public function target(): string {
		return trim((string)($this->schedule->getObject()['deliverTarget'] ?? ''));
	}//end target()

	/**
	 * The output as a Talk message posted as the schedule owner.
	 *
	 * @return TalkPost
	 *
	 * @spec openspec/changes/archive/2026-09-06-talk-delivery/tasks.md#1-deliveryservice-core
	 */
	public function talkPost(): TalkPost {
		return new TalkPost(owner: $this->owner(), message: $this->output);
	}//end talkPost()
}//end class
