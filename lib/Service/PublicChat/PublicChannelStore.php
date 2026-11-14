<?php

/**
 * Hermiq PublicChannelStore.
 *
 * Reads public chat channels, their agents and their conversations, and
 * writes the conversations. Every read and write here is a system read: the
 * visitor is anonymous and may read none of these objects, and the channel is
 * found by the token it was given, never by anything the visitor names.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\PublicChat
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
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\PublicChat;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Channel, agent and conversation storage for public chat.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
class PublicChannelStore {

	/**
	 * The register and schemas.
	 */
	public const REGISTER = 'hermiq';
	public const CHANNEL_SCHEMA = 'agentpublicchannel';
	public const CONVERSATION_SCHEMA = 'agentpublicconversation';
	public const AGENT_SCHEMA = 'agent';

	/**
	 * The most turns a conversation keeps; older ones are dropped.
	 */
	public const MAX_TURNS = 40;

	/**
	 * The shape of a token: what the random generator emits, nothing else.
	 */
	private const TOKEN_PATTERN = '/^[A-Za-z0-9]{16,64}$/';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister objects, read and written as the system.
	 * @param ISecureRandom   $random        Mints conversation tokens.
	 * @param LoggerInterface $logger        Records failed reads and writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The website channel a public URL names, by its token.
	 *
	 * @param string $token The channel token from the URL.
	 *
	 * @return array<string, mixed>|null The channel with its `id`, or null when none.
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public function channelByToken(string $token): ?array {
		if (self::isToken(token: $token) === false) {
			return null;
		}

		$channel = $this->firstChannel(filters: ['channelToken' => $token]);
		if ($channel === null || ($channel['kind'] ?? 'website') !== 'website') {
			return null;
		}

		return $channel;
	}//end channelByToken()

	/**
	 * The app channel a sibling app asks by, by its key.
	 *
	 * @param string $key The channel key, for example `app:portaliq:wonen`.
	 *
	 * @return array<string, mixed>|null The channel with its `id`, or null when none.
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-sibling-apps-reach-the-same-channel-in-process-without-an-identity-req-pubchat-004
	 */
	public function channelByKey(string $key): ?array {
		$key = trim($key);
		if ($key === '') {
			return null;
		}

		$channel = $this->firstChannel(filters: ['channelKey' => $key]);
		if ($channel === null || ($channel['kind'] ?? '') !== 'app') {
			return null;
		}

		return $channel;
	}//end channelByKey()

	/**
	 * The agent a channel answers with.
	 *
	 * @param string $agentId The agent's id.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function agent(string $agentId): ?ObjectEntity {
		if ($agentId === '') {
			return null;
		}

		try {
			$agent = $this->objectService->find(
				id: $agentId,
				register: self::REGISTER,
				schema: self::AGENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PublicChannelStore] The agent of a public channel could not be read',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (($agent instanceof ObjectEntity) === false) {
			return null;
		}

		return $agent;
	}//end agent()

	/**
	 * The earlier turns of a conversation on a channel.
	 *
	 * A token that belongs to another channel reads as a new conversation.
	 *
	 * @param string      $channelId The channel.
	 * @param string|null $token     The visitor's conversation token, or null.
	 *
	 * @return array{id: string|null, token: string, turns: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function conversation(string $channelId, ?string $token): array {
		$token = (string)$token;
		if (self::isToken(token: $token) === true) {
			$rows = $this->read(
				schema: self::CONVERSATION_SCHEMA,
				filters: ['conversationToken' => $token, 'channelId' => $channelId]
			);
			foreach ($rows as $row) {
				if (($row['channelId'] ?? null) === $channelId && ($row['conversationToken'] ?? null) === $token) {
					return ['id' => (string)$row['id'], 'token' => $token, 'turns' => array_values((array)($row['turns'] ?? []))];
				}
			}
		}

		return ['id' => null, 'token' => $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC), 'turns' => []];
	}//end conversation()

	/**
	 * Keep a conversation's turns, at most MAX_TURNS of them, with no identity and no address.
	 *
	 * @param array<string, mixed>                                              $channel      The channel.
	 * @param array{id: string|null, token: string, turns: list<array<string, mixed>>} $conversation The conversation with its new turns.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function saveConversation(array $channel, array $conversation): void {
		$data = [
			'channelId'         => (string)($channel['id'] ?? ''),
			'conversationToken' => $conversation['token'],
			'turns'             => array_slice($conversation['turns'], -self::MAX_TURNS),
			'tenantId'          => (string)($channel['tenantId'] ?? ''),
		];

		try {
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::CONVERSATION_SCHEMA,
				uuid: $conversation['id'],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// The answer was given; losing the history costs the visitor context, nothing more.
			$this->logger->warning(
				message: '[PublicChannelStore] A public conversation could not be kept',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}
	}//end saveConversation()

	/**
	 * Whether a string has the shape of a token this app mints.
	 *
	 * @param string $token The candidate.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-the-public-endpoint-is-limited-and-origin-bound-req-pubchat-003
	 */
	public static function isToken(string $token): bool {
		return preg_match(self::TOKEN_PATTERN, $token) === 1;
	}//end isToken()

	/**
	 * The first channel matching the filters, checked again against them.
	 *
	 * @param array<string, string> $filters The filters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function firstChannel(array $filters): ?array {
		foreach ($this->read(schema: self::CHANNEL_SCHEMA, filters: $filters) as $row) {
			$matches = true;
			foreach ($filters as $key => $value) {
				$matches = ($matches === true && ($row[$key] ?? null) === $value);
			}

			if ($matches === true) {
				return $row;
			}
		}

		return null;
	}//end firstChannel()

	/**
	 * Read objects of a schema as the system, as arrays with their `id`.
	 *
	 * @param string                $schema  The schema.
	 * @param array<string, string> $filters The filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function read(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister(self::REGISTER)
				->setSchema($schema)
				->findAll(config: ['filters' => $filters, 'limit' => 5], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PublicChannelStore] A public chat read failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'schema' => $schema, 'error' => $e->getMessage()]
			);
			return [];
		}

		$arrays = [];
		foreach ((array)($rows['results'] ?? $rows) as $row) {
			if ($row instanceof ObjectEntity) {
				$data = $row->getObject();
				$row  = array_merge($data, ['id' => (string)$row->getUuid(), 'tenantId' => (string)($data['tenantId'] ?? $row->getOrganisation() ?? '')]);
			}

			if (is_array($row) === true) {
				$arrays[] = $row;
			}
		}

		return $arrays;
	}//end read()
}//end class
