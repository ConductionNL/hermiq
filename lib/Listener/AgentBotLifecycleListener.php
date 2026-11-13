<?php

/**
 * Hermiq AgentBotLifecycleListener.
 *
 * Keeps each Talk-enabled agent's Talk bot in step with the agent itself.
 *
 * Agents are OpenRegister objects written through OpenRegister's own API
 * (ADR-022 — apps consume OR abstractions rather than wrapping them in
 * pass-through CRUD controllers), so there is no Hermiq controller to hang this
 * off. The object lifecycle events are the only hook that sees every write,
 * including ones made from OR's own UI or by another app.
 *
 * 🔴 The name shown in Talk lives ONLY on the bot record. `MessageParser`
 * resolves it at READ time from `url_hash`, so:
 *
 * - renaming an agent re-signs its already-posted messages under the new name;
 * - removing its bot degrades that history to `<actorId>-bot`, because the
 *   lookup is scoped to the conversation and now misses.
 *
 * Both are spreed's model rather than something Hermiq chooses, and the second
 * is the accepted price of removing the bot when an agent is deleted.
 *
 * Never throws: an agent write must not fail because Talk is unavailable or
 * refused the bot. Every path degrades to "no bot", which is the same state as
 * an agent that was never Talk-enabled.
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
 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\Agent\AgentObjectMatcher;
use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Installs, renames and removes an agent's Talk bot alongside the agent.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-api
 *
 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
 */
class AgentBotLifecycleListener implements IEventListener {


	/**
	 * Constructor.
	 *
	 * @param TalkBotInstaller $installer Installs/renames/removes the per-agent bot.
	 * @param AgentObjectMatcher $agents Tells a Hermiq agent from other objects.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly TalkBotInstaller $installer,
		private readonly AgentObjectMatcher $agents,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an agent object lifecycle event.
	 *
	 * @param Event $event The dispatched OpenRegister object event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 */
	public function handle(Event $event): void {
		try {
			$this->apply(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[AgentBotLifecycleListener] Could not sync the agent bot (the agent write is unaffected)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}

	}//end handle()

	/**
	 * Route the event to install/rename or uninstall.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 */
	private function apply(Event $event): void {
		[$object, $deleted] = $this->resolve(event: $event);
		$agentId = $this->agentIdOf(object: $object);
		if ($agentId === null) {
			return;
		}

		// Talk-disabled, trashed and deleted are the same instruction to spreed:
		// this agent must stop being addressable. Disabling is not a soft state
		// that leaves a silent bot in the room.
		if ($deleted === true || $this->mustStopAnswering(agent: $object) === true) {
			$this->installer->uninstallForAgent(agentId: $agentId);
			return;
		}

		// Install and rename are one call — spreed's install is an upsert on
		// (url, secret), so re-dispatching with a new name IS the rename. That
		// also makes this safely idempotent on every unrelated agent update.
		$this->installer->installForAgent(
			agentId: $agentId,
			agentName: (string)(($object->getObject())['name'] ?? '')
		);

	}//end apply()

	/**
	 * The uuid of a Hermiq agent object, or null for anything else.
	 *
	 * @param mixed $object The event's object.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 */
	private function agentIdOf(mixed $object): ?string {
		if (($object instanceof ObjectEntity) === false || $this->agents->isAgent(object: $object) === false) {
			return null;
		}

		$agentId = (string)$object->getUuid();
		if ($agentId === '') {
			return null;
		}

		return $agentId;
	}//end agentIdOf()

	/**
	 * Whether an agent that still exists must stop answering: it is trashed, or Talk is off for it.
	 *
	 * 🔴 TRASHED counts as deleted, and this is not a nicety.
	 *
	 * OpenRegister's API delete is a SOFT delete: it does not dispatch
	 * ObjectDeletedEvent (only the hard `MagicMapper::delete()` does) — the
	 * object arrives here as an UPDATE carrying a `deleted` marker, still
	 * holding `talkEnabled: true`. Without this check the listener reads
	 * that as "an ordinary update" and cheerfully RE-INSTALLS the bot of an
	 * agent the user just threw away, leaving it answering in every room it
	 * was enabled in. Verified live: the bot survived the delete until this
	 * was added.
	 *
	 * @param ObjectEntity $agent The agent object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 */
	private function mustStopAnswering(ObjectEntity $agent): bool {
		if ($agent->getDeleted() !== null && $agent->getDeleted() !== []) {
			return true;
		}

		return ((($agent->getObject())['talkEnabled'] ?? false) === true) === false;
	}//end mustStopAnswering()


	/**
	 * Unwrap the object and whether this is a deletion.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: ObjectEntity|null, 1: bool} The object and the deleted flag.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 */
	private function resolve(Event $event): array {
		if ($event instanceof ObjectCreatedEvent) {
			return [$event->getObject(), false];
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return [$event->getNewObject(), false];
		}

		if ($event instanceof ObjectDeletedEvent) {
			return [$event->getObject(), true];
		}

		return [null, false];
	}//end resolve()
}//end class
