<?php

/**
 * Characterisation of ProviderFactory's Fireworks chat call.
 *
 * Pins the request it sends (endpoint, headers, model, mapped messages), that a
 * turn's tools are ignored, the answer and usage it reads back, and how each
 * HTTP failure is reported.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use Exception;
use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderGovernance;
use OCA\Hermiq\Service\Llm\ProviderPlatform;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The Fireworks chat call of ProviderFactory.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */
class ProviderFactoryFireworksTest extends TestCase {

	/**
	 * The requests the broker saw: credential, path, headers and decoded body.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $requests = [];

	/**
	 * A factory whose broker answers with the given response.
	 *
	 * @param array<string, mixed> $response The broker response (status, headers, body).
	 *
	 * @return ProviderFactory
	 */
	private function factory(array $response): ProviderFactory {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (...$args) use ($response): array {
				$this->requests[] = [
					'credential' => $args[0],
					'path' => $args[3],
					'headers' => $args[4],
					'body' => json_decode((string)$args[5], true),
				];
				return $response;
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
			settingsHandler: $this->createMock(LlmSettingsHandler::class),
			platform: new ProviderPlatform(
				taskManager: $this->createMock(IManager::class),
				userSession: $userSession,
				appManager: null,
				urlGenerator: null,
				appConfig: null,
				container: $container
			),
			logger: new NullLogger(),
			appName: 'hermiq',
			governance: new ProviderGovernance(
				modelPolicyService: null,
				credentialResolver: null,
				runTokenService: null
			)
		);
	}//end factory()

	/**
	 * One chat completion: the request, the answer and the usage.
	 *
	 * @return void
	 */
	public function testACompletionIsRequestedAndRead(): void {
		$factory = $this->factory(
			[
				'status' => 200,
				'headers' => [],
				'body' => (string)json_encode(['choices' => [['message' => ['content' => 'Answer.']]], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3]]),
			]
		);

		$text = $factory->callFireworksChat(
			credentialId: 'cred-fw',
			model: 'fw-model',
			baseUrl: 'https://api.fireworks.ai/inference/v1/',
			messageHistory: [LLPhantMessage::system('Be brief.'), LLPhantMessage::user('Hi')],
			functions: [['name' => 'ignored']]
		);

		$this->assertSame('Answer.', $text);
		$this->assertSame(['promptTokens' => 12, 'completionTokens' => 3], $factory->lastCallUsage());
		$this->assertCount(1, $this->requests);
		$this->assertSame('cred-fw', $this->requests[0]['credential']);
		$this->assertStringEndsWith('/v1/chat/completions', $this->requests[0]['path']);
		$this->assertSame('fw-model', $this->requests[0]['body']['model']);
		$this->assertSame(['model', 'messages'], array_keys($this->requests[0]['body']));
		$this->assertSame('system', $this->requests[0]['body']['messages'][0]['role']);
		$this->assertSame('user', $this->requests[0]['body']['messages'][1]['role']);
	}//end testACompletionIsRequestedAndRead()

	/**
	 * An answer without usage reports none.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutUsageReportsNone(): void {
		$factory = $this->factory(['status' => 200, 'headers' => [], 'body' => (string)json_encode(['choices' => [['message' => ['content' => 'A']]], 'usage' => 'odd'])]);

		$factory->callFireworksChat(credentialId: 'c', model: 'm', baseUrl: 'https://fw/v1', messageHistory: [LLPhantMessage::user('Hi')]);

		$this->assertSame([], $factory->lastCallUsage());
	}//end testAnAnswerWithoutUsageReportsNone()

	/**
	 * Each failure and its message.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function failures(): array {
		$error = (string)json_encode(['error' => ['message' => 'bad request']]);
		return [
			'401' => [['status' => 401, 'headers' => [], 'body' => $error], 'Authentication failed. Please check your Fireworks API key.'],
			'403' => [['status' => 403, 'headers' => [], 'body' => $error], 'Authentication failed. Please check your Fireworks API key.'],
			'404' => [['status' => 404, 'headers' => [], 'body' => $error], 'Model not found: m. Please check the model name.'],
			'429' => [['status' => 429, 'headers' => [], 'body' => $error], 'Rate limit exceeded. Please try again later.'],
			'500 with message' => [['status' => 500, 'headers' => [], 'body' => $error], 'Fireworks API error (HTTP 500): bad request'],
			'500 with string error' => [['status' => 500, 'headers' => [], 'body' => (string)json_encode(['error' => 'flat'])], 'Fireworks API error (HTTP 500): flat'],
			'500 plain body' => [['status' => 500, 'headers' => [], 'body' => 'oops'], 'Fireworks API error (HTTP 500): oops'],
			'200 without content' => [['status' => 200, 'headers' => [], 'body' => '{"choices":[]}'], 'Unexpected Fireworks API response format: {"choices":[]}'],
		];
	}//end failures()

	/**
	 * Each failure surfaces with its message.
	 *
	 * @param array<string, mixed> $response The broker response.
	 * @param string               $message  The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider failures
	 */
	public function testEachFailureHasItsMessage(array $response, string $message): void {
		$factory = $this->factory($response);

		try {
			$factory->callFireworksChat(credentialId: 'c', model: 'm', baseUrl: 'https://fw/v1', messageHistory: [LLPhantMessage::user('Hi')]);
			$this->fail('The failure did not surface.');
		} catch (Exception $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}//end testEachFailureHasItsMessage()
}//end class
