<?php

/**
 * Tests for the API-Version response header.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Middleware;

use OCA\Hermiq\Middleware\ApiVersionMiddleware;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Which responses get the header, and with what value.
 *
 * @covers \OCA\Hermiq\Middleware\ApiVersionMiddleware
 */
class ApiVersionMiddlewareTest extends TestCase {

	private function middleware(?string $path, string $version = '0.3.1'): ApiVersionMiddleware {
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($path ?? false);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->with('hermiq')->willReturn($version);

		return new ApiVersionMiddleware($request, $apps);
	}//end middleware()

	private function after(ApiVersionMiddleware $middleware, JSONResponse $response): JSONResponse {
		$result = $middleware->afterController($this->createMock(Controller::class), 'index', $response);
		$this->assertSame($response, $result);

		return $result;
	}//end after()

	/**
	 * The headers set on a response, without the defaults getHeaders() merges in from the server.
	 */
	private function headers(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headers()

	public function testAnApiResponseNamesTheInstalledVersion(): void {
		$response = $this->after($this->middleware('/apps/hermiq/api/health'), new JSONResponse(['status' => 'ok']));

		$this->assertSame('0.3.1', $this->headers($response)['API-Version']);
	}//end testAnApiResponseNamesTheInstalledVersion()

	public function testAnErrorResponseNamesItToo(): void {
		$response = $this->after($this->middleware('/apps/hermiq/api/schedules/x/runs'), new JSONResponse(['error' => 'Schedule not found'], 404));

		$this->assertSame('0.3.1', $this->headers($response)['API-Version']);
		$this->assertSame(404, $response->getStatus());
	}//end testAnErrorResponseNamesItToo()

	public function testAPageResponseIsLeftAlone(): void {
		$response = $this->after($this->middleware('/apps/hermiq/agents'), new JSONResponse([]));

		$this->assertArrayNotHasKey('API-Version', $this->headers($response));
	}//end testAPageResponseIsLeftAlone()

	public function testARequestWithoutAPathIsLeftAlone(): void {
		$response = $this->after($this->middleware(null), new JSONResponse([]));

		$this->assertArrayNotHasKey('API-Version', $this->headers($response));
	}//end testARequestWithoutAPathIsLeftAlone()

	public function testAnUnknownVersionAddsNoHeader(): void {
		$response = $this->after($this->middleware('/apps/hermiq/api/x', ''), new JSONResponse([]));

		$this->assertArrayNotHasKey('API-Version', $this->headers($response));
	}//end testAnUnknownVersionAddsNoHeader()
}//end class
