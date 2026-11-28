<?php

/**
 * Hermiq AgentWaiverChangeListener.
 *
 * Records an approval waiver added or removed by any write to an agent: through
 * Hermiq's tool-grants endpoint, and through OpenRegister's generic objects API
 * (ADR-022: agents are OpenRegister objects, so the object events are the only
 * hook that sees every write). A draft agent raises the draft events instead,
 * and its owner can already run it, so those are audited too.
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

use OCA\Hermiq\Kernel\Contracts\AgentObjectMatcherInterface;
use OCA\Hermiq\Kernel\Contracts\GrantWaiverAuditInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\DraftCreatedEvent;
use OCA\OpenRegister\Event\DraftUpdatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the waiver audit event for every agent write.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-api
 *
 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
 */
class AgentWaiverChangeListener implements IEventListener {

	/**
	 * Uuids of agents whose save just left draft.
	 *
	 * Leaving draft raises DraftUpdatedEvent for the save and then
	 * ObjectCreatedEvent for the same object. The waivers were recorded on the
	 * draft path, so that created event is not recorded again. One listener
	 * instance serves every registration in a request.
	 *
	 * @var array<string, true>
	 */
	private array $leftDraft = [];

	/**
	 * Constructor.
	 *
	 * @param GrantWaiverAuditInterface $waiverAudit Writes the waiver audit event.
	 * @param AgentObjectMatcherInterface $agents Tells a Hermiq agent from other objects.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly GrantWaiverAuditInterface $waiverAudit,
		private readonly AgentObjectMatcherInterface $agents,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the waivers a created or updated agent, draft or not, gained or lost; other events are ignored.
	 *
	 * @param Event $event The dispatched OpenRegister object event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	public function handle(Event $event): void {
		try {
			[$agent, $before] = $this->written(event: $event);
			if ($agent === null || $this->agents->isAgent(object: $agent) === false || $this->announcesLeftDraft(event: $event, agent: $agent) === true) {
				return;
			}

			$this->waiverAudit->record(agent: $agent, before: $this->waiverAudit->waivers(tools: $before));
			if ($event instanceof DraftUpdatedEvent && $agent->isDraft() === false) {
				$this->leftDraft[(string)$agent->getUuid()] = true;
			}
		} catch (Throwable $e) {
			$this->logger->warning('[AgentWaiverChangeListener] agent write not audited: ' . $e->getMessage());
		}
	}//end handle()

	/**
	 * Whether this is the created event that follows an agent's save out of draft, already recorded.
	 *
	 * @param Event $event The dispatched event.
	 * @param ObjectEntity $agent The agent it carries.
	 *
	 * @return bool True for that created event; it is forgotten once seen.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	private function announcesLeftDraft(Event $event, ObjectEntity $agent): bool {
		$uuid = (string)$agent->getUuid();
		if ($event instanceof DraftCreatedEvent || isset($this->leftDraft[$uuid]) === false || ($event instanceof ObjectCreatedEvent) === false) {
			return false;
		}

		unset($this->leftDraft[$uuid]);
		return true;
	}//end announcesLeftDraft()

	/**
	 * The object a create or update stored, and its `tools` before the write.
	 *
	 * The draft events extend these two, so they are read the same way.
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
