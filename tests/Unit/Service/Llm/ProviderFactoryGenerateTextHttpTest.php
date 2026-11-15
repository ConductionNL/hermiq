<?php

/**
 * generateText() on the providers that answer over direct HTTP.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

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
 * A single prompt is sent as one user message to the configured provider, on
 * the configured credential and model, and its text comes back.
 */
class ProviderFactoryGenerateTextHttpTest extends TestCase {

	/**
	 * The broker requests sent, as credential, path and decoded body.
	 *
	 * @var array<int, array{credential: mixed, path: mixed, body: mixed}>
	 */
	private array $requests = [];

	/**
	 * A factory configured with this LLM config whose broker answers with this response.
	 *
	 * @param array $llmConfig The stored LLM settings.
	 * @param array $response  The broker's response.
	 *
	 * @return ProviderFactory
	 */
	private function factory(array $llmConfig, array $response): ProviderFactory {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (...$args) use ($response): array {
				$this->requests[] = ['credential' => $args[0], 'path' => $args[3], 'body' => json_decode((string)$args[5], true)];
				return $response;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($id === BrokerHttpClient::BROKER_CLASS ? $broker : null)
		);
		$settings = $this->createMock(LlmSettingsHandler::class);
		$settings->method('getLLMSettingsOnly')->willReturn($llmConfig);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ProviderFactory($settings, $this->createMock(IManager::class), $session, new NullLogger(), 'hermiq', null, null, null, null, null, null, $container);
	}//end factory()

	/**
	 * An Anthropic prompt goes out as one user message on the configured model.
	 *
	 * @return void
	 */
	public function testAnAnthropicPromptIsOneUserMessage(): void {
		$factory = $this->factory(
			llmConfig: ['chatProvider' => 'anthropic', 'anthropicConfig' => ['credentialId' => 'cred-a', 'chatModel' => 'claude-sonnet-x']],
			response: ['status' => 200, 'headers' => [], 'body' => (string)json_encode(['content' => [['type' => 'text', 'text' => 'Samenvatting.']], 'stop_reason' => 'end_turn'])]
		);

		$this->assertSame('Samenvatting.', $factory->generateText(prompt: 'Vat samen.', allowNextcloud: false));
		$this->assertCount(1, $this->requests);
		$this->assertSame('cred-a', $this->requests[0]['credential']);
		$this->assertStringEndsWith('/v1/messages', $this->requests[0]['path']);
		$this->assertSame('claude-sonnet-x', $this->requests[0]['body']['model']);
		$this->assertSame([['role' => 'user', 'content' => 'Vat samen.']], $this->requests[0]['body']['messages']);
	}//end testAnAnthropicPromptIsOneUserMessage()

	/**
	 * A Fireworks prompt goes out as one user message to its chat completions endpoint.
	 *
	 * @return void
	 */
	public function testAFireworksPromptIsOneUserMessage(): void {
		$factory = $this->factory(
			llmConfig: ['chatProvider' => 'fireworks', 'fireworksConfig' => ['credentialId' => 'cred-f', 'chatModel' => 'accounts/fireworks/models/llama']],
			response: ['status' => 200, 'headers' => [], 'body' => (string)json_encode(['choices' => [['message' => ['content' => 'Antwoord.']]]])]
		);

		$this->assertSame('Antwoord.', $factory->generateText(prompt: 'Vraag?', allowNextcloud: false));
		$this->assertCount(1, $this->requests);
		$this->assertSame('cred-f', $this->requests[0]['credential']);
		$this->assertStringEndsWith('/chat/completions', $this->requests[0]['path']);
		$this->assertSame('accounts/fireworks/models/llama', $this->requests[0]['body']['model']);
		$this->assertSame([['role' => 'user', 'content' => 'Vraag?']], $this->requests[0]['body']['messages']);
	}//end testAFireworksPromptIsOneUserMessage()
}//end class
