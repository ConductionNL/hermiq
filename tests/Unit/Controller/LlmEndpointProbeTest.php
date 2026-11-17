<?php

/**
 * Unit tests for LlmEndpointProbe: only local LLM endpoints may be probed,
 * and the advertised models are read from an Ollama-style tags document.
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

use OCA\Hermiq\Controller\LlmEndpointProbe;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for LlmEndpointProbe.
 *
 * @covers \OCA\Hermiq\Controller\LlmEndpointProbe
 */
class LlmEndpointProbeTest extends TestCase {

	/**
	 * A probe whose HTTP client answers the given body.
	 *
	 * @param string|RuntimeException $body The body, or the failure.
	 * @param ?string                 $url  The URL the probe must call.
	 *
	 * @return LlmEndpointProbe
	 */
	private function probe(string|RuntimeException $body, ?string $url = null): LlmEndpointProbe {
		$client = $this->createMock(IClient::class);
		$get = $client->method('get');
		if ($url !== null) {
			$get->with($url, [
				'timeout' => 5,
				'connect_timeout' => 3,
				'nextcloud' => ['allow_local_address' => true],
			]);
		}
		if ($body instanceof RuntimeException) {
			$get->willThrowException($body);
		} else {
			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn($body);
			$get->willReturn($response);
		}
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);
		return new LlmEndpointProbe($service);
	}

	/**
	 * Local http(s) hosts are allowed, case-insensitively.
	 *
	 * @return void
	 */
	public function testLocalEndpointsAreAllowed(): void {
		$probe = $this->probe('');
		foreach (['http://localhost:11434', 'HTTPS://127.0.0.1/v1', 'http://Host.Docker.Internal:11434/api'] as $url) {
			self::assertTrue($probe->isAllowedEndpoint($url), $url);
		}
	}

	/**
	 * Remote hosts, other schemes and malformed URLs are refused.
	 *
	 * @return void
	 */
	public function testOtherEndpointsAreRefused(): void {
		$probe = $this->probe('');
		foreach ([
			'http://example.com',
			'http://localhost.example.com',
			'http://10.0.0.5:11434',
			'ftp://localhost',
			'file:///etc/passwd',
			'localhost:11434',
			'//localhost',
			'',
			'http:///nohost',
		] as $url) {
			self::assertFalse($probe->isAllowedEndpoint($url), $url);
		}
	}

	/**
	 * The advertised models are the non-empty names in the tags document.
	 *
	 * @return void
	 */
	public function testAdvertisedModels(): void {
		$body = json_encode(['models' => [['name' => 'llama3:8b'], ['name' => ''], ['size' => 1], ['name' => 'mistral']]]);

		self::assertSame(['llama3:8b', 'mistral'], $this->probe($body, 'http://localhost:11434/api/tags')->advertisedModels('http://localhost:11434/api/tags'));
	}

	/**
	 * A document without models, or not JSON, advertises nothing.
	 *
	 * @return void
	 */
	public function testNoModels(): void {
		self::assertSame([], $this->probe('{"other":1}')->advertisedModels('http://localhost/api/tags'));
		self::assertSame([], $this->probe('not json')->advertisedModels('http://localhost/api/tags'));
	}

	/**
	 * A transport failure propagates to the caller.
	 *
	 * @return void
	 */
	public function testATransportFailurePropagates(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('refused');

		$this->probe(new RuntimeException('refused'))->advertisedModels('http://localhost/api/tags');
	}
}
