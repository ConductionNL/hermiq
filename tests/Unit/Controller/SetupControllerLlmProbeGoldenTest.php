<?php

/**
 * Golden tests for the first-time-setup LLM probe (`runAction('test-llm')`).
 *
 * Pins which endpoints the probe is allowed to reach (the SSRF allow-list:
 * http(s) on localhost, 127.0.0.1, ::1 or host.docker.internal only) and which
 * it refuses without any request, the exact request it makes, and every answer:
 * no models, one model, several, unreachable — with what it writes to app
 * config and logs. Written before the probe was split into named steps.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SetupController;
use OCA\Hermiq\Service\DemoDataService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden answers of SetupController's LLM probe.
 */
class SetupControllerLlmProbeGoldenTest extends TestCase {

	/**
	 * App-config writes, key => value.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Requests made, as [url, options].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $requests = [];

	/**
	 * Debug log lines.
	 *
	 * @var array<int, string>
	 */
	private array $debug = [];

	/**
	 * Probe with the given configured endpoint and host answer.
	 *
	 * @param string $endpoint The configured `llmendpoint` ('' when unset).
	 * @param string|\Throwable $answer The /api/tags body, or what the request throws.
	 *
	 * @return array<string, mixed> The response data.
	 */
	private function probe(string $endpoint, string|\Throwable $answer): array {
		$this->written = [];
		$this->requests = [];
		$this->debug = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'llmendpoint' ? $endpoint : $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			}
		);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($answer): IResponse {
				$this->requests[] = [$url, $options];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($answer);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message): void {
				$this->debug[] = $message;
			}
		);

		$controller = new SetupController(
			$this->createMock(IRequest::class),
			$clients,
			$appConfig,
			$logger,
			$this->createMock(DemoDataService::class)
		);

		$response = $controller->runAction('test-llm');
		$this->assertSame(200, $response->getStatus());
		return $response->getData();
	}//end probe()

	/**
	 * An endpoint outside the allow-list is refused before any request is made.
	 *
	 * @return void
	 */
	public function testEndpointsOutsideTheAllowListAreRefusedWithoutARequest(): void {
		$refused = [
			'http://example.com:11434', 'https://10.0.0.5', 'ftp://localhost', 'localhost:11434', 'http://', '//localhost/x',
			'http://localhost.evil.com', 'http://LOCALHOST@evil.com', 'file:///etc/passwd', 'http://169.254.169.254',
			'http://[::1]:11434',
		];
		foreach ($refused as $endpoint) {
			$data = $this->probe($endpoint, '{"models":[{"name":"m"}]}');
			$this->assertSame(
				[
					'success' => false,
					'message' => 'Endpoint host not allowed. Use localhost or host.docker.internal (configure a remote model host in OpenRegister).',
				],
				$data,
				$endpoint
			);
			$this->assertSame([], $this->requests, $endpoint);
			$this->assertSame([], $this->written, $endpoint);
		}
	}//end testEndpointsOutsideTheAllowListAreRefusedWithoutARequest()

	/**
	 * Every allowed host and scheme is probed at `<base>/api/tags` with the same options.
	 *
	 * @return void
	 */
	public function testAllowedEndpointsAreProbed(): void {
		$allowed = [
			'' => 'http://host.docker.internal:11434/api/tags',
			'   ' => 'http://host.docker.internal:11434/api/tags',
			'http://localhost:11434/' => 'http://localhost:11434/api/tags',
			' HTTPS://LocalHost ' => 'HTTPS://LocalHost/api/tags',
			'http://127.0.0.1:8080//' => 'http://127.0.0.1:8080/api/tags',
			'http://host.docker.internal' => 'http://host.docker.internal/api/tags',
		];
		foreach ($allowed as $endpoint => $url) {
			$this->probe($endpoint, '{"models":[]}');
			$this->assertSame(
				[[$url, ['timeout' => 5, 'connect_timeout' => 3, 'nextcloud' => ['allow_local_address' => true]]]],
				$this->requests,
				$endpoint
			);
		}
	}//end testAllowedEndpointsAreProbed()

	/**
	 * Every answer of a reachable host, and what each writes.
	 *
	 * @return void
	 */
	public function testAnswers(): void {
		$this->assertSame(
			['success' => false, 'message' => 'Reachable, but the host advertises no models. Pull a model on the Ollama host first.'],
			$this->probe('', '{"models":[{"name":""},{"size":1}]}')
		);
		$this->assertSame([], $this->written);

		foreach (['not json', '[]', '{}', '{"models":[]}'] as $body) {
			$this->assertSame(false, $this->probe('', $body)['success'], $body);
		}

		$this->assertSame(
			['success' => true, 'message' => 'Connected — 1 model (llama3). Default set to "llama3".'],
			$this->probe('', '{"models":[{"name":"llama3"}]}')
		);
		$this->assertSame(['defaultmodel' => 'llama3', 'setup_llm_tested' => '1'], $this->written);

		$this->assertSame(
			['success' => true, 'message' => 'Connected — 5 models (a, b, c, d). Default set to "a".'],
			$this->probe('', '{"models":[{"name":"a"},{"name":"b"},{"name":""},{"name":"c"},{"name":"d"},{"name":"e"}]}')
		);
		$this->assertSame(['defaultmodel' => 'a', 'setup_llm_tested' => '1'], $this->written);

		$this->assertSame(
			['success' => false, 'message' => 'Not reachable: connection refused'],
			$this->probe('http://localhost:11434', new RuntimeException('connection refused'))
		);
		$this->assertSame(['[Hermiq] LLM test failed: connection refused'], $this->debug);
		$this->assertSame([], $this->written);
	}//end testAnswers()
}//end class
