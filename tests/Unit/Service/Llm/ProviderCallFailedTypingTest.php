<?php

/**
 * Typed provider failures (models-several-models-per-turn task 2): every
 * HTTP failure of the Anthropic and Fireworks paths carries its kind and
 * retry-after, keeps its message, and says whether a fallback may answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-refusal-or-a-configuration-error-never-triggers-a-fallback-req-mfail-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use Exception;
use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderCallFailedException;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Llm\ProviderCallFailedException
 * @covers \OCA\Hermiq\Service\Llm\ProviderFactory
 * @uses   \OCA\Hermiq\Service\Llm\AnthropicChatRun
 * @uses   \OCA\Hermiq\Service\Llm\AnthropicMessageFormat
 * @uses   \OCA\Hermiq\Service\Llm\AnthropicToolLoop
 * @uses   \OCA\Hermiq\Service\Llm\BrokerHttpClient
 */
class ProviderCallFailedTypingTest extends TestCase {

	/**
	 * A factory whose broker answers with the given response, or throws it.
	 *
	 * @param array<string, mixed>|\Throwable $response The broker's answer.
	 *
	 * @return ProviderFactory
	 */
	private function factory(array|\Throwable $response): ProviderFactory {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			static function () use ($response): array {
				if ($response instanceof \Throwable) {
					throw $response;
				}

				return $response;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => ($id === BrokerHttpClient::BROKER_CLASS ? $broker : null));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ProviderFactory(
			$this->createMock(LlmSettingsHandler::class),
			$this->createMock(IManager::class),
			$session,
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
	 * The failure a provider call raises.
	 *
	 * @param string                          $provider anthropic or fireworks.
	 * @param array<string, mixed>|\Throwable $response The broker's answer.
	 *
	 * @return Exception
	 */
	private function failure(string $provider, array|\Throwable $response): Exception {
		$factory = $this->factory(response: $response);
		try {
			if ($provider === 'anthropic') {
				$factory->callAnthropicChat(credentialId: 'cred', model: 'claude-x', baseUrl: 'https://api.anthropic.com/v1', messageHistory: [LLPhantMessage::user('Hallo')]);
			} else {
				$factory->callFireworksChat(credentialId: 'cred', model: 'llama', baseUrl: 'https://api.fireworks.ai/inference/v1', messageHistory: [LLPhantMessage::user('Hallo')]);
			}
		} catch (Exception $e) {
			return $e;
		}

		$this->fail('The failure did not surface.');
	}//end failure()

	/**
	 * Per provider and status: [response, kind, retry-after, moves the turn, message prefix].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function statuses(): array {
		$error = (string)json_encode(['error' => ['message' => 'nope']]);
		$http = static fn (int $status, array $headers = []): array => ['status' => $status, 'headers' => $headers, 'body' => $error];

		return [
			'anthropic 429 retry-after' => ['anthropic', $http(429, ['retry-after' => ['30']]), 'rate_limit', 30, true, 'Rate limit exceeded. Please try again in 30 seconds.'],
			'anthropic 429 counters' => ['anthropic', $http(429, ['anthropic-ratelimit-tokens-remaining' => ['0']]), 'rate_limit', null, true, 'Rate limit exceeded. Please try again later.'],
			'anthropic 429 refused credential' => ['anthropic', $http(429), 'auth', null, false, 'Anthropic refused this credential'],
			'anthropic 500' => ['anthropic', $http(500), 'server', null, true, 'Anthropic API error (HTTP 500): nope'],
			'anthropic 529' => ['anthropic', $http(529), 'server', null, true, 'Anthropic API error (HTTP 529): nope'],
			'anthropic 401' => ['anthropic', $http(401), 'auth', null, false, 'Authentication failed.'],
			'anthropic 403' => ['anthropic', $http(403), 'auth', null, false, 'Authentication failed.'],
			'anthropic 400' => ['anthropic', $http(400), 'bad_request', null, false, 'Anthropic API error (HTTP 400): nope'],
			'anthropic 404' => ['anthropic', $http(404), 'bad_request', null, false, 'Model not found: claude-x.'],
			'fireworks 429' => ['fireworks', $http(429, ['retry-after' => ['12']]), 'rate_limit', 12, true, 'Rate limit exceeded. Please try again later.'],
			'fireworks 503' => ['fireworks', $http(503), 'server', null, true, 'Fireworks API error (HTTP 503): nope'],
			'fireworks 401' => ['fireworks', $http(401), 'auth', null, false, 'Authentication failed. Please check your Fireworks API key.'],
			'fireworks 400' => ['fireworks', $http(400), 'bad_request', null, false, 'Fireworks API error (HTTP 400): nope'],
		];
	}//end statuses()

	/**
	 * Each HTTP failure carries its kind, its retry-after and its old message.
	 *
	 * @param string               $provider   The provider.
	 * @param array<string, mixed> $response   The broker's answer.
	 * @param string               $kind       The kind.
	 * @param int|null             $retryAfter The retry-after seconds.
	 * @param bool                 $moves      Whether a fallback may answer.
	 * @param string               $message    The message prefix.
	 *
	 * @return void
	 *
	 * @dataProvider statuses
	 */
	public function testEachHttpFailureIsTyped(string $provider, array $response, string $kind, ?int $retryAfter, bool $moves, string $message): void {
		$failure = $this->failure(provider: $provider, response: $response);

		$this->assertInstanceOf(ProviderCallFailedException::class, $failure);
		$this->assertSame($kind, $failure->kind);
		$this->assertSame($response['status'], $failure->httpStatus);
		$this->assertSame($retryAfter, $failure->retryAfterSeconds);
		$this->assertSame($moves, $failure->movesTurn());
		$this->assertStringStartsWith($message, $failure->getMessage());
	}//end testEachHttpFailureIsTyped()

	/**
	 * A request that never got an answer keeps its message and is typed by what went wrong.
	 *
	 * @return void
	 */
	public function testATransportFailureIsTyped(): void {
		$failure = $this->failure(provider: 'anthropic', response: new RuntimeException('cURL error 28: Operation timed out after 30000 milliseconds'));

		$this->assertInstanceOf(ProviderCallFailedException::class, $failure);
		$this->assertStringStartsWith('Anthropic API request failed: ', $failure->getMessage());
		$this->assertSame(ProviderCallFailedException::TIMEOUT, $failure->kind);
		$this->assertTrue($failure->movesTurn());
	}//end testATransportFailureIsTyped()

	/**
	 * A client failure is typed once: through its response, its message, or as unknown; an unavailable provider moves the turn.
	 *
	 * @return void
	 */
	public function testAClientFailureIsTypedOnce(): void {
		$typed = ProviderCallFailedException::fromHttpStatus(status: 500, message: 'x');
		$this->assertSame($typed, ProviderCallFailedException::fromThrowable(failure: $typed));

		$this->assertSame(ProviderCallFailedException::UNAVAILABLE, ProviderCallFailedException::fromThrowable(failure: new ProviderUnavailableException('broker down'))->kind);
		$this->assertSame(ProviderCallFailedException::RATE_LIMIT, ProviderCallFailedException::fromThrowable(failure: new RuntimeException('OpenAI error: HTTP 429 Too Many Requests'))->kind);
		$this->assertSame(ProviderCallFailedException::SERVER, ProviderCallFailedException::fromThrowable(failure: new RuntimeException('Server responded with status code 502'))->kind);
		$this->assertSame(ProviderCallFailedException::CONNECTION, ProviderCallFailedException::fromThrowable(failure: new RuntimeException('cURL error 7: Failed to connect to localhost port 11434'))->kind);

		$unknown = ProviderCallFailedException::fromThrowable(failure: new RuntimeException('Something odd'));
		$this->assertSame(ProviderCallFailedException::UNKNOWN, $unknown->kind);
		$this->assertFalse($unknown->movesTurn());
		$this->assertSame('Something odd', $unknown->getMessage());
		$this->assertInstanceOf(RuntimeException::class, $unknown->getPrevious());
	}//end testAClientFailureIsTypedOnce()

	/**
	 * Retry-after is read as seconds or as an HTTP date; anything else is absent.
	 *
	 * @return void
	 */
	public function testRetryAfterIsSecondsOrADate(): void {
		$this->assertSame(45, ProviderCallFailedException::fromHttpStatus(status: 429, message: 'x', retryAfter: ' 45 ')->retryAfterSeconds);
		$inAMinute = gmdate('D, d M Y H:i:s', time() + 60) . ' GMT';
		$seconds = ProviderCallFailedException::fromHttpStatus(status: 503, message: 'x', retryAfter: $inAMinute)->retryAfterSeconds;
		$this->assertGreaterThanOrEqual(58, $seconds);
		$this->assertLessThanOrEqual(60, $seconds);
		$this->assertNull(ProviderCallFailedException::fromHttpStatus(status: 429, message: 'x', retryAfter: 'soon')->retryAfterSeconds);
		$this->assertSame(429, ProviderCallFailedException::fromHttpStatus(status: 429, message: 'x')->getCode());
	}//end testRetryAfterIsSecondsOrADate()
}//end class
