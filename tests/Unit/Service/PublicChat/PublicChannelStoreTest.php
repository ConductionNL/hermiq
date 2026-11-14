<?php

/**
 * Public chat storage: channels by token or key, agents and conversations, all as the system and with no identity.
 *
 * Pins delivery-public-web-chat task 3 (REQ-PUBCHAT-002).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\PublicChat
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

namespace OCA\Hermiq\Tests\Unit\Service\PublicChat;

use OCA\Hermiq\Service\PublicChat\PublicChannelStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\PublicChat\PublicChannelStore
 */
class PublicChannelStoreTest extends TestCase {

	/**
	 * Every findAll: schema, config, rbac, multitenancy.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * Every saveObject's arguments.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * The store over a register of rows by schema.
	 *
	 * @param array<string, list<mixed>> $rows     Rows by schema.
	 * @param bool                       $failSave Whether saving throws.
	 *
	 * @return PublicChannelStore
	 */
	private function store(array $rows, bool $failSave=false): PublicChannelStore {
		$schema  = '';
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(function (mixed $s) use (&$schema, $objects): ObjectService {
			$schema = (string)$s;
			return $objects;
		});
		$objects->method('findAll')->willReturnCallback(function (array $config, bool $_rbac=true, bool $_multitenancy=true) use (&$schema, $rows): array {
			$this->reads[] = ['schema' => $schema, 'config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
			return ($rows[$schema] ?? []);
		});
		$objects->method('find')->willReturnCallback(function (int|string $id, ...$rest): ?ObjectEntity {
			if ($id === 'boom') {
				throw new RuntimeException('down');
			}

			if ($id !== 'agent-1') {
				return null;
			}

			$agent = new ObjectEntity();
			$agent->setUuid('agent-1');
			return $agent;
		});
		$objects->method('saveObject')->willReturnCallback(function (...$args) use ($failSave): ObjectEntity {
			if ($failSave === true) {
				throw new RuntimeException('disk full');
			}

			$this->writes[] = ['object' => $args[0], 'register' => $args[2], 'schema' => $args[3], 'uuid' => $args[4], '_rbac' => $args[5], '_multitenancy' => $args[6]];
			return new ObjectEntity();
		});

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->with(32, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('Fresh0123456789Fresh0123456789ab');

		return new PublicChannelStore($objects, $random, $this->createMock(LoggerInterface::class));
	}//end store()

	/**
	 * A website channel is found by its token, as the system; a malformed token reads nothing.
	 *
	 * @return void
	 */
	public function testAWebsiteChannelIsFoundByItsToken(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('ch-1');
		$entity->setOrganisation('org-1');
		$entity->setObject(['kind' => 'website', 'channelToken' => 'Tok0123456789abcdef', 'enabled' => true]);
		$store = $this->store(['agentpublicchannel' => [$entity]]);

		self::assertSame(
			['id' => 'ch-1', 'kind' => 'website', 'channelToken' => 'Tok0123456789abcdef', 'enabled' => true, 'tenantId' => 'org-1'],
			$store->channelByToken(token: 'Tok0123456789abcdef')
		);
		self::assertSame(['schema' => 'agentpublicchannel', 'config' => ['filters' => ['channelToken' => 'Tok0123456789abcdef'], 'limit' => 5], 'rbac' => false, 'multitenancy' => false], $this->reads[0]);

		self::assertNull($store->channelByToken(token: 'x'));
		self::assertNull($store->channelByToken(token: "Tok0123456789abcdef' OR 1"));
		self::assertCount(1, $this->reads);
	}//end testAWebsiteChannelIsFoundByItsToken()

	/**
	 * A row that does not match the filter, or is of the other kind, is not the channel.
	 *
	 * @return void
	 */
	public function testOnlyAMatchingChannelOfTheRightKindIsReturned(): void {
		$store = $this->store([
			'agentpublicchannel' => [
				['id' => 'a', 'kind' => 'website', 'channelToken' => 'Other0123456789abcdef'],
				['id' => 'b', 'kind' => 'app', 'channelKey' => 'app:portaliq:wonen'],
			],
		]);

		self::assertNull($store->channelByToken(token: 'Tok0123456789abcdef'));
		self::assertSame('b', $store->channelByKey(key: ' app:portaliq:wonen ')['id']);
		self::assertNull($store->channelByKey(key: ''));

		$websiteKey = $this->store(['agentpublicchannel' => [['id' => 'c', 'kind' => 'website', 'channelKey' => 'app:x']]]);
		self::assertNull($websiteKey->channelByKey(key: 'app:x'));
		$appToken = $this->store(['agentpublicchannel' => [['id' => 'd', 'kind' => 'app', 'channelToken' => 'Tok0123456789abcdef']]]);
		self::assertNull($appToken->channelByToken(token: 'Tok0123456789abcdef'));
	}//end testOnlyAMatchingChannelOfTheRightKindIsReturned()

	/**
	 * The agent is read as the system; a missing or unreadable one is null.
	 *
	 * @return void
	 */
	public function testTheAgentIsReadAsTheSystem(): void {
		$store = $this->store([]);

		self::assertSame('agent-1', $store->agent(agentId: 'agent-1')?->getUuid());
		self::assertNull($store->agent(agentId: 'other'));
		self::assertNull($store->agent(agentId: 'boom'));
		self::assertNull($store->agent(agentId: ''));
	}//end testTheAgentIsReadAsTheSystem()

	/**
	 * A known token continues its conversation on its own channel only; otherwise a new token is minted.
	 *
	 * @return void
	 */
	public function testAConversationContinuesOnlyOnItsChannel(): void {
		$store = $this->store([
			'agentpublicconversation' => [
				['id' => 'conv-1', 'channelId' => 'ch-1', 'conversationToken' => 'Conv0123456789abcdef', 'turns' => [['role' => 'user', 'content' => 'Hi']]],
			],
		]);

		self::assertSame(['id' => 'conv-1', 'token' => 'Conv0123456789abcdef', 'turns' => [['role' => 'user', 'content' => 'Hi']]], $store->conversation(channelId: 'ch-1', token: 'Conv0123456789abcdef'));
		self::assertSame(['id' => null, 'token' => 'Fresh0123456789Fresh0123456789ab', 'turns' => []], $store->conversation(channelId: 'ch-2', token: 'Conv0123456789abcdef'));
		self::assertSame(['id' => null, 'token' => 'Fresh0123456789Fresh0123456789ab', 'turns' => []], $store->conversation(channelId: 'ch-1', token: null));
	}//end testAConversationContinuesOnlyOnItsChannel()

	/**
	 * A conversation is kept as the system, with its last turns only and nothing about the visitor.
	 *
	 * @return void
	 */
	public function testAConversationIsKeptWithoutIdentity(): void {
		$turns = array_map(static fn (int $n): array => ['role' => 'user', 'content' => 'q' . $n], range(1, 45));
		$this->store([])->saveConversation(channel: ['id' => 'ch-1', 'tenantId' => 'org-1'], conversation: ['id' => 'conv-1', 'token' => 'Conv0123456789abcdef', 'turns' => $turns]);

		$args = $this->writes[0];
		self::assertSame(['channelId', 'conversationToken', 'turns', 'tenantId'], array_keys($args['object']));
		self::assertCount(40, $args['object']['turns']);
		self::assertSame('q6', $args['object']['turns'][0]['content']);
		self::assertSame('agentpublicconversation', $args['schema']);
		self::assertSame('conv-1', $args['uuid']);
		self::assertFalse($args['_rbac']);
		self::assertFalse($args['_multitenancy']);

		// A failed save is logged, not thrown: the answer was already given.
		$this->store([], failSave: true)->saveConversation(channel: ['id' => 'ch-1'], conversation: ['id' => null, 'token' => 't', 'turns' => []]);
		self::assertCount(1, $this->writes);
	}//end testAConversationIsKeptWithoutIdentity()

	/**
	 * Only tokens of the minted shape are tokens.
	 *
	 * @return void
	 */
	public function testIsToken(): void {
		$store = $this->store([]);
		self::assertTrue($store->isToken(token: 'abcDEF0123456789'));
		self::assertFalse($store->isToken(token: 'short'));
		self::assertFalse($store->isToken(token: 'abc-DEF0123456789'));
		self::assertFalse($store->isToken(token: str_repeat('a', 65)));
	}//end testIsToken()

	/**
	 * Allowed origins are exact origins: scheme and host, no path, case and trailing slash ignored.
	 *
	 * @return void
	 */
	public function testAllowedOrigins(): void {
		self::assertSame(
			['https://www.example.nl', 'http://localhost:8080'],
			$this->store([])->allowedOrigins(channel: ['allowedOrigins' => ['https://WWW.example.nl/', 'http://localhost:8080', 'https://x.nl/path', 'ftp://x.nl', '*', null]])
		);
		self::assertSame([], $this->store([])->allowedOrigins(channel: []));
	}//end testAllowedOrigins()
}//end class
