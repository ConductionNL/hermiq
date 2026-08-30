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
 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
 */
class AgentBotLifecycleListener implements IEventListener {

	/**
	 * Schema slug for agents.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Register slug that owns Hermiq's agents.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Constructor.
	 *
	 * @param TalkBotInstaller $installer Installs/renames/removes the per-agent bot.
	 * @param SchemaMapper $schemaMapper Resolves the object's schema slug.
	 * @param RegisterMapper $registerMapper Resolves the object's register slug.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly TalkBotInstaller $installer,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
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
	 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
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
	 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 */
	private function apply(Event $event): void {
		[$object, $deleted] = $this->resolve(event: $event);
		if (($object instanceof ObjectEntity) === false) {
			return;
		}

		if ($this->isHermiqAgent(object: $object) === false) {
			return;
		}

		$agentId = (string)$object->getUuid();
		if ($agentId === '') {
			return;
		}

		$data = $object->getObject();

		// 🔴 TRASHED counts as deleted, and this is not a nicety.
		//
		// OpenRegister's API delete is a SOFT delete: it does not dispatch
		// ObjectDeletedEvent (only the hard `MagicMapper::delete()` does) — the
		// object arrives here as an UPDATE carrying a `deleted` marker, still
		// holding `talkEnabled: true`. Without this check the listener reads
		// that as "an ordinary update" and cheerfully RE-INSTALLS the bot of an
		// agent the user just threw away, leaving it answering in every room it
		// was enabled in. Verified live: the bot survived the delete until this
		// was added.
		$trashed = ($object->getDeleted() !== null && $object->getDeleted() !== []);

		// Talk-disabled, trashed and deleted are the same instruction to spreed:
		// this agent must stop being addressable. Disabling is not a soft state
		// that leaves a silent bot in the room.
		if ($deleted === true || $trashed === true || (($data['talkEnabled'] ?? false) === true) === false) {
			$this->installer->uninstallForAgent(agentId: $agentId);
			return;
		}

		// Install and rename are one call — spreed's install is an upsert on
		// (url, secret), so re-dispatching with a new name IS the rename. That
		// also makes this safely idempotent on every unrelated agent update.
		$this->installer->installForAgent(
			agentId: $agentId,
			agentName: (string)($data['name'] ?? '')
		);

	}//end apply()

	/**
	 * Whether an object is one of Hermiq's agents.
	 *
	 * 🔴 `ObjectEntity::getSchema()` returns the schema's NUMERIC ID, not its
	 * slug — an agent arrives as `'4365'`, never `'agent'`. Comparing it to the
	 * slug is a check that can only ever be false, and it fails in the worst
	 * possible way: silently, on every event, with the listener firing exactly
	 * as designed and simply declining to act. Verified live; the id is
	 * instance-specific, so it cannot be hard-coded either.
	 *
	 * Resolved per call rather than cached: this listener is constructed per
	 * request, schemas can be re-imported mid-life, and the mappers cache.
	 *
	 * @param ObjectEntity $object The object the event carries.
	 *
	 * @return bool True when this is a Hermiq agent object.
	 *
	 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
	 */
	private function isHermiqAgent(ObjectEntity $object): bool {
		$schema = (string)$object->getSchema();
		if ($schema === '') {
			return false;
		}

		// Slug, when a caller hands one over instead of an id.
		if ($schema === self::AGENT_SCHEMA) {
			return true;
		}

		try {
			if ((string)$this->schemaMapper->find((int)$schema)->getSlug() !== self::AGENT_SCHEMA) {
				return false;
			}

			// 🔴 The register check is NOT redundant. Schema slugs are not
			// unique across the instance: this fleet has TWO schemas slugged
			// `agent` (hermiq's and another app's), exactly as it has two
			// slugged `conversation`. Matching on the slug alone would install
			// a Hermiq Talk bot for another app's objects.
			return ((string)$this->registerMapper->find((int)$object->getRegister())->getSlug() === self::REGISTER_SLUG);
		} catch (Throwable $e) {
			return false;
		}

	}//end isHermiqAgent()

	/**
	 * Unwrap the object and whether this is a deletion.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: ObjectEntity|null, 1: bool} The object and the deleted flag.
	 *
	 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
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
