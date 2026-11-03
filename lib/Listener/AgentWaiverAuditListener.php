<?php

/**
 * Hermiq AgentWaiverAuditListener.
 *
 * Records an approval waiver added or removed by any write to an agent, also one
 * made through OpenRegister's generic objects API (ADR-022: agents are
 * OpenRegister objects, so the object events are the only hook that sees every
 * write). Hermiq's own tool-grants endpoint records its write itself and claims
 * the agent while it saves; that write is skipped here.
 *
 * Never throws: an agent write must not fail because its audit could not be written.
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
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\Agent\AgentObjectMatcher;
use OCA\Hermiq\Service\Agent\GrantWaiverAudit;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the waiver audit event for agent writes made outside the tool-grants endpoint.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-api
 *
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
 */
class AgentWaiverAuditListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param GrantWaiverAudit $waiverAudit Writes the waiver audit event.
	 * @param AgentObjectMatcher $agents Tells a Hermiq agent from other objects.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly GrantWaiverAudit $waiverAudit,
		private readonly AgentObjectMatcher $agents,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object created or updated event.
	 *
	 * @param Event $event The dispatched OpenRegister object event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	public function handle(Event $event): void {
		try {
			$this->apply(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[AgentWaiverAuditListener] Could not audit the agent write (the write is unaffected)',
				context: ['error' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Record the waivers the write moved, unless it is not an agent or is claimed.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	private function apply(Event $event): void {
		[$agent, $before] = $this->written(event: $event);
		if ($agent === null
			|| $this->agents->isAgent(object: $agent) === false
			|| $this->waiverAudit->isClaimed(agentId: (string)$agent->getUuid()) === true
		) {
			return;
		}

		$this->waiverAudit->record(agent: $agent, before: $this->waiverAudit->waivers(tools: $before));

	}//end apply()

	/**
	 * The object a create or update stored, and its `tools` before the write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: ObjectEntity|null, 1: mixed} The stored object (null for other events) and the previous tools.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	private function written(Event $event): array {
		if ($event instanceof ObjectUpdatedEvent) {
			return [$event->getNewObject(), ($event->getOldObject()?->getObject()['tools'] ?? [])];
		}

		if ($event instanceof ObjectCreatedEvent) {
			return [$event->getObject(), []];
		}

		return [null, []];
	}//end written()
}//end class
