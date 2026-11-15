<?php

/**
 * Characterisation of the chat-driver options ProviderFactory applies.
 *
 * Pins, per provider, the model an agent override replaces, the temperature
 * and output ceiling that reach the client configuration, the defaults when
 * nothing is configured, and the messages of the misconfiguration refusals.
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

use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Driver options per provider.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-2-1
 */
class ProviderFactoryDriverOptionsTest extends TestCase {

	/**
	 * A factory with a session user and no optional collaborators.
	 *
	 * @return ProviderFactory
	 */
	private function factory(): ProviderFactory {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new ProviderFactory($this->createMock(LlmSettingsHandler::class), $this->createMock(IManager::class), $userSession, new NullLogger());
	}//end factory()

	/**
	 * The protected LLPhant config of a chat instance.
	 *
	 * @param object $chat The chat instance.
	 *
	 * @return object
	 */
	private function configOf(object $chat): object {
		$property = new \ReflectionProperty($chat, 'config');
		$property->setAccessible(true);
		return $property->getValue($chat);
	}//end configOf()

	/**
	 * The private model options of an OpenAI chat instance.
	 *
	 * @param OpenAIChat $chat The chat instance.
	 *
	 * @return array<string, mixed>
	 */
	private function openAiOptions(OpenAIChat $chat): array {
		$property = new \ReflectionProperty($chat, 'modelOptions');
		$property->setAccessible(true);
		return $property->getValue($chat);
	}//end openAiOptions()

	/**
	 * OpenAI: the agent model, temperature and ceiling reach the client config.
	 *
	 * @return void
	 */
	public function testOpenAiCarriesTheAgentsModelTemperatureAndCeiling(): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: ['chatProvider' => 'openai', 'openaiConfig' => ['credentialId' => 'cred-o', 'chatModel' => 'gpt-config']],
			agentModel: 'gpt-agent',
			agentTemperature: 0.3,
			agentMaxTokens: 700
		);

		$this->assertInstanceOf(OpenAIChat::class, $driver->chat);
		$this->assertSame('gpt-agent', $driver->model);
		$this->assertSame('cred-o', $driver->credentialId);
		$this->assertSame(700, $driver->maxTokens);
		$this->assertSame('gpt-agent', $driver->chat->model);
		$this->assertSame(['temperature' => 0.3, 'max_tokens' => 700], $this->openAiOptions(chat: $driver->chat));

	}//end testOpenAiCarriesTheAgentsModelTemperatureAndCeiling()

	/**
	 * OpenAI without a configured model uses gpt-4o-mini and sets no options.
	 *
	 * @return void
	 */
	public function testOpenAiDefaultsItsModelAndSetsNoOptions(): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: ['chatProvider' => 'openai', 'openaiConfig' => ['credentialId' => 'cred-o']],
			agentModel: ''
		);

		$this->assertSame('gpt-4o-mini', $driver->model);
		$this->assertNull($driver->maxTokens);
		$this->assertSame([], $this->openAiOptions(chat: $driver->chat));

	}//end testOpenAiDefaultsItsModelAndSetsNoOptions()

	/**
	 * Ollama: the url gets `/api/`, and the agent model, temperature and ceiling
	 * (as `num_predict`) reach the client config; the default model is llama2.
	 *
	 * @return void
	 */
	public function testOllamaCarriesTheAgentsOptionsAndDefaultsItsModel(): void {
		$factory = $this->factory();
		$driver = $factory->createChatDriver(
			llmConfig: ['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => 'http://ollama:11434/']],
			agentModel: 'mistral',
			agentTemperature: 0.9,
			agentMaxTokens: 64
		);

		$this->assertInstanceOf(OllamaChat::class, $driver->chat);
		$config = $this->configOf($driver->chat);
		$this->assertSame('http://ollama:11434/api/', $config->url);
		$this->assertSame('mistral', $driver->model);
		$this->assertSame(['temperature' => 0.9, 'num_predict' => 64], $config->modelOptions);

		$plain = $factory->createChatDriver(llmConfig: ['chatProvider' => 'ollama', 'ollamaConfig' => ['url' => 'http://ollama:11434']]);
		$this->assertSame('llama2', $plain->model);
		$this->assertSame([], $this->configOf($plain->chat)->modelOptions);

	}//end testOllamaCarriesTheAgentsOptionsAndDefaultsItsModel()

	/**
	 * Fireworks: the agent model replaces the configured one and a base url
	 * already ending in /v1 is kept.
	 *
	 * @return void
	 */
	public function testFireworksTakesTheAgentsModel(): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: ['chatProvider' => 'fireworks', 'fireworksConfig' => ['credentialId' => 'cred-f', 'chatModel' => 'fw-config', 'baseUrl' => 'https://fw/v1/']],
			agentModel: 'fw-agent',
			agentTemperature: 0.5,
			agentMaxTokens: 10
		);

		$this->assertSame('fw-agent', $driver->model);
		$this->assertSame('https://fw/v1', $driver->baseUrl);
		$this->assertNull($driver->maxTokens);

	}//end testFireworksTakesTheAgentsModel()

	/**
	 * Anthropic: the ceiling is carried, an unknown auth mode is the api key, and
	 * the base url loses its trailing slash.
	 *
	 * @return void
	 */
	public function testAnthropicCarriesTheCeilingAndNormalisesItsModes(): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: ['chatProvider' => 'anthropic', 'anthropicConfig' => ['credentialId' => 'cred-a', 'authMode' => 'other', 'baseUrl' => 'https://a/v1/']],
			agentMaxTokens: 300
		);

		$this->assertSame(300, $driver->maxTokens);
		$this->assertSame('api_key', $driver->authMode);
		$this->assertSame('https://a/v1', $driver->baseUrl);
		$this->assertSame('cred-a', $driver->credentialId);

	}//end testAnthropicCarriesTheCeilingAndNormalisesItsModes()

	/**
	 * The misconfiguration refusals and their messages.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'no provider' => [[], 'Chat provider is not configured. Please configure OpenAI, Anthropic, Fireworks AI, Ollama, or Nextcloud Assistant in settings.'],
			'openai without credential' => [['chatProvider' => 'openai'], 'OpenAI has no credential. Select one from the credential broker in the Hermiq LLM settings.'],
			'fireworks without credential' => [['chatProvider' => 'fireworks'], 'Fireworks AI has no credential. Select one from the credential broker in the Hermiq LLM settings.'],
			'anthropic without credential' => [['chatProvider' => 'anthropic'], 'Anthropic has no credential. Select one from the credential broker in the Hermiq LLM settings.'],
			'ollama without url' => [['chatProvider' => 'ollama'], 'Ollama URL is not configured'],
			'unknown provider' => [['chatProvider' => 'azure'], 'Unsupported chat provider: azure'],
		];
	}//end refusals()

	/**
	 * Each misconfiguration is refused with its message.
	 *
	 * @param array<string, mixed> $llmConfig The configuration.
	 * @param string               $message   The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testEachMisconfigurationIsRefused(array $llmConfig, string $message): void {
		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage($message);

		$this->factory()->createChatDriver(llmConfig: $llmConfig);

	}//end testEachMisconfigurationIsRefused()
}//end class
