<?php

/**
 * The openai-compatible provider and the OpenAI base URL (models-provider-connections).
 *
 * Pins the transport rule: a base URL with no credential is called directly,
 * after the egress check, and a credential keeps the broker path; an OpenAI
 * provider without a base URL behaves as before. Also pins the refusals for a
 * missing base URL or model and for a base URL the egress check refuses.
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
 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\OpenAIChat;
use OCA\Hermiq\Kernel\Contracts\WebResearchEgressGuardInterface;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Driver construction for openai-compatible and for OpenAI with a base URL.
 *
 * @covers \OCA\Hermiq\Service\Llm\ProviderFactory
 * @uses \OCA\Hermiq\Service\Llm\ChatDriver
 * @uses \OCA\Hermiq\Service\Llm\ChatDriverRequest
 * @uses \OCA\Hermiq\Service\Llm\BrokerHttpClient
 * @uses \OCA\Hermiq\Service\Llm\ProviderUnavailableException
 *
 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md
 */
class ProviderFactoryOpenAiCompatibleTest extends TestCase {

	/**
	 * The egress guard double.
	 *
	 * @var WebResearchEgressGuardInterface&MockObject
	 */
	private WebResearchEgressGuardInterface&MockObject $guard;

	/**
	 * The calls the guard received: url, admin endpoint, insecure HTTP.
	 *
	 * @var list<array{0: string, 1: bool, 2: bool}>
	 */
	private array $checked = [];

	/**
	 * Set up a guard that allows every URL and records its calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->checked = [];
		$this->guard = $this->createMock(WebResearchEgressGuardInterface::class);
		$this->guard->method('assertSafe')->willReturnCallback(
			function (string $url, bool $isAdminConfiguredEndpoint, array $allowlist = [], array $denylist = [], bool $allowInsecureHttp = false): array {
				$this->checked[] = [$url, $isAdminConfiguredEndpoint, $allowInsecureHttp];
				return ['allowed' => true, 'code' => null, 'message' => null];
			}
		);
	}//end setUp()

	/**
	 * A factory with a session user and, unless told otherwise, a container that gives the guard.
	 *
	 * @param bool $withGuard Whether the container gives the egress guard.
	 *
	 * @return ProviderFactory
	 */
	private function factory(bool $withGuard=true): ProviderFactory {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$container = $this->createMock(ContainerInterface::class);
		if ($withGuard === true) {
			$container->method('get')->willReturnCallback(
				fn (string $id): object => match ($id) {
					WebResearchEgressGuardInterface::class => $this->guard,
					default => throw new \RuntimeException('Not in this container: ' . $id),
				}
			);
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('No guard'));
		}

