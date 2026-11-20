<?php

/**
 * Hermiq PublicChannelGuardListener.
 *
 * Refuses, before it is saved, a public chat channel whose agent holds tool
 * grants or whose allowed origins are not origins, and gives a new channel
 * its random token. A schema rule cannot read the agent a channel points at,
 * so this is the save-guard seam (ADR-031).
 *
 * @category Listener
 * @package  OCA\Hermiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Listener;

use OCA\Hermiq\Service\PublicChat\PublicChannelStore;
use OCA\Hermiq\Service\PublicChat\PublicChatGate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public channel save guard.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */
class PublicChannelGuardListener implements IEventListener {

	/**
	 * What a channel on an agent with tool grants is refused with.
	 */
	public const REFUSED_TOOLS = 'A public assistant cannot use tools. Pick an agent without tools.';

	/**
	 * What a channel on an agent that does not exist is refused with.
	 */
	public const REFUSED_AGENT = 'Pick the agent that answers on this channel.';

	/**
	 * What a channel with an allowed origin that is not one is refused with.
	 */
	public const REFUSED_ORIGIN = 'An allowed origin is a scheme and a host, such as https://www.example.nl, without a path.';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper       $schemaMapper   Tells a channel from other objects.
	 * @param RegisterMapper     $registerMapper Tells hermiq's register from others.
	 * @param PublicChannelStore $store          Reads the channel's agent.
	 * @param PublicChatGate     $gate           Says whether an agent holds tool grants.
	 * @param ISecureRandom      $random         Mints the channel token.
	 * @param LoggerInterface    $logger         Records a refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly PublicChannelStore $store,
		private readonly PublicChatGate $gate,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check a channel about to be created or updated.
	 *
	 * @param Event $event The before-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		if ($event->isPropagationStopped() === true) {
			return;
		}

		$channel = $this->written(event: $event);
		if ($this->isChannel(object: $channel) === false) {
			return;
		}

		$data = array_merge($channel->getObject(), $event->getModifiedData());
		$refusal = $this->refusalOf(data: $data);
		if ($refusal !== null) {
			$event->setErrors([$refusal[0] => $refusal[1]]);
			$event->stopPropagation();
			$this->logger->info('[PublicChannelGuardListener] Refused a public chat channel: {message}', ['message' => $refusal[1]]);
			return;
		}

		if ($this->store->isToken(token: (string)($data['channelToken'] ?? '')) === false) {
			$event->setModifiedData(
				array_merge($event->getModifiedData(), ['channelToken' => $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC)])
			);
		}
	}//end handle()

	/**
	 * The field and sentence a channel is refused with, or null when it may be saved.
	 *
	 * @param array<string, mixed> $data The channel as it would be saved.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function refusalOf(array $data): ?array {
		$agentId = trim((string)($data['agentId'] ?? ''));
		$agent   = $this->store->agent(agentId: $agentId);
		if ($agent === null) {
			return ['agentId', self::REFUSED_AGENT];
		}

		if ($this->gate->agentHasTools(agent: $agent) === true) {
			return ['agentId', self::REFUSED_TOOLS];
		}

		$origins = array_filter((array)($data['allowedOrigins'] ?? []), static fn (mixed $origin): bool => $origin !== null && $origin !== '');
		if (count($this->store->allowedOrigins(channel: ['allowedOrigins' => $origins])) !== count($origins)) {
			return ['allowedOrigins', self::REFUSED_ORIGIN];
		}

		return null;
	}//end refusalOf()

	/**
	 * The object as it will be saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return ObjectEntity
	 */
	private function written(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end written()

	/**
	 * Whether an object is one of hermiq's public chat channels.
	 *
	 * The register check matters: a schema slug is not unique across the
	 * instance, so the slug alone could match another app's objects.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function isChannel(ObjectEntity $object): bool {
		$schema = (string)$object->getSchema();
		if ($schema === PublicChannelStore::CHANNEL_SCHEMA) {
			return true;
		}

		if ($schema === '' || ctype_digit($schema) === false) {
			return false;
		}

		try {
			return (string)$this->schemaMapper->find((int)$schema)->getSlug() === PublicChannelStore::CHANNEL_SCHEMA
				&& (string)$this->registerMapper->find((int)$object->getRegister())->getSlug() === PublicChannelStore::REGISTER;
		} catch (Throwable $e) {
			return false;
		}
	}//end isChannel()
}//end class
