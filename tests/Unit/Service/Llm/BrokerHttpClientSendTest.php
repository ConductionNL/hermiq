<?php

/**
 * Golden test for BrokerHttpClient::sendRequest: the fail-closed refusals, the
 * exact broker arguments (credential, app id, method, path with query, headers
 * without auth, body, acting user), the method-and-path-only failure log and
 * the response defaults.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use GuzzleHttp\Psr7\Request;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class BrokerHttpClientSendTest extends TestCase {

	/**
	 * Log lines: [level, message, context].
	 *
	 * @var array<int, array{0: mixed, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	public function testWithoutAContainerItRefusesToCall(): void {
		$client = new BrokerHttpClient(credentialId: 'cred-1', logger: $this->logger());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(
			'Hermiq LLM: this client was constructed without a container, so the OpenRegister '
			. 'credential broker cannot be resolved; refusing to call the provider.'
		);

		$client->sendRequest(new Request('POST', 'https://api.example.org/v1/chat'));
	}//end testWithoutAContainerItRefusesToCall()

	public function testTheCredentialIsCheckedBeforeTheContainer(): void {
		$client = new BrokerHttpClient(credentialId: '', logger: $this->logger());

		$this->expectExceptionMessage('Hermiq LLM: no broker credential is configured for this provider.');

		$client->sendRequest(new Request('POST', 'https://api.example.org/v1/chat'));
	}//end testTheCredentialIsCheckedBeforeTheContainer()

	public function testItSendsTheExactBrokerCall(): void {
		$broker = $this->broker(response: ['status' => 201, 'headers' => ['retry-after' => ['3']], 'body' => '{"ok":1}']);
		$client = new BrokerHttpClient(credentialId: 'cred-1', logger: $this->logger(), actingUserId: 'alice', container: $this->container(broker: $broker));

		$response = $client->sendRequest(
			new Request('POST', 'https://api.example.org/v1/chat?beta=1&x=2', ['Authorization' => 'Bearer k', 'Content-Type' => 'application/json'], '{"q":1}')
		);

		self::assertSame([['cred-1', 'hermiq', 'POST', '/v1/chat?beta=1&x=2', ['Host' => 'api.example.org', 'Content-Type' => 'application/json'], '{"q":1}', 'alice']], $broker->calls);
		self::assertSame(201, $response->getStatusCode());
		self::assertSame('{"ok":1}', (string)$response->getBody());
		self::assertSame(['3'], $response->getHeader('retry-after'));
		self::assertSame([], $this->logs);
	}//end testItSendsTheExactBrokerCall()

	public function testAnEmptyPathBecomesTheRootAndAMissingResponseIsABadGateway(): void {
		$broker = $this->broker(response: []);
		$client = new BrokerHttpClient(credentialId: 'cred-1', logger: $this->logger(), container: $this->container(broker: $broker));

		$response = $client->sendRequest(new Request('GET', 'https://api.example.org'));

		self::assertSame('/', $broker->calls[0][3]);
		self::assertNull($broker->calls[0][6]);
		self::assertSame('', $broker->calls[0][5]);
		self::assertSame(502, $response->getStatusCode());
		self::assertSame('', (string)$response->getBody());
		self::assertSame(['application/json'], $response->getHeader('Content-Type'));
	}//end testAnEmptyPathBecomesTheRootAndAMissingResponseIsABadGateway()

	public function testARootPathKeepsItsQuery(): void {
		$broker = $this->broker(response: ['status' => 200]);
		$client = new BrokerHttpClient(credentialId: 'cred-1', logger: $this->logger(), container: $this->container(broker: $broker));

		$client->sendRequest(new Request('GET', 'https://api.example.org?a=b'));

		self::assertSame('/?a=b', $broker->calls[0][3]);
	}//end testARootPathKeepsItsQuery()

	public function testABrokerFailureIsLoggedWithoutTheBodyAndRethrownWrapped(): void {
		$cause = new RuntimeException('secret detail');
		$broker = $this->broker(response: [], failure: $cause);
		$client = new BrokerHttpClient(credentialId: 'cred-1', logger: $this->logger(), container: $this->container(broker: $broker));

		try {
			$client->sendRequest(new Request('PUT', 'https://api.example.org/v1/x?y=1', [], 'prompt text'));
			self::fail('Expected the broker failure to surface.');
		} catch (RuntimeException $error) {
			self::assertSame('Hermiq LLM: the credential broker refused or could not make the call.', $error->getMessage());
			self::assertSame(0, $error->getCode());
			self::assertSame($cause, $error->getPrevious());
		}

		self::assertSame([['warning', '[Hermiq] LLM broker call failed', ['method' => 'PUT', 'path' => '/v1/x?y=1']]], $this->logs);
	}//end testABrokerFailureIsLoggedWithoutTheBodyAndRethrownWrapped()

	public function testAContainerFailureIsWrappedToo(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no service'));
		$client = new BrokerHttpClient(credentialId: 'cred-1', logger: $this->logger(), container: $container);

		$this->expectExceptionMessage('Hermiq LLM: the credential broker refused or could not make the call.');

		try {
			$client->sendRequest(new Request('GET', 'https://api.example.org/v1'));
		} finally {
			self::assertSame([['warning', '[Hermiq] LLM broker call failed', ['method' => 'GET', 'path' => '/v1']]], $this->logs);
		}
	}//end testAContainerFailureIsWrappedToo()

	/**
	 * A recording broker double.
	 *
	 * @param array<string, mixed>  $response The broker's answer.
	 * @param RuntimeException|null $failure  Thrown instead, when given.
	 *
	 * @return object
	 */
	private function broker(array $response, ?RuntimeException $failure = null): object {
		return new class($response, $failure) {
			public array $calls = [];

			public function __construct(private array $response, private ?RuntimeException $failure) {
			}

			public function request(string $credentialId, string $appId, string $method, string $path, array $headers, string $body, ?string $actingUserId): array {
				$this->calls[] = [$credentialId, $appId, $method, $path, $headers, $body, $actingUserId];
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return $this->response;
			}
		};
	}//end broker()

	private function container(object $broker): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BrokerHttpClient::BROKER_CLASS)->willReturn($broker);
		return $container;
	}//end container()

	private function logger(): AbstractLogger {
		$logs = &$this->logs;
		return new class($logs) extends AbstractLogger {
			public function __construct(private array &$logs) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->logs[] = [$level, (string)$message, $context];
			}
		};
	}//end logger()
}//end class
