<?php

/**
 * Unit tests for ProviderFactory in Responses API mode: the driver's mode and the two transports.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Kernel\Contracts\WebResearchEgressGuardInterface;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderCallFailedException;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The Responses API mode end to end over doubled transports.
 */
class ProviderFactoryResponsesTest extends TestCase {

	/**
	 * Direct calls made, as URL and options.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $direct = [];

	/**
	 * Broker calls made, as credential, path and decoded body.
	 *
	 * @var array<int,array{credential:mixed,path:mixed,body:mixed}>
	 */
	private array $brokered = [];

	/**
	 * A factory whose keyless client and broker both answer with this status and body.
	 *
	 * @param int                 $status The status.
	 * @param array<string,mixed> $body   The decoded body.
	 *
	 * @return ProviderFactory
	 */
	private function factory(int $status, array $body): ProviderFactory {
		$this->direct = [];
		$this->brokered = [];

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn((string)json_encode($body));
		$response->method('getHeader')->willReturn('');
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($response): IResponse {
				$this->direct[] = [$url, $options];
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (...$args) use ($status, $body): array {
				$this->brokered[] = ['credential' => $args[0], 'path' => $args[3], 'body' => json_decode((string)$args[5], true)];
				return ['status' => $status, 'headers' => [], 'body' => (string)json_encode($body)];
			}
		);

		$guard = $this->createMock(WebResearchEgressGuardInterface::class);
		$guard->method('assertSafe')->willReturn(['allowed' => true, 'code' => null, 'message' => null]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): ?object => match ($id) {
				IClientService::class => $clientService,
				BrokerHttpClient::BROKER_CLASS => $broker,
				WebResearchEgressGuardInterface::class => $guard,
				default => null,
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ProviderFactory(
			settingsHandler: $this->createMock(LlmSettingsHandler::class),
			taskManager: $this->createMock(IManager::class),
			userSession: $session,
			logger: new NullLogger(),
			container: $container
		);
	}//end factory()

	/**
	 * A text answer body.
	 *
	 * @return array<string,mixed>
	 */
	private function answer(): array {
		return [
			'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Antwoord.']]]],
			'usage' => ['input_tokens' => 7, 'output_tokens' => 2],
		];
	}//end answer()

	/**
	 * The apiMode setting reaches the driver; anything but `responses` is chat completions.
	 *
	 * @return void
	 */
	public function testTheApiModeReachesTheDriver(): void {
		$factory = $this->factory(status: 200, body: []);

		$responses = $factory->createChatDriver(llmConfig: [
			'chatProvider' => 'openai-compatible',
			'openaiCompatibleConfig' => ['baseUrl' => 'https://llm.example.nl/v1', 'chatModel' => 'm', 'apiMode' => 'responses', 'ownNetwork' => true],
		]);
		$other = $factory->createChatDriver(llmConfig: [
			'chatProvider' => 'openai-compatible',
			'openaiCompatibleConfig' => ['baseUrl' => 'https://llm.example.nl/v1', 'chatModel' => 'm', 'apiMode' => 'assistants'],
		]);

		$this->assertSame('responses', $responses->apiMode);
		$this->assertTrue($responses->ownNetwork);
		$this->assertSame('chat_completions', $other->apiMode);
		$this->assertFalse($other->ownNetwork);
	}//end testTheApiModeReachesTheDriver()

	/**
	 * An OpenAI driver without a base URL keeps api.openai.com, through the broker.
	 *
	 * @return void
	 */
	public function testOpenAiWithoutABaseUrlGoesToOpenAiThroughTheBroker(): void {
		$factory = $this->factory(status: 200, body: $this->answer());
		$driver = $factory->createChatDriver(llmConfig: [
			'chatProvider' => 'openai',
			'openaiConfig' => ['credentialId' => 'cred-openai', 'chatModel' => 'gpt-5', 'apiMode' => 'responses'],
		]);

		$answer = $factory->callOpenAiResponses(driver: $driver, messageHistory: [LLPhantMessage::system('Wees kort.'), LLPhantMessage::user('Vraag?')]);

		$this->assertSame('https://api.openai.com/v1', $driver->baseUrl);
		$this->assertSame('Antwoord.', $answer);
		$this->assertSame([], $this->direct);
		$this->assertCount(1, $this->brokered);
		$this->assertSame('cred-openai', $this->brokered[0]['credential']);
		$this->assertSame('/v1/responses', $this->brokered[0]['path']);
		$this->assertSame(
			['model' => 'gpt-5', 'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Vraag?']]]], 'store' => false, 'instructions' => 'Wees kort.'],
			$this->brokered[0]['body']
		);
		$this->assertSame(['promptTokens' => 7, 'completionTokens' => 2], $factory->lastCallUsage());
	}//end testOpenAiWithoutABaseUrlGoesToOpenAiThroughTheBroker()

	/**
	 * A keyless endpoint is called directly at its base URL, with local addresses only on the admin's say-so.
	 *
	 * @return void
	 */
	public function testAKeylessEndpointIsCalledDirectly(): void {
		$factory = $this->factory(status: 200, body: $this->answer());
		$driver = new ChatDriver(provider: 'openai-compatible', chat: null, model: 'm', baseUrl: 'http://10.0.0.5/v1/', apiMode: 'responses', ownNetwork: true);

		$this->assertSame('Antwoord.', $factory->callOpenAiResponses(driver: $driver, messageHistory: [LLPhantMessage::user('Hoi')]));

		$this->assertSame([], $this->brokered);
		$this->assertCount(1, $this->direct);
		$this->assertSame('http://10.0.0.5/v1/responses', $this->direct[0][0]);
		$this->assertSame(['allow_local_address' => true], $this->direct[0][1]['nextcloud']);
		$this->assertFalse(json_decode($this->direct[0][1]['body'], true)['store']);
	}//end testAKeylessEndpointIsCalledDirectly()

	/**
	 * An error status is a provider call failure that names the endpoint's message.
	 *
	 * @return void
	 */
	public function testAnErrorStatusFailsTheCall(): void {
		$factory = $this->factory(status: 429, body: ['error' => ['message' => 'Rate limit reached']]);
		$driver = new ChatDriver(provider: 'openai-compatible', chat: null, model: 'm', baseUrl: 'https://llm.example.nl/v1', apiMode: 'responses');

		$this->expectException(ProviderCallFailedException::class);
		$this->expectExceptionMessage('Responses API error (HTTP 429): Rate limit reached');

		$factory->callOpenAiResponses(driver: $driver, messageHistory: [LLPhantMessage::user('Hoi')]);
	}//end testAnErrorStatusFailsTheCall()

	/**
	 * Background generation follows the driver's Responses API mode too.
	 *
	 * @return void
	 */
	public function testBackgroundGenerationUsesTheResponsesApi(): void {
		$factory = $this->factory(status: 200, body: $this->answer());
		$method = new \ReflectionMethod(ProviderFactory::class, 'generateWithDriver');

		$text = $method->invoke($factory, new ChatDriver(provider: 'openai-compatible', chat: null, model: 'm', baseUrl: 'https://llm.example.nl/v1', apiMode: 'responses'), 'Titel?');

		$this->assertSame('Antwoord.', $text);
		$this->assertSame('https://llm.example.nl/v1/responses', $this->direct[0][0]);
	}//end testBackgroundGenerationUsesTheResponsesApi()
}//end class
