<?php

/**
 * Hermiq SpeechClient reachability unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Speech
 *
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

namespace OCA\Hermiq\Tests\Unit\Service\Speech;

use OCA\Hermiq\Service\Speech\SpeechClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for isReachable(): it really calls /health, briefly, and never throws.
 *
 * @covers \OCA\Hermiq\Service\Speech\SpeechClient
 */
class SpeechClientReachabilityTest extends TestCase {

	/**
	 * The client over an HTTP double.
	 *
	 * @param IClient         $http    The HTTP client.
	 * @param string          $baseUrl The configured base URL ('' for the default).
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return SpeechClient The client.
	 */
	private function client(IClient $http, string $baseUrl, LoggerInterface $logger): SpeechClient {
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === SpeechClient::CONFIG_BASE_URL ? $baseUrl : '')
		);

		return new SpeechClient($service, $config, $logger);
	}//end client()

	/**
	 * A response with this status.
	 *
	 * @param int $status The status.
	 *
	 * @return IResponse The response.
	 */
	private function response(int $status): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		return $response;
	}//end response()

	/**
	 * Only a 200 from /health on the configured base, with a short timeout, is reachable.
	 *
	 * @return void
	 */
	public function testOnlyA200FromHealthIsReachable(): void {
		$http = $this->createMock(IClient::class);
		$http->expects($this->exactly(2))
			->method('get')
			->with('http://speech:9000/health', ['timeout' => 3])
			->willReturnOnConsecutiveCalls($this->response(200), $this->response(503));
		$client = $this->client($http, 'http://speech:9000/', $this->createMock(LoggerInterface::class));

		$this->assertTrue($client->isReachable());
		$this->assertFalse($client->isReachable());
	}//end testOnlyA200FromHealthIsReachable()

	/**
	 * Without configuration the local default is probed.
	 *
	 * @return void
	 */
	public function testTheDefaultBaseIsProbed(): void {
		$http = $this->createMock(IClient::class);
		$http->expects($this->once())->method('get')->with('http://127.0.0.1:8000/health', ['timeout' => 3])->willReturn($this->response(200));

		$this->assertTrue($this->client($http, '', $this->createMock(LoggerInterface::class))->isReachable());
	}//end testTheDefaultBaseIsProbed()

	/**
	 * A failing call is unreachable and logged at debug level, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingCallIsUnreachableAndLogged(): void {
		$error = new RuntimeException('name does not resolve');
		$http = $this->createMock(IClient::class);
		$http->method('get')->willThrowException($error);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('Hermiq: speech sidecar unreachable', ['exception' => $error]);

		$this->assertFalse($this->client($http, 'http://speech:9000', $logger)->isReachable());
	}//end testAFailingCallIsUnreachableAndLogged()
}//end class
