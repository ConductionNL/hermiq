<?php

/**
 * Hermiq FlowRunTerminalListener.
 *
 * Closes the flow handoff of a run that OpenRegister persisted in a terminal
 * status (flows-hand-off-and-wait). Registered by event name, like
 * TaskTerminalListener, so hermiq boots on an OpenRegister without the event.
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
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
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\Handoff\FlowHandoffs;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Closes a flow handoff when its run ends.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
 */
class FlowRunTerminalListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param FlowHandoffs    $flowHandoffs Opens and closes flow handoffs.
	 * @param LoggerInterface $logger       PSR-3 logger.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
	 */
	public function __construct(
		private readonly FlowHandoffs $flowHandoffs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle OpenRegister's flow-run terminal event; any other event is ignored.
	 *
	 * Never throws: a failing close must not fail OpenRegister's run update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003
	 */
	public function handle(Event $event): void {
		if (is_a($event, FlowHandoffs::EVENT_CLASS) === false
			|| method_exists($event, 'getRunUuid') === false
			|| method_exists($event, 'getStatus') === false
		) {
			return;
		}

		try {
			$this->flowHandoffs->closeForRun(runUuid: (string) $event->getRunUuid(), status: (string) $event->getStatus());
		} catch (Throwable $e) {
			$this->logger->warning('[FlowRunTerminalListener] The flow handoff could not be closed: ' . $e->getMessage());
		}
	}//end handle()
}//end class
