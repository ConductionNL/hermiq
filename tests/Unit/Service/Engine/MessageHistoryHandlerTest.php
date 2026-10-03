<?php

/**
 * Unit tests for MessageHistoryHandler (agent-engine-port).
 *
 * Covers Message persistence through ObjectService (sources/context only attached
 * when non-empty) and history building (chronological re-ordering of the
 * most-recent-first fetch, LLPhant role mapping, skipping incomplete/unknown turns).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use LLPhant\Chat\Enums\ChatRole;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the message storage/history handler.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class MessageHistoryHandlerTest extends TestCase {

	/**
	 * A Message ObjectEntity.
	 *
	 * @param string $role The role.
	 * @param string $content The content.
	 *
	 * @return ObjectEntity
	 */
	private function message(string $role, string $content): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('msg-' . $role . '-' . substr(md5($content), 0, 6));
		$entity->setObject(
			[
				'sessionId' => 'conv-1',
				'role' => $role,
				'content' => $content,
			]
		);
		return $entity;
	}//end message()

	/**
	 * storeMessage persists the message payload through ObjectService, attaching
	 * sources/context ONLY when non-empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testStoreMessageAttachesOptionalFieldsOnlyWhenPresent(): void {
		$saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null) use (&$saved): ObjectEntity {
				$saved[] = [
					'object' => $object,
					'register' => $register,
					'schema' => $schema,
				];
				$entity = new ObjectEntity();
				$entity->setUuid('msg-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		$handler = new MessageHistoryHandler($objectService, new NullLogger());

		// A user turn with a context snapshot but no sources.
		$handler->storeMessage(
			conversationId: 'conv-1',
			role: 'user',
			content: 'Hello',
			sources: null,
			context: ['app' => 'decidesk']
		);

		// An assistant turn with sources but empty context.
		$stored = $handler->storeMessage(
			conversationId: 'conv-1',
			role: 'assistant',
			content: 'Hi!',
			sources: [['id' => 'src-1', 'type' => 'object', 'name' => 'Doc']]
		);

		$this->assertSame('msg-1', $stored->getUuid());
		$this->assertCount(2, $saved);

		$userPayload = $saved[0]['object'];
		$this->assertSame('user', $userPayload['role']);
		$this->assertSame(['app' => 'decidesk'], $userPayload['context']);
		$this->assertArrayNotHasKey('sources', $userPayload);
		$this->assertSame('hermiq', $saved[0]['register']);
		$this->assertSame(expected: 'agentsessionturn', actual: $saved[0]['schema']);

		$assistantPayload = $saved[1]['object'];
		$this->assertSame('assistant', $assistantPayload['role']);
		$this->assertCount(1, $assistantPayload['sources']);
		$this->assertArrayNotHasKey('context', $assistantPayload);

	}//end testStoreMessageAttachesOptionalFieldsOnlyWhenPresent()

	/**
	 * buildMessageHistory re-orders the most-recent-first fetch chronologically,
	 * maps roles onto LLPhant factories, and skips incomplete or unknown turns.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testBuildMessageHistoryOrdersAndFiltersTurns(): void {
		// findAll returns most-recent-first (sort created DESC).
		$fetched = [
			$this->message('assistant', 'Second answer'),
			$this->message('user', 'Second question'),
			$this->message('tool', 'raw tool output'),
			$this->message('assistant', ''),
			$this->message('user', 'First question'),
		];

		$capturedConfig = null;
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$capturedConfig, $fetched): array {
				$capturedConfig = $config;
				return $fetched;
			}
		);

		$handler = new MessageHistoryHandler($objectService, new NullLogger());
		$history = $handler->buildMessageHistory(conversationId: 'conv-1');

		// The fetch is filtered + capped + newest-first.
		$this->assertSame(expected: 'conv-1', actual: $capturedConfig['filters']['sessionId']);
		$this->assertSame(['created' => 'DESC'], $capturedConfig['sort']);
		$this->assertSame(10, $capturedConfig['limit']);

		// tool role (unknown to LLPhant mapping) and the empty-content turn are
		// skipped; the rest is chronological (oldest first).
		$this->assertCount(3, $history);
		$this->assertSame(ChatRole::User, $history[0]->role);
		$this->assertSame('First question', $history[0]->content);
		$this->assertSame(ChatRole::User, $history[1]->role);
		$this->assertSame('Second question', $history[1]->content);
		$this->assertSame(ChatRole::Assistant, $history[2]->role);
		$this->assertSame('Second answer', $history[2]->content);

	}//end testBuildMessageHistoryOrdersAndFiltersTurns()

	/**
	 * The history of a shared session includes the other participants' turns.
	 *
	 * A SessionTurn is readable by its owner only (hermiq#976), and in a Talk
	 * shared session each speaker owns their own turns. The engine has already
	 * run the owner-or-participant check on the session, so the history read
	 * goes around RBAC and keeps tenancy.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testHistoryIsReadAroundTheOwnerOnlyTurnRule(): void {
		$flags = null;
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true, bool $_multitenancy = true) use (&$flags): array {
				$flags = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return [];
			}
		);

		(new MessageHistoryHandler($objectService, new NullLogger()))->buildMessageHistory(conversationId: 'conv-1');

		$this->assertSame(['rbac' => false, 'multitenancy' => true], $flags);

	}//end testHistoryIsReadAroundTheOwnerOnlyTurnRule()

	/**
	 * A user turn keeps its attachments as references, and the history names an
	 * earlier attachment in one line instead of sending the file again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function testAttachmentsAreStoredAndNamedInTheHistory(): void {
		$attached = [['fileId' => 48213, 'name' => 'offerte-2026.pdf', 'mimeType' => 'application/pdf', 'size' => 10, 'origin' => 'files']];
		$saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);
		$turn = $this->message('user', 'Wat staat erin?');
		$turn->setObject(array_merge($turn->getObject(), ['attachments' => $attached]));
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([$turn]);

		$handler = new MessageHistoryHandler($objectService, new NullLogger());
		$handler->storeMessage(conversationId: 'conv-1', role: 'user', content: 'Wat staat erin?', attachments: $attached);
		$handler->storeMessage(conversationId: 'conv-1', role: 'assistant', content: 'Dit.');

		$this->assertSame($attached, $saved[0]['attachments']);
		$this->assertArrayNotHasKey('attachments', $saved[1]);

		$history = $handler->buildMessageHistory(conversationId: 'conv-1');
		$this->assertSame("Wat staat erin?\n\nAttached earlier: offerte-2026.pdf, file 48213", $history[0]->content);

	}//end testAttachmentsAreStoredAndNamedInTheHistory()
}//end class
