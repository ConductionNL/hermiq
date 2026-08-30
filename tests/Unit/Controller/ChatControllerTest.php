<?php

/**
 * Unit tests for ChatController (agent-engine-port).
 *
 * Exercises the ported chat surface against the in-app Engine + ObjectService:
 * message validation, conversation resolution, the gate-7 ownership guards
 * (403 on a foreign conversation, engine never invoked), the payload-level
 * archive marker written by clearHistory, feedback create/update against the
 * hermiq `feedback` schema, and org-scoped stats via paginated totals.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatController;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-engine-port ChatController.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */
class ChatControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Mock engine facade.
	 *
	 * @var Engine&MockObject
	 */
	private Engine $engine;

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock user session (alice by default).
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Mock logger (settable expectations per test — log-level assertions).
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->engine = $this->createMock(Engine::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * Build the controller wired to the current mocks.
	 *
	 * @return ChatController
	 */
	private function controller(): ChatController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		// ⚠️ NAMED arguments, not positional. The three collaborators below were
		// inserted in the MIDDLE of the constructor, and with positional
		// arguments the logger silently slid into the `$runStepBus` slot — the
		// suite failed with "must be of type RunStepBus, MockObject_Logger
		// given", which is the loud version. The quiet version is an argument
		// that happens to satisfy the next parameter's type.
		return new ChatController(
			request: $this->request,
			engine: $this->engine,
			objectService: $this->objectService,
			userSession: $this->userSession,
			l10n: $l10n,
			runStepBus: $this->createMock(RunStepBus::class),
			providerFactory: $this->createMock(ProviderFactory::class),
			accessRequests: $this->createMock(ToolAccessRequestService::class),
			logger: $this->logger
		);

	}//end controller()

	/**
	 * Stub request params via getParam(key, default).
	 *
	 * @param array<string,mixed> $params The parameter map.
	 *
	 * @return void
	 */
	private function stubParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

	}//end stubParams()

	/**
	 * Build an ObjectEntity fixture.
	 *
	 * @param string $uuid The object UUID.
	 * @param array<string,mixed> $payload The object payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * An empty message is a 400 and never reaches the engine.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendMessageRequiresMessage(): void {
		$this->stubParams(['conversation' => 'conv-1', 'message' => '']);
		$this->engine->expects($this->never())->method('processMessage');

		$response = $this->controller()->sendMessage();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Missing message', $response->getData()['error']);

	}//end testSendMessageRequiresMessage()

	/**
	 * A nonexistent conversation is a 404 and never reaches the engine.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendMessageUnknownConversationIsNotFound(): void {
		$this->stubParams(['conversation' => 'ghost', 'message' => 'hi']);
		$this->objectService->method('find')->willReturn(null);
		$this->engine->expects($this->never())->method('processMessage');

		$response = $this->controller()->sendMessage();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('Conversation not found', $response->getData()['error']);

	}//end testSendMessageUnknownConversationIsNotFound()

	/**
	 * Gate-7 IDOR guard: another user's conversation is a 403 and the engine
	 * is never invoked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendMessageForeignConversationIsForbidden(): void {
		$this->stubParams(['conversation' => 'conv-bob', 'message' => 'hi']);
		$this->objectService->method('find')->willReturn(
			$this->entity('conv-bob', ['userId' => 'bob', 'agentId' => 'agent-1'])
		);
		$this->engine->expects($this->never())->method('processMessage');

		$response = $this->controller()->sendMessage();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('Access denied', $response->getData()['error']);

	}//end testSendMessageForeignConversationIsForbidden()

	/**
	 * The happy path delegates to Engine::processMessage with the conversation
	 * UUID and echoes the conversation uuid back on the result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendMessageDelegatesToEngine(): void {
		$this->stubParams(['conversation' => 'conv-1', 'message' => 'hi there']);
		$this->objectService->method('find')->willReturn(
			$this->entity('conv-1', ['userId' => 'alice', 'agentId' => 'agent-1'])
		);

		$this->engine->expects($this->once())->method('processMessage')->with(
			$this->equalTo('conv-1'),
			$this->equalTo('alice'),
			$this->equalTo('hi there')
		)->willReturn(
			[
				'message' => 'hello alice',
				'messageId' => 'msg-9',
				'sources' => [],
				'timings' => [],
				'usage' => [],
			]
		);

		$response = $this->controller()->sendMessage();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('hello alice', $response->getData()['message']);
		$this->assertSame('conv-1', $response->getData()['conversation']);

	}//end testSendMessageDelegatesToEngine()

	/**
	 * A missing conversation AND agentUuid is a 400 (resolveConversation's
	 * input-validation guard) and must log at WARNING — not ERROR with a full
	 * stack trace — because it is expected client input error, not a server fault.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendMessageMissingAgentUuidLogsWarningNotError(): void {
		$this->stubParams(['message' => 'hi']);
		$this->engine->expects($this->never())->method('processMessage');
		$this->logger->expects($this->once())->method('warning');
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller()->sendMessage();

		$this->assertSame(400, $response->getStatus());

	}//end testSendMessageMissingAgentUuidLogsWarningNotError()

	/**
	 * A genuine server-side failure (engine throws an uncoded exception, so the
	 * catch block defaults it to 500) must still log at ERROR with the trace —
	 * only the 4xx client-validation branch is downgraded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendMessageServerFailureStillLogsError(): void {
		$this->stubParams(['conversation' => 'conv-1', 'message' => 'hi there']);
		$this->objectService->method('find')->willReturn(
			$this->entity('conv-1', ['userId' => 'alice', 'agentId' => 'agent-1'])
		);
		$this->engine->method('processMessage')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');
		$this->logger->expects($this->never())->method('warning');

		$response = $this->controller()->sendMessage();

		$this->assertSame(500, $response->getStatus());

	}//end testSendMessageServerFailureStillLogsError()

	/**
	 * getHistory without a conversationId is a 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testGetHistoryRequiresConversationId(): void {
		$this->stubParams([]);

		$response = $this->controller()->getHistory();

		$this->assertSame(400, $response->getStatus());

	}//end testGetHistoryRequiresConversationId()

	/**
	 * Gate-7 IDOR guard: history of another user's conversation is a 403.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testGetHistoryForeignConversationIsForbidden(): void {
		$this->stubParams(['conversationId' => 'conv-bob']);
		$this->objectService->method('find')->willReturn(
			$this->entity('conv-bob', ['userId' => 'bob'])
		);

		$response = $this->controller()->getHistory();

		$this->assertSame(403, $response->getStatus());

	}//end testGetHistoryForeignConversationIsForbidden()

	/**
	 * getHistory returns the serialized messages plus the paginated total.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testGetHistoryReturnsMessagesAndTotal(): void {
		$this->stubParams(['conversationId' => 'conv-1']);
		$this->objectService->method('find')->willReturn(
			$this->entity('conv-1', ['userId' => 'alice'])
		);
		$this->objectService->method('findAll')->willReturn(
			[
				$this->entity('msg-1', ['conversationId' => 'conv-1', 'role' => 'user', 'content' => 'hi']),
				$this->entity('msg-2', ['conversationId' => 'conv-1', 'role' => 'assistant', 'content' => 'hello']),
			]
		);
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 2]);

		$response = $this->controller()->getHistory();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['total']);
		$this->assertCount(2, $response->getData()['messages']);
		$this->assertSame('msg-1', $response->getData()['messages'][0]['id']);
		$this->assertSame('user', $response->getData()['messages'][0]['role']);

	}//end testGetHistoryReturnsMessagesAndTotal()

	/**
	 * clearHistory archives the conversation by writing the payload-level
	 * metadata.deletedAt marker (the hermiq soft-delete adaptation).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testClearHistoryWritesArchiveMarker(): void {
		$this->stubParams(['conversationId' => 'conv-1']);
		$this->objectService->method('find')->willReturn(
			$this->entity('conv-1', ['userId' => 'alice', 'agentId' => 'agent-1'])
		);

		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object) use (&$saved): ObjectEntity {
				$saved = $object;
				return new ObjectEntity();
			}
		);

		$response = $this->controller()->clearHistory();

		$this->assertSame(200, $response->getStatus());
		$this->assertIsArray($saved);
		$this->assertNotEmpty($saved['metadata']['deletedAt']);
		$this->assertSame('alice', $saved['metadata']['deletedBy']);

	}//end testClearHistoryWritesArchiveMarker()

	/**
	 * An invalid feedback type is a 400 and nothing is persisted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendFeedbackRejectsInvalidType(): void {
		$this->stubParams(['type' => 'meh']);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->sendFeedback('conv-1', 'msg-1');

		$this->assertSame(400, $response->getStatus());

	}//end testSendFeedbackRejectsInvalidType()

	/**
	 * A message that does not belong to the conversation is a 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendFeedbackChecksMessageBelongsToConversation(): void {
		$this->stubParams(['type' => 'positive']);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				if ($schema === 'conversation') {
					return $this->entity('conv-1', ['userId' => 'alice', 'agentId' => 'agent-1']);
				}

				return $this->entity('msg-1', ['conversationId' => 'conv-OTHER', 'role' => 'assistant']);
			}
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->sendFeedback('conv-1', 'msg-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('Message not found', $response->getData()['error']);

	}//end testSendFeedbackChecksMessageBelongsToConversation()

	/**
	 * New feedback is persisted with the required hermiq `feedback` shape
	 * (messageId/conversationId/agentId/userId/type/comment).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendFeedbackCreatesFeedbackObject(): void {
		$this->stubParams(['type' => 'negative', 'comment' => 'wrong answer']);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				if ($schema === 'conversation') {
					return $this->entity('conv-1', ['userId' => 'alice', 'agentId' => 'agent-1']);
				}

				return $this->entity('msg-1', ['conversationId' => 'conv-1', 'role' => 'assistant']);
			}
		);

		// No existing feedback for this message/user.
		$this->objectService->method('findAll')->willReturn([]);

		$saved = null;
		$savedUuid = 'unset';
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object, ?array $extend = null, mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved, &$savedUuid): ObjectEntity {
				$saved = $object;
				$savedUuid = $uuid;
				$entity = new ObjectEntity();
				$entity->setUuid('fb-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		$response = $this->controller()->sendFeedback('conv-1', 'msg-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertNull($savedUuid, 'A new feedback object must not target an existing uuid.');
		$this->assertSame(
			[
				'messageId' => 'msg-1',
				'conversationId' => 'conv-1',
				'agentId' => 'agent-1',
				'userId' => 'alice',
				'type' => 'negative',
				'comment' => 'wrong answer',
			],
			$saved
		);
		$this->assertSame('negative', $response->getData()['type']);

	}//end testSendFeedbackCreatesFeedbackObject()

	/**
	 * Existing feedback is updated in place (same uuid, new type/comment).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testSendFeedbackUpdatesExistingFeedback(): void {
		$this->stubParams(['type' => 'positive', 'comment' => 'better now']);
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				if ($schema === 'conversation') {
					return $this->entity('conv-1', ['userId' => 'alice', 'agentId' => 'agent-1']);
				}

				return $this->entity('msg-1', ['conversationId' => 'conv-1', 'role' => 'assistant']);
			}
		);

		$existing = $this->entity(
			'fb-1',
			[
				'messageId' => 'msg-1',
				'conversationId' => 'conv-1',
				'agentId' => 'agent-1',
				'userId' => 'alice',
				'type' => 'negative',
				'comment' => 'wrong answer',
			]
		);
		$this->objectService->method('findAll')->willReturn([$existing]);

		$saved = null;
		$savedUuid = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object, ?array $extend = null, mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved, &$savedUuid): ObjectEntity {
				$saved = $object;
				$savedUuid = $uuid;
				$entity = new ObjectEntity();
				$entity->setUuid('fb-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		$response = $this->controller()->sendFeedback('conv-1', 'msg-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('fb-1', $savedUuid, 'Existing feedback must be updated in place.');
		$this->assertSame('positive', $saved['type']);
		$this->assertSame('better now', $saved['comment']);

	}//end testSendFeedbackUpdatesExistingFeedback()

	/**
	 * getChatStats reads every count from the paginated total (org-scoped by
	 * ObjectService multitenancy) — never from raw tables.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
	 */
	public function testGetChatStatsUsesPaginatedTotals(): void {
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 5]);

		$response = $this->controller()->getChatStats();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'total_agents' => 5,
				'total_conversations' => 5,
				'total_messages' => 5,
			],
			$response->getData()
		);

	}//end testGetChatStatsUsesPaginatedTotals()

	/**
	 * `POST /api/chat/warm` with nothing to warm answers 200, not an error.
	 *
	 * The warm-up is an optimisation the following turn does not depend on, so
	 * "there is nothing to warm" is a normal outcome and must be reported as
	 * one. A 4xx here would give the chat a failure to handle for a request it
	 * only made to save time.
	 *
	 * @return void
	 */
	public function testWarmWithoutIdentifiersIsNotAnError(): void {
		$this->stubParams(['agentUuid' => '', 'conversation' => '']);

		$response = $this->controller()->warm();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['warmed' => false], $response->getData());

	}//end testWarmWithoutIdentifiersIsNotAnError()

	/**
	 * A warm-up whose conversation lookup THROWS still answers 200.
	 *
	 * `findConversation()` throws rather than returning null when the
	 * conversation is absent, so this is the live failure path and not a
	 * hypothetical one. The endpoint's contract is that a failed warm-up is
	 * invisible to the chat — if this ever returns 500, every chat that opens
	 * on a stale conversation id starts reporting an error for a request the
	 * user never made.
	 *
	 * @return void
	 */
	public function testWarmSwallowsAFailedLookup(): void {
		$this->stubParams(['agentUuid' => 'agent-1', 'conversation' => 'gone']);
		$this->objectService->method('find')
			->willThrowException(new \RuntimeException('no such conversation'));

		$response = $this->controller()->warm();

		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($response->getData()['warmed']);

	}//end testWarmSwallowsAFailedLookup()
}//end class
