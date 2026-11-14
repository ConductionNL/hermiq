<?php

/**
 * Unit tests for ChatController's warm-up, guard and failure answers.
 *
 * ChatControllerTest covers the turn, history and feedback happy paths. This
 * file pins the warm-up decisions (no agent, not a cli agent, a cli agent with
 * and without tools), the 400/403/500 answers of getHistory, clearHistory,
 * sendFeedback and getChatStats, a 422 attachment refusal, a turn that names
 * an agent that does not exist, and the archive marker on a conversation whose
 * metadata is not an array.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ChatController;
use OCA\Hermiq\Kernel\Contracts\EngineInterface;
use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Kernel\Contracts\RunStepBusInterface;
use OCA\Hermiq\Kernel\Contracts\ToolAccessRequestServiceInterface;
use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Warm-up, guard and failure answers of ChatController.
 *
 * @covers \OCA\Hermiq\Controller\ChatController
 * @uses   \OCA\Hermiq\Service\Talk\ConversationParticipation
 * @uses   \OCA\Hermiq\Service\Llm\ChatDriver
 * @uses   \OCA\Hermiq\Service\Engine\RunTraceCollector
 * @uses   \OCA\Hermiq\Service\Engine\SanitizesForSaveTrait
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-4-1
 */
final class ChatControllerUncoveredPathsTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var EngineInterface&MockObject
	 */
	private EngineInterface $engine;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var ProviderFactoryInterface&MockObject
	 */
	private ProviderFactoryInterface $providerFactory;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the doubles; the session user is alice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->engine = $this->createMock(EngineInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->providerFactory = $this->createMock(ProviderFactoryInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the controller wired to the current doubles.
	 *
	 * @return ChatController The controller.
	 */
	private function controller(): ChatController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new ChatController(
			request: $this->request,
			engine: $this->engine,
			objectService: $this->objectService,
			userSession: $this->userSession,
			l10n: $l10n,
			runStepBus: $this->createMock(RunStepBusInterface::class),
			providerFactory: $this->providerFactory,
			accessRequests: $this->createMock(ToolAccessRequestServiceInterface::class),
			logger: $this->logger
		);
	}//end controller()

	/**
	 * Stub request params via getParam(key, default).
	 *
	 * @param array<string, mixed> $params The parameter map.
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
	 * @param string               $uuid    The object UUID.
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * Answer ObjectService::find() per schema: the stub's fifth positional
	 * argument is the schema slug.
	 *
	 * @param array<string, ObjectEntity|null> $bySchema Entity per schema slug.
	 *
	 * @return void
	 */
	private function findBySchema(array $bySchema): void {
		$this->objectService->method('find')->willReturnCallback(
			static function (...$args) use ($bySchema): ?ObjectEntity {
				return ($bySchema[$args[4]] ?? null);
			}
		);
	}//end findBySchema()

	/**
	 * A warm-up for an agent that no longer exists answers warmed false and
	 * never resolves a driver.
	 *
	 * @return void
	 */
	public function testWarmForAMissingAgentIsNotWarmed(): void {
		$this->stubParams(['agentUuid' => 'agent-1', 'conversation' => 'conv-1']);
		$this->findBySchema(['agentsession' => $this->entity('conv-1', ['userId' => 'alice'])]);
		$this->providerFactory->expects(self::never())->method('createChatDriver');

		$response = $this->controller()->warm();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['warmed' => false], $response->getData());
	}//end testWarmForAMissingAgentIsNotWarmed()

	/**
	 * An HTTP-transport agent has no process to warm: the answer names why and
	 * the CLI pool is never touched. A non-string model is passed on as null.
	 *
	 * @return void
	 */
	public function testWarmForANonCliAgentSaysWhy(): void {
		$this->stubParams(['agentUuid' => 'agent-1', 'conversation' => 'conv-1']);
		$this->findBySchema([
			'agentsession' => $this->entity('conv-1', ['userId' => 'alice']),
			'agent' => $this->entity('agent-1', ['model' => 42]),
		]);
		$this->providerFactory->method('getLlmConfig')->willReturn(['provider' => 'openai']);
		$this->providerFactory->expects(self::once())
			->method('createChatDriver')
			->with(['provider' => 'openai'], null)
			->willReturn(new ChatDriver(provider: 'openai', chat: null, model: 'gpt-x'));
		$this->providerFactory->expects(self::never())->method('warmAnthropicCli');

		$response = $this->controller()->warm();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['warmed' => false, 'reason' => 'not a cli agent'], $response->getData());
	}//end testWarmForANonCliAgentSaysWhy()

	/**
	 * A cli agent holding tools is warmed governed, with the driver's credential
	 * and model and the agent's own model passed to the driver.
	 *
	 * @return void
	 */
	public function testWarmACliAgentWithToolsIsGoverned(): void {
		$this->stubParams(['agentUuid' => 'agent-1', 'conversation' => 'conv-1']);
		$this->findBySchema([
			'agentsession' => $this->entity('conv-1', ['userId' => 'alice']),
			'agent' => $this->entity('agent-1', ['model' => 'claude-x', 'tools' => ['search']]),
		]);
		$this->providerFactory->method('getLlmConfig')->willReturn([]);
		$this->providerFactory->expects(self::once())
			->method('createChatDriver')
			->with([], 'claude-x')
			->willReturn(new ChatDriver(provider: 'anthropic', chat: null, model: 'claude-x', credentialId: 'cred-7', executionMode: 'cli'));
		$this->providerFactory->expects(self::once())
			->method('warmAnthropicCli')
			->with('cred-7', 'claude-x', 'agent-1', 'conv-1', true)
			->willReturn(true);

		$response = $this->controller()->warm();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['warmed' => true], $response->getData());
	}//end testWarmACliAgentWithToolsIsGoverned()

	/**
	 * A cli agent without tools is warmed ungoverned, and a pool that declines
	 * the warm-up is reported as not warmed.
	 *
	 * @return void
	 */
	public function testWarmACliAgentWithoutToolsIsUngoverned(): void {
		$this->stubParams(['agentUuid' => 'agent-1', 'conversation' => 'conv-1']);
		$this->findBySchema([
			'agentsession' => $this->entity('conv-1', ['userId' => 'alice']),
			'agent' => $this->entity('agent-1', ['tools' => []]),
		]);
		$this->providerFactory->method('getLlmConfig')->willReturn([]);
		$this->providerFactory->method('createChatDriver')
			->willReturn(new ChatDriver(provider: 'anthropic', chat: null, model: 'claude-y', executionMode: 'cli'));
		$this->providerFactory->expects(self::once())
			->method('warmAnthropicCli')
			->with('', 'claude-y', 'agent-1', 'conv-1', false)
			->willReturn(false);

		$response = $this->controller()->warm();

		self::assertSame(['warmed' => false], $response->getData());
	}//end testWarmACliAgentWithoutToolsIsUngoverned()

	/**
	 * An attachment refused for a missing redaction (code 422) answers 422.
	 *
	 * @return void
	 */
	public function testAnAttachmentRefusedForRedactionAnswers422(): void {
		$this->stubParams(['conversation' => 'conv-1', 'message' => 'Lees dit']);
		$this->findBySchema(['agentsession' => $this->entity('conv-1', ['userId' => 'alice'])]);
		$this->engine->method('processMessage')->willThrowException(
			new RuntimeException('wrapped', 0, new AttachmentRefusedException('Redact this file first', 422))
		);

		$response = $this->controller()->sendMessage();

		self::assertSame(422, $response->getStatus());
		self::assertSame(
			['error' => 'Redact this file first', 'message' => 'Redact this file first', 'errorCode' => 'attachment_refused'],
			$response->getData()
		);
	}//end testAnAttachmentRefusedForRedactionAnswers422()

	/**
	 * A new turn against an agent that does not exist answers 404 and never
	 * creates a conversation.
	 *
	 * @return void
	 */
	public function testANewTurnForAMissingAgentIsNotFound(): void {
		$this->stubParams(['agentUuid' => 'ghost-agent', 'message' => 'hi']);
		$this->findBySchema([]);
		$this->objectService->expects(self::never())->method('saveObject');
		$this->engine->expects(self::never())->method('processMessage');

		$response = $this->controller()->sendMessage();

		self::assertSame(404, $response->getStatus());
		self::assertSame('The agent with UUID ghost-agent does not exist', $response->getData()['message']);
	}//end testANewTurnForAMissingAgentIsNotFound()

	/**
	 * A failing history read is logged and answered 500 with the cause.
	 *
	 * @return void
	 */
	public function testGetHistoryFailureIsLoggedAndAnswered500(): void {
		$this->stubParams(['conversationId' => 'conv-1']);
		$this->objectService->method('find')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())
			->method('error')
			->with('[ChatController] Failed to get history', self::callback(
				static fn (array $context): bool => $context['error'] === 'db down'
			));

		$response = $this->controller()->getHistory();

		self::assertSame(500, $response->getStatus());
		self::assertSame(
			['error' => 'Failed to fetch conversation history', 'message' => 'db down'],
			$response->getData()
		);
	}//end testGetHistoryFailureIsLoggedAndAnswered500()

	/**
	 * A history request without a conversation id is a 400.
	 *
	 * @return void
	 */
	public function testGetHistoryWithoutConversationIdIs400(): void {
		$this->stubParams([]);
		$this->objectService->expects(self::never())->method('find');

		$response = $this->controller()->getHistory();

		self::assertSame(400, $response->getStatus());
		self::assertSame('Missing conversationId', $response->getData()['error']);
	}//end testGetHistoryWithoutConversationIdIs400()

	/**
	 * Clearing without a conversation id is a 400.
	 *
	 * @return void
	 */
	public function testClearHistoryWithoutConversationIdIs400(): void {
		$this->stubParams([]);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->clearHistory();

		self::assertSame(400, $response->getStatus());
		self::assertSame(
			['error' => 'Missing conversationId', 'message' => 'conversationId is required'],
			$response->getData()
		);
	}//end testClearHistoryWithoutConversationIdIs400()

	/**
	 * Clearing someone else's conversation is a 403 and writes nothing.
	 *
	 * @return void
	 */
	public function testClearHistoryOfAForeignConversationIsForbidden(): void {
		$this->stubParams(['conversationId' => 'conv-1']);
		$this->findBySchema(['agentsession' => $this->entity('conv-1', ['userId' => 'bob'])]);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->clearHistory();

		self::assertSame(403, $response->getStatus());
		self::assertSame(
			['error' => 'Access denied', 'message' => 'You do not have access to this conversation'],
			$response->getData()
		);
	}//end testClearHistoryOfAForeignConversationIsForbidden()

	/**
	 * A conversation whose metadata is not an array gets a fresh metadata map
	 * carrying the archive marker.
	 *
	 * @return void
	 */
	public function testClearHistoryReplacesNonArrayMetadata(): void {
		$this->stubParams(['conversationId' => 'conv-1']);
		$this->findBySchema(['agentsession' => $this->entity('conv-1', ['userId' => 'alice', 'metadata' => 'broken'])]);
		$saved = null;
		$this->objectService->expects(self::once())
			->method('saveObject')
			->willReturnCallback(
				static function (...$args) use (&$saved): ObjectEntity {
					$saved = $args;
					return new ObjectEntity();
				}
			);

		$response = $this->controller()->clearHistory();

		self::assertSame(200, $response->getStatus());
		self::assertSame('alice', $saved[0]['metadata']['deletedBy']);
		self::assertArrayHasKey('deletedAt', $saved[0]['metadata']);
		self::assertCount(2, $saved[0]['metadata']);
		self::assertSame('conv-1', $saved[4]);
	}//end testClearHistoryReplacesNonArrayMetadata()

	/**
	 * A failing archive write is logged and answered 500, never reported as cleared.
	 *
	 * @return void
	 */
	public function testClearHistoryFailureIsLoggedAndAnswered500(): void {
		$this->stubParams(['conversationId' => 'conv-1']);
		$this->findBySchema(['agentsession' => $this->entity('conv-1', ['userId' => 'alice'])]);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('write refused'));
		$this->logger->expects(self::once())
			->method('error')
			->with('[ChatController] Failed to clear history', self::anything());

		$response = $this->controller()->clearHistory();

		self::assertSame(500, $response->getStatus());
		self::assertSame(
			['error' => 'Failed to clear conversation', 'message' => 'write refused'],
			$response->getData()
		);
	}//end testClearHistoryFailureIsLoggedAndAnswered500()

	/**
	 * Feedback on someone else's conversation is a 403 and saves nothing.
	 *
	 * @return void
	 */
	public function testSendFeedbackOnAForeignConversationIsForbidden(): void {
		$this->stubParams(['type' => 'positive']);
		$this->findBySchema(['agentsession' => $this->entity('conv-1', ['userId' => 'bob'])]);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->sendFeedback('conv-1', 'msg-1');

		self::assertSame(403, $response->getStatus());
		self::assertSame('Access denied', $response->getData()['error']);
	}//end testSendFeedbackOnAForeignConversationIsForbidden()

	/**
	 * A failing feedback save is logged with its conversation and message and
	 * answered 500.
	 *
	 * @return void
	 */
	public function testSendFeedbackFailureIsLoggedAndAnswered500(): void {
		$this->stubParams(['type' => 'negative']);
		$this->objectService->method('find')->willThrowException(new RuntimeException('lookup failed'));
		$this->logger->expects(self::once())
			->method('error')
			->with('[ChatController] Failed to save feedback', self::callback(
				static fn (array $context): bool => $context['conversationUuid'] === 'conv-1'
					&& $context['messageId'] === 'msg-1'
					&& $context['error'] === 'lookup failed'
			));

		$response = $this->controller()->sendFeedback('conv-1', 'msg-1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Failed to save feedback', 'message' => 'lookup failed'], $response->getData());
	}//end testSendFeedbackFailureIsLoggedAndAnswered500()

	/**
	 * Stats for an unauthenticated caller are refused (as a logged 500) and
	 * nothing is counted.
	 *
	 * @return void
	 */
	public function testGetChatStatsWithoutAUserCountsNothing(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->userSession = $session;
		$this->objectService->expects(self::never())->method('searchObjectsPaginated');
		$this->logger->expects(self::once())
			->method('error')
			->with('[ChatController] Failed to get chat stats', self::callback(
				static fn (array $context): bool => $context['error'] === 'Authentication required'
			));

		$response = $this->controller()->getChatStats();

		self::assertSame(500, $response->getStatus());
		self::assertSame(
			['error' => 'Failed to get chat statistics', 'message' => 'Authentication required'],
			$response->getData()
		);
	}//end testGetChatStatsWithoutAUserCountsNothing()
}//end class