		return new ProviderFactory(
			settingsHandler: $this->createMock(LlmSettingsHandler::class),
			taskManager: $this->createMock(IManager::class),
			userSession: $userSession,
			logger: new NullLogger(),
			container: $container
		);
	}//end factory()

	/**
	 * The base URI the OpenAI client of a chat instance sends to.
	 *
	 * @param OpenAIChat $chat The chat instance.
	 *
	 * @return string
	 */
	private function baseUriOf(OpenAIChat $chat): string {
		$client = (new \ReflectionProperty($chat, 'client'))->getValue($chat);
		$transporter = (new \ReflectionProperty($client, 'transporter'))->getValue($client);
		return (new \ReflectionProperty($transporter, 'baseUri'))->getValue($transporter)->toString();
	}//end baseUriOf()

	/**
	 * The PSR-18 client the OpenAI client of a chat instance sends through.
	 *
	 * @param OpenAIChat $chat The chat instance.
	 *
	 * @return object
	 */
	private function httpClientOf(OpenAIChat $chat): object {
		$client = (new \ReflectionProperty($chat, 'client'))->getValue($chat);
		$transporter = (new \ReflectionProperty($client, 'transporter'))->getValue($client);
		return (new \ReflectionProperty($transporter, 'client'))->getValue($transporter);
	}//end httpClientOf()

	/**
	 * A keyless endpoint is called directly at its base URL, after the egress check.
	 *
	 * @return void
	 */
	public function testAKeylessEndpointIsCalledAtItsBaseUrl(): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: [
				'chatProvider' => 'openai-compatible',
				'openaiCompatibleConfig' => [
					'label' => 'vLLM Rekencentrum',
					'baseUrl' => ' https://llm.rekencentrum.example.nl/v1/ ',
					'chatModel' => 'mistral-small-3.2',
				],
			],
			agentMaxTokens: 400
		);

		$this->assertSame('openai-compatible', $driver->provider);
		$this->assertInstanceOf(OpenAIChat::class, $driver->chat);
		$this->assertSame('mistral-small-3.2', $driver->model);
		$this->assertSame('mistral-small-3.2', $driver->chat->model);
		$this->assertNull($driver->credentialId);
		$this->assertSame('https://llm.rekencentrum.example.nl/v1/', $driver->baseUrl);
		$this->assertSame(400, $driver->maxTokens);
		$this->assertSame('https://llm.rekencentrum.example.nl/v1/', $this->baseUriOf(chat: $driver->chat));
		$this->assertNotInstanceOf(BrokerHttpClient::class, $this->httpClientOf(chat: $driver->chat));
		$this->assertSame([['https://llm.rekencentrum.example.nl/v1/', false, false]], $this->checked);
	}//end testAKeylessEndpointIsCalledAtItsBaseUrl()

	/**
	 * A server the admin states is on their own network may be private and plain HTTP.
	 *
	 * @return void
	 */
	public function testAnOwnNetworkServerLiftsThePrivateAddressAndHttpChecks(): void {
		$this->factory()->createChatDriver(
			llmConfig: [
				'chatProvider' => 'openai-compatible',
				'openaiCompatibleConfig' => ['baseUrl' => 'http://10.0.0.5:8000/v1', 'chatModel' => 'm', 'ownNetwork' => true],
			]
		);

		$this->assertSame([['http://10.0.0.5:8000/v1', true, true]], $this->checked);
	}//end testAnOwnNetworkServerLiftsThePrivateAddressAndHttpChecks()

	/**
	 * A base URL the egress check refuses is never called, and the refusal names the endpoint.
	 *
	 * @return void
	 */
	public function testARefusedBaseUrlIsNotUsed(): void {
		$guard = $this->createMock(WebResearchEgressGuardInterface::class);
		$guard->method('assertSafe')->willReturn(['allowed' => false, 'code' => 'private_address', 'message' => 'The host resolves to a private address.']);
		$this->guard = $guard;

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage('vLLM cannot be used: its base URL is refused. The host resolves to a private address.');
		$this->factory()->createChatDriver(
			llmConfig: [
				'chatProvider' => 'openai-compatible',
				'openaiCompatibleConfig' => ['label' => 'vLLM', 'baseUrl' => 'https://internal.example/v1', 'chatModel' => 'm'],
			]
		);
	}//end testARefusedBaseUrlIsNotUsed()

	/**
	 * Without an egress guard a keyless base URL is refused, never called unchecked.
	 *
	 * @return void
	 */
	public function testAKeylessBaseUrlIsRefusedWhenItCannotBeChecked(): void {
		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage('OpenAI-compatible endpoint cannot be used: its base URL cannot be checked on this instance.');
		$this->factory(withGuard: false)->createChatDriver(
			llmConfig: [
				'chatProvider' => 'openai-compatible',
				'openaiCompatibleConfig' => ['baseUrl' => 'https://llm.example/v1', 'chatModel' => 'm'],
			]
		);
	}//end testAKeylessBaseUrlIsRefusedWhenItCannotBeChecked()

	/**
	 * The endpoint needs a base URL and a model; the refusals say which.
	 *
	 * @return void
	 */
	public function testABaseUrlAndAModelAreRequired(): void {
		$messages = [];
		foreach ([
			['label' => 'Gateway', 'baseUrl' => '', 'chatModel' => 'm'],
			['label' => 'Gateway', 'baseUrl' => 'https://llm.example/v1', 'chatModel' => null],
		] as $config) {
			try {
				$this->factory()->createChatDriver(llmConfig: ['chatProvider' => 'openai-compatible', 'openaiCompatibleConfig' => $config]);
			} catch (ProviderUnavailableException $e) {
				$messages[] = [$e->getMessage(), $e->getCode()];
			}
		}

		$this->assertSame(
			[
				['Gateway has no base URL. Enter it in the Hermiq LLM settings.', 503],
				['Gateway has no model. Enter the model name the endpoint serves.', 503],
			],
			$messages
		);
		$this->assertSame([], $this->checked);
	}//end testABaseUrlAndAModelAreRequired()

	/**
	 * The agent's own model replaces the configured one.
	 *
	 * @return void
	 */
	public function testTheAgentsModelWins(): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: [
				'chatProvider' => 'openai-compatible',
				'openaiCompatibleConfig' => ['baseUrl' => 'https://llm.example/v1', 'chatModel' => 'configured'],
			],
			agentModel: 'agent-model'
		);

		$this->assertSame('agent-model', $driver->model);
	}//end testTheAgentsModelWins()

	/**
	 * A credential sends the call through the broker and skips the direct call.
	 *
	 * @return void
	 */
	public function testACredentialKeepsTheBrokerPath(): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: [
				'chatProvider' => 'openai-compatible',
				'openaiCompatibleConfig' => ['baseUrl' => 'https://llm.example/v1', 'chatModel' => 'm', 'credentialId' => 'cred-c'],
			]
		);

		$this->assertSame('cred-c', $driver->credentialId);
		$this->assertInstanceOf(BrokerHttpClient::class, $this->httpClientOf(chat: $driver->chat));
		$this->assertSame([], $this->checked);
	}//end testACredentialKeepsTheBrokerPath()

	/**
	 * OpenAI without a base URL goes through the broker as before; with a base URL
	 * and no credential it calls that base URL; with both it keeps the broker.
	 *
	 * @return void
	 */
	public function testTheOpenAiBaseUrlFollowsTheSameRule(): void {
		$plain = $this->factory()->createChatDriver(llmConfig: ['chatProvider' => 'openai', 'openaiConfig' => ['credentialId' => 'cred-o', 'chatModel' => 'gpt-x']]);
		$this->assertInstanceOf(BrokerHttpClient::class, $this->httpClientOf(chat: $plain->chat));
		$this->assertSame('cred-o', $plain->credentialId);

		$regional = $this->factory()->createChatDriver(llmConfig: ['chatProvider' => 'openai', 'openaiConfig' => ['baseUrl' => 'https://eu.llm.example/v1', 'chatModel' => 'gpt-x']]);
		$this->assertSame('https://eu.llm.example/v1/', $this->baseUriOf(chat: $regional->chat));
		$this->assertNotInstanceOf(BrokerHttpClient::class, $this->httpClientOf(chat: $regional->chat));
		$this->assertSame('', $regional->credentialId);

		$keyed = $this->factory()->createChatDriver(llmConfig: ['chatProvider' => 'openai', 'openaiConfig' => ['baseUrl' => 'https://eu.llm.example/v1', 'credentialId' => 'cred-o']]);
		$this->assertInstanceOf(BrokerHttpClient::class, $this->httpClientOf(chat: $keyed->chat));

		$this->assertSame([['https://eu.llm.example/v1', false, false]], $this->checked);
	}//end testTheOpenAiBaseUrlFollowsTheSameRule()

	/**
	 * OpenAI with neither a base URL nor a credential is still refused as before.
	 *
	 * @return void
	 */
	public function testOpenAiWithoutABaseUrlStillNeedsACredential(): void {
		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage('OpenAI has no credential.');
		$this->factory()->createChatDriver(llmConfig: ['chatProvider' => 'openai', 'openaiConfig' => ['chatModel' => 'gpt-x']]);
	}//end testOpenAiWithoutABaseUrlStillNeedsACredential()
}//end class
