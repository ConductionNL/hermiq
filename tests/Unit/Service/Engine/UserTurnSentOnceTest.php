<?php

/**
 * The model receives the user's turn once, on every direct provider (user-turn-sent-once).
 *
 * The engine stored the user's turn, then built the history from storage (which
 * now held that turn), and the response handler appended the turn again. Each
 * provider request therefore carried the latest user message twice.
 *
 * These tests run a real turn end to end: the real Engine, the real
 * MessageHistoryHandler over an in-memory store, the real ResponseGenerationHandler
 * and the real provider clients. Only the wire is replaced: the credential broker
 * for Fireworks and Anthropic, a Guzzle mock handler for Ollama and OpenAI. Each
 * test reads the request body the provider would have received.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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
 * @spec openspec/changes/user-turn-sent-once/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OllamaConfig;
use LLPhant\OpenAIConfig;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OpenAI;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Two turns through the engine; the second request is read off the wire.
 *
 * @spec openspec/changes/user-turn-sent-once/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
 */
class UserTurnSentOnceTest extends TestCase {

	/**
	 * The first question of the conversation.
	 *
	 * @var string
	 */
	private const FIRST_QUESTION = 'Which cases are open?';

	/**
	 * The second, latest question.
	 *
	 * @var string
	 */
	private const LATEST_QUESTION = 'Which of them is due this week?';

	/**
	 * The stored session turns, in the order they were saved.
	 *
	 * @var list<ObjectEntity>
	 */
	private array $turns = [];

