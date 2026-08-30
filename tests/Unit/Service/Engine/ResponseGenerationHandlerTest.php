<?php

/**
 * Unit tests for ResponseGenerationHandler (agent-engine-port).
 *
 * Covers the orchestration around the LLM call without network: system-prompt
 * assembly (agent prompt, CnAiContext bullets, RAG context block) captured via the
 * Fireworks direct-HTTP path, agent model/temperature forwarding into the
 * provider factory, per-run usage exposure (`lastUsage`), the nextcloud-driver
 * chat scope guard, and error wrapping.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use Exception;
use LLPhant\Chat\Enums\ChatRole;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the response generation orchestration.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class ResponseGenerationHandlerTest extends TestCase {
	/**
	 * An Agent ObjectEntity.
	 *
	 * @param array<string, mixed> $payload Agent fields.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setObject($payload);
		return $entity;
	}//end agent()

	/**
	 * A ToolLoop mock returning no functions.
	 *
	 * @return ToolLoop
	 */
	private function toollessLoop(): ToolLoop {
		$loop = $this->createMock(ToolLoop::class);
		$loop->method('listAgentFunctions')->willReturn([]);
		return $loop;
	}//end toollessLoop()

	/**
	 * The Fireworks path assembles the system prompt (agent prompt + CnAiContext
	 * bullets + RAG context) as the FIRST message and the user message LAST, and
	 * forwards agent model/temperature into the provider factory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testFireworksPathAssemblesPromptAndForwardsOverrides(): void {
		$capturedHistory = null;
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => 'fireworks']);
		$factory->expects($this->once())
			->method('createChatDriver')
			->with(
				['chatProvider' => 'fireworks'],
				'llama-custom',
				0.2
			)
			->willReturn(
				new ChatDriver(
					provider: 'fireworks',
					chat: null,
					model: 'llama-custom',
					credentialId: 'cred-uuid-fireworks',
					baseUrl: 'https://api.fireworks.ai/inference/v1'
				)
			);
		$factory->method('callFireworksChat')->willReturnCallback(
			function (string $credentialId, string $model, string $baseUrl, array $messageHistory) use (&$capturedHistory): string {
				$capturedHistory = $messageHistory;
				return 'The answer.';
			}
		);

		$handler = new ResponseGenerationHandler($factory, $this->toollessLoop(), new NullLogger());

		$response = $handler->generateResponse(
			userMessage: 'What now?',
			context: [
				'text' => 'Doc excerpt.',
				'sources' => [],
			],
			messageHistory: [],
			agent: $this->agent(
				[
					'prompt' => 'You are the meeting assistant.',
					'model' => 'llama-custom',
					'temperature' => 0.2,
				]
			),
			cnAiContext: ['app' => 'decidesk', 'view' => 'meetings']
		);

		$this->assertSame('The answer.', $response);

		$this->assertNotNull($capturedHistory);
		$system = $capturedHistory[0];
		$this->assertSame(ChatRole::System, $system->role);
		$this->assertStringContainsString('You are the meeting assistant.', $system->content);
		$this->assertStringContainsString('CURRENT APP CONTEXT', $system->content);
		$this->assertStringContainsString('- app: decidesk', $system->content);
		$this->assertStringContainsString("CONTEXT:\nDoc excerpt.", $system->content);

		$last = $capturedHistory[count($capturedHistory) - 1];
		$this->assertSame(ChatRole::User, $last->role);
		$this->assertSame('What now?', $last->content);

		// Fireworks exposes no token usage; llmSeconds is still recorded.
		$this->assertArrayHasKey('llmSeconds', $handler->lastUsage);

	}//end testFireworksPathAssemblesPromptAndForwardsOverrides()

	/**
	 * The Anthropic path wires a `toolExecutor` into callAnthropicChat when the
	 * agent has tools: the executor runs a requested tool by (name, input)
	 * through the SAME governed FunctionInfo the LLPhant path uses, and returns
	 * a clear error for an unknown tool name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	public function testAnthropicPathWiresGovernedToolExecutor(): void {
		$capturedExecutor = null;
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => 'anthropic']);
		$factory->method('createChatDriver')->willReturn(
			new ChatDriver(
				provider: 'anthropic',
				chat: null,
				model: 'claude-opus-4-8',
				credentialId: 'cred-anthropic',
				baseUrl: 'https://api.anthropic.com/v1',
				authMode: 'api_key'
			)
		);
		$factory->method('callAnthropicChat')->willReturnCallback(
			function (
				string $credentialId,
				string $model,
				string $baseUrl,
				array $messageHistory,
				string $authMode,
				?int $maxTokens,
				array $functions,
				?callable $toolExecutor,
			) use (&$capturedExecutor): string {
				$capturedExecutor = $toolExecutor;
				return 'done.';
			}
		);

		// A tool instance whose method-call (via __call, since the named tool
		// method is not declared) echoes its input — mirroring how LLPhant's
		// FunctionInfo::callWithArguments() dispatches into FacadeToolInvoker.
		$toolInstance = new class {
			/**
			 * @param string $name Tool name.
			 * @param array<string, mixed> $args Named arguments.
			 *
			 * @return string
			 */
			public function __call(string $name, array $args): string {
				return 'weather:' . ((string)($args['city'] ?? '?'));
			}//end __call()
		};
		$functionInfo = new \LLPhant\Chat\FunctionInfo\FunctionInfo('get_weather', $toolInstance, 'Get weather', []);

		$loop = $this->createMock(ToolLoop::class);
		$loop->method('listAgentFunctions')->willReturn(
			[['name' => 'get_weather', 'description' => 'Get weather', 'parameters' => ['type' => 'object', 'properties' => []]]]
		);
		$loop->method('buildFunctionInfos')->willReturn([$functionInfo]);

		$handler = new ResponseGenerationHandler($factory, $loop, new NullLogger());
		$handler->generateResponse(
			userMessage: 'weather in Paris?',
			context: ['text' => '', 'sources' => []],
			messageHistory: [],
			agent: $this->agent(['prompt' => 'You are helpful.']),
			cnAiContext: []
		);

		$this->assertIsCallable($capturedExecutor, 'A toolExecutor must be passed when the agent has tools.');
		$this->assertSame('weather:Paris', $capturedExecutor(toolName: 'get_weather', toolInput: ['city' => 'Paris']));
		$this->assertSame('{"error":"Unknown tool: nope"}', $capturedExecutor(toolName: 'nope', toolInput: []));

	}//end testAnthropicPathWiresGovernedToolExecutor()

	/**
	 * Selecting the nextcloud TaskProcessing driver for CHAT is refused (its
	 * scope is background titles/summaries only) with a clear wrapped error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-2
	 */
	public function testNextcloudDriverIsRefusedForChat(): void {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => 'nextcloud']);
		$factory->method('createChatDriver')->willReturn(
			new ChatDriver(provider: 'nextcloud', chat: null, model: 'core:text2text')
		);

		$handler = new ResponseGenerationHandler($factory, $this->toollessLoop(), new NullLogger());

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('background work only');
		$handler->generateResponse(
			userMessage: 'Hi',
			context: ['text' => '', 'sources' => []],
			messageHistory: [],
			agent: null
		);

	}//end testNextcloudDriverIsRefusedForChat()

	/**
	 * Provider unavailability surfaces as a wrapped, catchable Exception (the
	 * ported "Failed to generate response" contract).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
	 */
	public function testProviderUnavailableIsWrapped(): void {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => null]);
		$factory->method('createChatDriver')->willThrowException(
			new ProviderUnavailableException('Chat provider is not configured', 503)
		);

		$handler = new ResponseGenerationHandler($factory, $this->toollessLoop(), new NullLogger());

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to generate response: Chat provider is not configured');
		$handler->generateResponse(
			userMessage: 'Hi',
			context: ['text' => '', 'sources' => []],
			messageHistory: [],
			agent: null
		);

	}//end testProviderUnavailableIsWrapped()

	/**
	 * When the agent defines no prompt, the default system prompt is used and
	 * no APP CONTEXT block appears without a CnAiContext snapshot.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testDefaultPromptWithoutAgentOrContext(): void {
		$capturedHistory = null;
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => 'fireworks']);
		$factory->method('createChatDriver')->willReturn(
			new ChatDriver(
				provider: 'fireworks',
				chat: null,
				model: 'm',
				credentialId: 'cred-uuid-fireworks',
				baseUrl: 'https://api.fireworks.ai/inference/v1'
			)
		);
		$factory->method('callFireworksChat')->willReturnCallback(
			function (string $apiKey, string $model, string $baseUrl, array $messageHistory) use (&$capturedHistory): string {
				$capturedHistory = $messageHistory;
				return 'ok';
			}
		);

		$handler = new ResponseGenerationHandler($factory, $this->toollessLoop(), new NullLogger());
		$handler->generateResponse(
			userMessage: 'Hi',
			context: ['text' => '', 'sources' => []],
			messageHistory: [],
			agent: null
		);

		$system = $capturedHistory[0];
		$this->assertStringContainsString('helpful AI assistant', $system->content);
		$this->assertStringNotContainsString('CURRENT APP CONTEXT', $system->content);
		$this->assertStringNotContainsString('CONTEXT:', $system->content);

	}//end testDefaultPromptWithoutAgentOrContext()
}//end class
