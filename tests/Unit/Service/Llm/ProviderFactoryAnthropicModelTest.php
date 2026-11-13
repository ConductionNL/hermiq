<?php

/**
 * Which model an Anthropic turn runs on, and how its transport modes normalise.
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
 *
 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderPlatform;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An agent's model override is used for an Anthropic turn only when it is a
 * claude-* id; any other override is logged and the configured model is used.
 */
class ProviderFactoryAnthropicModelTest extends TestCase {

	/**
	 * Warnings the factory logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $warnings = [];

	/**
	 * An Anthropic driver for this config and agent model.
	 *
	 * @param array       $anthropicConfig The anthropicConfig block.
	 * @param string|null $agentModel      The agent's model override.
	 *
	 * @return ChatDriver
	 */
	private function driver(array $anthropicConfig, ?string $agentModel = null): ChatDriver {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warnings[] = [(string)$message, $context];
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$factory = new ProviderFactory(
			settingsHandler: $this->createMock(LlmSettingsHandler::class),
			platform: new ProviderPlatform(
				taskManager: $this->createMock(IManager::class),
				userSession: $session
			),
			logger: $logger
		);

		return $factory->createChatDriver(
			llmConfig: ['chatProvider' => 'anthropic', 'anthropicConfig' => (['credentialId' => 'cred-a'] + $anthropicConfig)],
			agentModel: $agentModel
		);
	}//end driver()

	/**
	 * Without an override the configured chat model is used, and claude-opus-4-8 without one.
	 *
	 * @return void
	 */
	public function testWithoutAnOverrideTheConfiguredModelIsUsed(): void {
		$this->assertSame('claude-sonnet-x', $this->driver(anthropicConfig: ['chatModel' => 'claude-sonnet-x'])->model);
		$this->assertSame('claude-sonnet-x', $this->driver(anthropicConfig: ['chatModel' => 'claude-sonnet-x'], agentModel: '')->model);
		$this->assertSame('claude-opus-4-8', $this->driver(anthropicConfig: [])->model);
		$this->assertSame([], $this->warnings);
	}//end testWithoutAnOverrideTheConfiguredModelIsUsed()

	/**
	 * A claude-* override wins over the configured model, silently.
	 *
	 * @return void
	 */
	public function testAClaudeOverrideIsUsed(): void {
		$this->assertSame('claude-haiku-y', $this->driver(anthropicConfig: ['chatModel' => 'claude-sonnet-x'], agentModel: 'claude-haiku-y')->model);
		$this->assertSame([], $this->warnings);
	}//end testAClaudeOverrideIsUsed()

	/**
	 * Any other override is ignored for the configured model, and the warning names both.
	 *
	 * @return void
	 */
	public function testAForeignOverrideIsIgnoredAndLogged(): void {
		$driver = $this->driver(anthropicConfig: ['chatModel' => 'claude-sonnet-x'], agentModel: 'qwen3.5-optimized:latest');

		$this->assertSame('claude-sonnet-x', $driver->model);
		$this->assertCount(1, $this->warnings);
		$this->assertStringStartsWith("[ProviderFactory] Ignoring the agent's model override for an Anthropic turn", $this->warnings[0][0]);
		$this->assertSame('qwen3.5-optimized:latest', $this->warnings[0][1]['agentModel']);
		$this->assertSame('claude-sonnet-x', $this->warnings[0][1]['configuredModel']);
	}//end testAForeignOverrideIsIgnoredAndLogged()

	/**
	 * OAuth and the CLI transport are kept; anything else is the api key over HTTP, at the default base url.
	 *
	 * @return void
	 */
	public function testTheTransportModesNormalise(): void {
		$cli = $this->driver(anthropicConfig: ['authMode' => 'oauth', 'executionMode' => 'cli']);
		$this->assertSame(['anthropic', 'oauth', 'cli', 'cred-a'], [$cli->provider, $cli->authMode, $cli->executionMode, $cli->credentialId]);

		$other = $this->driver(anthropicConfig: ['executionMode' => 'grpc']);
		$this->assertSame(['api_key', 'http', 'https://api.anthropic.com/v1'], [$other->authMode, $other->executionMode, $other->baseUrl]);
		$this->assertNull($other->chat);
	}//end testTheTransportModesNormalise()
}//end class