	/**
	 * Each request body that left for the provider, decoded.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * An ObjectService over an in-memory session: one conversation, one agent, and
	 * the turns the history handler saves and reads back.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		$conversation = new ObjectEntity();
		$conversation->setUuid('conv-1');
		$conversation->setObject(['userId' => 'alice', 'agentId' => 'agent-1', 'title' => 'Open cases']);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['name' => 'Helper', 'prompt' => 'You help with cases.', 'tools' => []]);

		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('find')->willReturnCallback(
			static fn (int|string $id): ?ObjectEntity => match ($id) {
				'conv-1' => $conversation,
				'agent-1' => $agent,
				default => null,
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object): ObjectEntity {
				$turn = new ObjectEntity();
				$turn->setUuid('turn-' . (count($this->turns) + 1));
				$turn->setObject((array)$object);
				$this->turns[] = $turn;
				return $turn;
			}
		);
		// Most recent first, as MessageHistoryHandler asks for.
		$service->method('findAll')->willReturnCallback(fn (): array => array_reverse($this->turns));

		return $service;
	}//end objectService()

	/**
	 * A real ProviderFactory whose broker records each request body and answers
	 * with the given provider body; only the settings read and the driver choice
	 * are stubbed, to hand the handler the driver under test.
	 *
	 * @param ChatDriver $driver The driver the turn runs on.
	 * @param array<string, mixed> $answer The provider's decoded response body.
	 *
	 * @return ProviderFactory
	 */
	private function providerFactory(ChatDriver $driver, array $answer): ProviderFactory {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (...$args) use ($answer): array {
				// BrokerHttpClient passes the body as the sixth argument.
				$this->requests[] = (array)json_decode((string)$args[5], true);
				return [
					'status' => 200,
					'headers' => ['content-type' => ['application/json']],
					'body' => (string)json_encode($answer),
				];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === BrokerHttpClient::BROKER_CLASS ? $broker : null)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$factory = $this->getMockBuilder(ProviderFactory::class)
			->setConstructorArgs(
				[
					$this->createMock(LlmSettingsHandler::class),
					$this->createMock(IManager::class),
					$userSession,
					new NullLogger(),
					'hermiq',
					null,
					null,
					null,
					null,
					null,
					null,
					$container,
				]
			)
			->onlyMethods(['getLlmConfig', 'createChatDriver'])
			->getMock();
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => $driver->provider]);
		$factory->method('createChatDriver')->willReturn($driver);

		return $factory;
	}//end providerFactory()

	/**
	 * A Guzzle client that records each request body and answers with the given body.
	 *
	 * @param array<string, mixed> $answer The provider's decoded response body.
	 *
	 * @return GuzzleClient
	 */
	private function recordingHttpClient(array $answer): GuzzleClient {
		// One Response per request: a body stream is read once.
		$responses = array_map(
			static fn (): Response => new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($answer)),
			range(1, 4)
		);
		$stack = HandlerStack::create(new MockHandler($responses));
		$stack->push(
			Middleware::mapRequest(
				function ($request) {
					$this->requests[] = (array)json_decode((string)$request->getBody(), true);
					return $request;
				}
			)
		);

		return new GuzzleClient(['handler' => $stack]);
	}//end recordingHttpClient()

	/**
	 * Run the two turns of the conversation through the real engine.
	 *
	 * @param ProviderFactory $factory The provider factory the response handler uses.
	 *
	 * @return void
	 */
	private function runTwoTurns(ProviderFactory $factory): void {
		$toolLoop = $this->createMock(ToolLoop::class);
		$toolLoop->method('listAgentFunctions')->willReturn([]);

		$contextHandler = $this->createMock(ContextRetrievalHandler::class);
		$contextHandler->method('retrieveContext')->willReturn(['text' => '', 'sources' => []]);

		$contextAssembler = $this->createMock(ContextAssembler::class);
		$contextAssembler->method('assembleForAgent')->willReturn('');

		$objectService = $this->objectService();
		$engine = new Engine(
			$objectService,
			$contextHandler,
			new ResponseGenerationHandler($factory, $toolLoop, new NullLogger()),
			$this->createMock(ConversationManagementHandler::class),
			new MessageHistoryHandler($objectService, new NullLogger()),
			$contextAssembler,
			new NullLogger()
		);

		foreach ([self::FIRST_QUESTION, self::LATEST_QUESTION] as $question) {
			$engine->processMessage(conversationId: 'conv-1', userId: 'alice', userMessage: $question);
		}
	}//end runTwoTurns()

	/**
	 * The text of one wire message, whether its content is a string or a list of parts.
	 *
	 * @param array<string, mixed> $message A message from a request body.
	 *
	 * @return string
	 */
	private function textOf(array $message): string {
		$content = ($message['content'] ?? '');
		if (is_string($content) === true) {
			return $content;
		}

		$text = '';
		foreach ((array)$content as $part) {
			if (is_array($part) === true && isset($part['text']) === true) {
				$text .= (string)$part['text'];
			}
		}

		return $text;
	}//end textOf()

	/**
	 * Assert the second request: earlier turns intact and in order, the latest
	 * question once, as the last message.
	 *
	 * @param string $answer The answer the provider gave to the first question.
	 *
	 * @return void
	 */
	private function assertLatestTurnSentOnce(string $answer): void {
		$this->assertCount(2, $this->requests, 'One provider request per turn.');

		// System prompts travel outside `messages` on Anthropic; compare the turns only.
		$turns = array_values(
			array_filter(
				(array)$this->requests[1]['messages'],
				static fn (array $message): bool => ($message['role'] ?? '') !== 'system'
			)
		);
		$spoken = array_map(
			fn (array $message): array => [(string)$message['role'], $this->textOf(message: $message)],
			$turns
		);

		$this->assertSame(
			[
				['user', self::FIRST_QUESTION],
				['assistant', $answer],
				['user', self::LATEST_QUESTION],
			],
			$spoken,
			'The model must read the earlier turns once each and the latest question once, last.'
		);

		$first = array_values(
			array_filter(
				(array)$this->requests[0]['messages'],
				static fn (array $message): bool => ($message['role'] ?? '') !== 'system'
			)
		);
		$this->assertCount(1, $first, 'The first turn of a conversation carries the question once.');
	}//end assertLatestTurnSentOnce()

	/**
	 * Fireworks receives the latest question once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/user-turn-sent-once/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 */
	public function testFireworksReceivesTheLatestQuestionOnce(): void {
		$factory = $this->providerFactory(
			new ChatDriver(
				provider: 'fireworks',
				chat: null,
				model: 'llama-v3p1-8b-instruct',
				credentialId: 'cred-fireworks',
				baseUrl: 'https://api.fireworks.ai/inference/v1'
			),
			['choices' => [['message' => ['content' => 'Two cases.']]]]
		);

		$this->runTwoTurns(factory: $factory);

		$this->assertLatestTurnSentOnce(answer: 'Two cases.');
	}//end testFireworksReceivesTheLatestQuestionOnce()

	/**
	 * Anthropic receives the latest question once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/user-turn-sent-once/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 */
	public function testAnthropicReceivesTheLatestQuestionOnce(): void {
		$factory = $this->providerFactory(
			new ChatDriver(
				provider: 'anthropic',
				chat: null,
				model: 'claude-opus-4-8',
				credentialId: 'cred-anthropic',
				baseUrl: 'https://api.anthropic.com/v1',
				authMode: 'api_key'
			),
			[
				'content' => [['type' => 'text', 'text' => 'Two cases.']],
				'stop_reason' => 'end_turn',
				'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
			]
		);

		$this->runTwoTurns(factory: $factory);

		$this->assertLatestTurnSentOnce(answer: 'Two cases.');
	}//end testAnthropicReceivesTheLatestQuestionOnce()

	/**
	 * Ollama receives the latest question once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/user-turn-sent-once/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 */
	public function testOllamaReceivesTheLatestQuestionOnce(): void {
		$config = new OllamaConfig();
		$config->model = 'qwen3:8b';
		$config->url = 'http://ollama:11434/api/';
		$chat = new OllamaChat($config);
		$chat->client = $this->recordingHttpClient(
			['message' => ['role' => 'assistant', 'content' => 'Two cases.'], 'done' => true]
		);

		$factory = $this->providerFactory(new ChatDriver(provider: 'ollama', chat: $chat, model: 'qwen3:8b'), []);

		$this->runTwoTurns(factory: $factory);

		$this->assertLatestTurnSentOnce(answer: 'Two cases.');
	}//end testOllamaReceivesTheLatestQuestionOnce()

	/**
	 * OpenAI receives the latest question once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/user-turn-sent-once/specs/agent-engine-port/spec.md#requirement-the-model-receives-the-users-turn-once
	 */
	public function testOpenAiReceivesTheLatestQuestionOnce(): void {
		$client = OpenAI::factory()
			->withApiKey('test-key')
			->withHttpClient(
				$this->recordingHttpClient(
					[
						'id' => 'chatcmpl-1',
						'object' => 'chat.completion',
						'created' => 1760000000,
						'model' => 'gpt-4o-mini',
						'choices' => [
							[
								'index' => 0,
								'message' => ['role' => 'assistant', 'content' => 'Two cases.'],
								'finish_reason' => 'stop',
							],
						],
						'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
					]
				)
			)
			->make();
		$chat = new OpenAIChat(new OpenAIConfig(model: 'gpt-4o-mini', client: $client));

		$factory = $this->providerFactory(new ChatDriver(provider: 'openai', chat: $chat, model: 'gpt-4o-mini'), []);

		$this->runTwoTurns(factory: $factory);

		$this->assertLatestTurnSentOnce(answer: 'Two cases.');
	}//end testOpenAiReceivesTheLatestQuestionOnce()
}//end class
