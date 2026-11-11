<?php

/**
 * Characterisation of ProviderFactory's Anthropic Messages (http) path.
 *
 * Pins the request each round sends (endpoint, headers, model, ceiling, the
 * hoisted system prompt, tools only beside an executor), the tool loop's
 * message echo, the text a capped turn ends with, and how each HTTP failure
 * is reported.
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
 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use Exception;
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
 * The Anthropic http path of ProviderFactory.
 *
 * @spec openspec/changes/anthropic-agent-provider/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
 */
class ProviderFactoryAnthropicHttpTest extends TestCase {

	/**
	 * The requests the broker saw: path, headers and decoded body.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * A factory whose broker answers each request with the next response in line.
	 *
	 * @param array<int, array<string, mixed>> $responses Broker responses (status, headers, body).
	 *
	 * @return ProviderFactory
	 */
	private function factory(array $responses): ProviderFactory {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (...$args) use (&$responses): array {
				$this->requests[] = [
					'path' => $args[3],
					'headers' => $args[4],
					'body' => json_decode((string)$args[5], true),
				];
				return array_shift($responses);
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
	 * A 200 response carrying the given decoded body.
	 *
	 * @param array<string, mixed> $body The decoded body.
	 *
	 * @return array<string, mixed>
	 */
	private function ok(array $body): array {
		return [
			'status' => 200,
			'headers' => ['content-type' => ['application/json']],
			'body' => (string)json_encode($body),
		];
	}//end ok()

	/**
	 * A tool descriptor.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function functions(): array {
		return [['name' => 'list_cases', 'description' => 'List cases', 'parameters' => ['type' => 'object', 'properties' => []]]];
	}//end functions()

	/**
	 * One text round: the system prompt is hoisted, the ceiling defaults to 4096,
	 * no tools are offered without an executor, and the api-key headers are sent.
	 *
	 * @return void
	 */
	public function testATextTurnSendsOneRequestWithTheHoistedSystemPrompt(): void {
		$factory = $this->factory([$this->ok(['content' => [['type' => 'text', 'text' => 'Hi.'], ['type' => 'text', 'text' => ' There.']], 'stop_reason' => 'end_turn'])]);

		$text = $factory->callAnthropicChat(
			credentialId: 'cred',
			model: 'claude-x',
			baseUrl: 'https://api.anthropic.com/v1/',
			messageHistory: [LLPhantMessage::system('Be brief.'), LLPhantMessage::user('Hello')],
			functions: $this->functions()
		);

		$this->assertSame('Hi. There.', $text);
		$this->assertCount(1, $this->requests);
		$this->assertStringEndsWith('/v1/messages', $this->requests[0]['path']);
		$body = $this->requests[0]['body'];
		$this->assertSame('claude-x', $body['model']);
		$this->assertSame(4096, $body['max_tokens']);
		$this->assertSame('Be brief.', $body['system']);
		$this->assertArrayNotHasKey('tools', $body);
		$this->assertSame([['role' => 'user', 'content' => 'Hello']], $body['messages']);
		$this->assertSame('2023-06-01', $this->requests[0]['headers']['anthropic-version'] ?? null);

	}//end testATextTurnSendsOneRequestWithTheHoistedSystemPrompt()

	/**
	 * A tool round echoes the assistant turn and sends the tool results back; the
	 * oauth headers and an explicit ceiling are used, and no system field is sent
	 * without a system turn.
	 *
	 * @return void
	 */
	public function testAToolRoundEchoesTheAssistantTurnAndTheResults(): void {
		$toolUse = ['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'list_cases', 'input' => ['q' => 'x']];
		$factory = $this->factory(
			[
				$this->ok(['content' => [$toolUse], 'stop_reason' => 'tool_use']),
				$this->ok(['content' => [['type' => 'text', 'text' => 'Done.']], 'stop_reason' => 'end_turn']),
			]
		);

		$seen = [];
		$text = $factory->callAnthropicChat(
			credentialId: 'cred',
			model: 'claude-x',
			baseUrl: 'https://api.anthropic.com/v1',
			messageHistory: [LLPhantMessage::user('Hello')],
			authMode: 'oauth',
			maxTokens: 900,
			functions: $this->functions(),
			toolExecutor: static function (string $name, array $input) use (&$seen): string {
				$seen[] = [$name, $input];
				return '["case-1"]';
			}
		);

		$this->assertSame('Done.', $text);
		$this->assertSame([['list_cases', ['q' => 'x']]], $seen);
		$this->assertCount(2, $this->requests);
		$first = $this->requests[0]['body'];
		$this->assertSame(900, $first['max_tokens']);
		$this->assertArrayNotHasKey('system', $first);
		$this->assertSame('list_cases', $first['tools'][0]['name']);
		$this->assertSame('oauth-2025-04-20', $this->requests[0]['headers']['anthropic-beta'] ?? null);
		$second = $this->requests[1]['body']['messages'];
		$this->assertCount(3, $second);
		$this->assertSame(['role' => 'assistant', 'content' => [$toolUse]], $second[1]);
		$this->assertSame('user', $second[2]['role']);
		$this->assertSame([['type' => 'tool_result', 'tool_use_id' => 'tu-1', 'content' => '["case-1"]']], $second[2]['content']);

	}//end testAToolRoundEchoesTheAssistantTurnAndTheResults()

	/**
	 * A turn that hits its tool call cap with no text ends with the limit
	 * message, and a second tool call in the same round is answered with the
	 * limit error instead of running.
	 *
	 * @return void
	 */
	public function testACappedTurnWithoutTextSaysTheLimitWasReached(): void {
		$twoCalls = [
			'content' => [
				['type' => 'tool_use', 'id' => 'a', 'name' => 'list_cases', 'input' => []],
				['type' => 'tool_use', 'id' => 'b', 'name' => 'list_cases', 'input' => 'not-an-array'],
			],
			'stop_reason' => 'tool_use',
		];
		$factory = $this->factory([$this->ok($twoCalls), $this->ok($twoCalls)]);

		$executed = 0;
		$text = $factory->callAnthropicChat(
			credentialId: 'cred',
			model: 'claude-x',
			baseUrl: 'https://api.anthropic.com/v1',
			messageHistory: [LLPhantMessage::user('Hello')],
			functions: $this->functions(),
			toolExecutor: static function (string $name, array $input) use (&$executed): string {
				$executed++;
				return '[]';
			},
			maxToolCalls: 1
		);

		$this->assertSame('Tool call limit reached for this turn.', $text);
		$this->assertSame(1, $executed);
		$this->assertTrue($factory->lastCallHitToolCap());
		$results = $this->requests[1]['body']['messages'][2]['content'];
		$this->assertSame('[]', $results[0]['content']);
		$this->assertSame('{"isError":true,"result":{"error":"Tool call limit reached for this turn"}}', $results[1]['content']);

	}//end testACappedTurnWithoutTextSaysTheLimitWasReached()

	/**
	 * Each HTTP failure maps to its own message.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function failures(): array {
		$error = (string)json_encode(['error' => ['message' => 'bad request']]);
		return [
			'401' => [['status' => 401, 'headers' => [], 'body' => $error], 'Authentication failed. Please check your Anthropic credential.'],
			'403' => [['status' => 403, 'headers' => [], 'body' => $error], 'Authentication failed. Please check your Anthropic credential.'],
			'404' => [['status' => 404, 'headers' => [], 'body' => $error], 'Model not found: claude-x. Please check the model name.'],
			'429 with retry-after' => [['status' => 429, 'headers' => ['retry-after' => ['30']], 'body' => $error], 'Rate limit exceeded. Please try again in 30 seconds.'],
			'429 with counters' => [['status' => 429, 'headers' => ['anthropic-ratelimit-tokens-remaining' => ['0']], 'body' => $error], 'Rate limit exceeded. Please try again later.'],
			'429 without counters' => [['status' => 429, 'headers' => [], 'body' => $error], 'Anthropic refused this credential for the Messages API'],
			'500 with message' => [['status' => 500, 'headers' => [], 'body' => $error], 'Anthropic API error (HTTP 500): bad request'],
			'500 with string error' => [['status' => 500, 'headers' => [], 'body' => (string)json_encode(['error' => 'flat'])], 'Anthropic API error (HTTP 500): flat'],
			'500 with odd error' => [['status' => 500, 'headers' => [], 'body' => (string)json_encode(['error' => ['code' => 1]])], 'Anthropic API error (HTTP 500): Unknown error'],
			'500 plain body' => [['status' => 500, 'headers' => [], 'body' => 'oops'], 'Anthropic API error (HTTP 500): oops'],
			'200 not json' => [['status' => 200, 'headers' => [], 'body' => 'oops'], 'Unexpected Anthropic API response format: oops'],
		];
	}//end failures()

	/**
	 * Each HTTP failure surfaces with its message.
	 *
	 * @param array<string, mixed> $response The broker response.
	 * @param string               $message  The expected message (prefix).
	 *
	 * @return void
	 *
	 * @dataProvider failures
	 */
	public function testEachHttpFailureHasItsMessage(array $response, string $message): void {
		$factory = $this->factory([$response]);

		try {
			$factory->callAnthropicChat(
				credentialId: 'cred',
				model: 'claude-x',
				baseUrl: 'https://api.anthropic.com/v1',
				messageHistory: [LLPhantMessage::user('Hello')]
			);
			$this->fail('The failure did not surface.');
		} catch (Exception $e) {
			$this->assertStringStartsWith($message, $e->getMessage());
		}

	}//end testEachHttpFailureHasItsMessage()
}//end class
