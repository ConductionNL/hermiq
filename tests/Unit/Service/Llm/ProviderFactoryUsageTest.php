<?php

/**
 * Hosted provider calls report the tokens they used (hermiq#985).
 *
 * `BudgetService::currentUsageTokens()` sums `usage.promptTokens` and
 * `usage.completionTokens` from the run audit trail. Only Ollama filled those
 * keys: `callFireworksChat()` and `callAnthropicChat()` returned the text and
 * dropped the usage the provider sent back, so a token budget on a hosted model
 * never counted anything.
 *
 * These tests drive both calls through the real `BrokerHttpClient`, with the
 * OpenRegister credential broker stubbed to answer like the provider does.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
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
 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-7-the-ensemble-turn-and-its-budget
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Usage reported by ProviderFactory::lastCallUsage() after a hosted call.
 *
 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-7-the-ensemble-turn-and-its-budget
 */
class ProviderFactoryUsageTest extends TestCase {

	/**
	 * A factory whose broker answers each request with the next body in line.
	 *
	 * @param array<int, array<string, mixed>> $bodies Decoded provider response bodies, in order.
	 *
	 * @return ProviderFactory
	 */
	private function factory(array $bodies): ProviderFactory {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			static function () use (&$bodies): array {
				return [
					'status' => 200,
					'headers' => ['content-type' => ['application/json']],
					'body' => (string)json_encode(array_shift($bodies)),
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

		return new ProviderFactory(
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
			$container
		);
	}//end factory()

	/**
	 * A one-message history.
	 *
	 * @return array<int, LLPhantMessage>
	 */
	private function history(): array {
		return [LLPhantMessage::user('What is due this week?')];
	}//end history()

	/**
	 * Fireworks answers in the chat completions shape; its usage is kept.
	 *
	 * @return void
	 */
	public function testFireworksReportsTheTokensOfItsAnswer(): void {
		$factory = $this->factory(
			[
				[
					'choices' => [['message' => ['content' => 'Three things.']]],
					'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150],
				],
			]
		);

		$text = $factory->callFireworksChat(
			credentialId: 'cred-fireworks',
			model: 'llama-v3p1-8b-instruct',
			baseUrl: 'https://api.fireworks.ai/inference/v1',
			messageHistory: $this->history()
		);

		$this->assertSame('Three things.', $text);
		$this->assertSame(['promptTokens' => 120, 'completionTokens' => 30], $factory->lastCallUsage());
	}//end testFireworksReportsTheTokensOfItsAnswer()

	/**
	 * Every request of an Anthropic tool loop counts, not only the last one.
	 *
	 * @return void
	 */
	public function testAnthropicSumsEveryRequestOfTheToolLoop(): void {
		$factory = $this->factory(
			[
				[
					'content' => [['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'list_cases', 'input' => []]],
					'stop_reason' => 'tool_use',
					'usage' => ['input_tokens' => 400, 'output_tokens' => 40],
				],
				[
					'content' => [['type' => 'text', 'text' => 'Two cases are due.']],
					'stop_reason' => 'end_turn',
					'usage' => ['input_tokens' => 520, 'output_tokens' => 60],
				],
			]
		);

		$text = $factory->callAnthropicChat(
			credentialId: 'cred-anthropic',
			model: 'claude-opus-4-8',
			baseUrl: 'https://api.anthropic.com/v1',
			messageHistory: $this->history(),
			functions: [['name' => 'list_cases', 'description' => 'List cases', 'parameters' => ['type' => 'object', 'properties' => []]]],
			toolExecutor: static fn (string $name, array $input): string => '[]'
		);

		$this->assertSame('Two cases are due.', $text);
		$this->assertSame(['promptTokens' => 920, 'completionTokens' => 100], $factory->lastCallUsage());
	}//end testAnthropicSumsEveryRequestOfTheToolLoop()

	/**
	 * The agent's tool call cap holds on the Anthropic loop: with a cap of 2 a
	 * model that keeps asking gets two tool calls, not the old fixed ten.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
	 */
	public function testAnthropicLoopStopsAtTheAgentsToolCallCap(): void {
		$askAgain = [
			'content' => [['type' => 'tool_use', 'id' => 'tu', 'name' => 'list_cases', 'input' => []]],
			'stop_reason' => 'tool_use',
			'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
		];
		$factory = $this->factory(array_fill(0, 12, $askAgain));

		$executed = 0;
		$factory->callAnthropicChat(
			credentialId: 'cred-anthropic',
			model: 'claude-opus-4-8',
			baseUrl: 'https://api.anthropic.com/v1',
			messageHistory: $this->history(),
			functions: [['name' => 'list_cases', 'description' => 'List cases', 'parameters' => ['type' => 'object', 'properties' => []]]],
			toolExecutor: static function (string $name, array $input) use (&$executed): string {
				$executed++;
				return '[]';
			},
			maxToolCalls: 2
		);

		$this->assertSame(2, $executed, 'The third tool call must not run.');
		$this->assertSame(3, $factory->lastCallUsage()['promptTokens'], 'Two tool rounds and one closing request.');
	}//end testAnthropicLoopStopsAtTheAgentsToolCallCap()

	/**
	 * A call that reports no usage does not inherit the previous call's.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutUsageReportsNone(): void {
		$factory = $this->factory(
			[
				[
					'content' => [['type' => 'text', 'text' => 'First.']],
					'stop_reason' => 'end_turn',
					'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
				],
				[
					'content' => [['type' => 'text', 'text' => 'Second.']],
					'stop_reason' => 'end_turn',
				],
			]
		);

		$factory->callAnthropicChat(
			credentialId: 'cred-anthropic',
			model: 'claude-opus-4-8',
			baseUrl: 'https://api.anthropic.com/v1',
			messageHistory: $this->history()
		);
		$this->assertSame(['promptTokens' => 10, 'completionTokens' => 2], $factory->lastCallUsage());

		$factory->callAnthropicChat(
			credentialId: 'cred-anthropic',
			model: 'claude-opus-4-8',
			baseUrl: 'https://api.anthropic.com/v1',
			messageHistory: $this->history()
		);
		$this->assertSame([], $factory->lastCallUsage());
	}//end testAnAnswerWithoutUsageReportsNone()
}//end class
