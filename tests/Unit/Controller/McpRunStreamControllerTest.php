<?php

/**
 * Unit tests for McpRunStreamController: the MCP client's GET on the run endpoint gets 405.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link https://conduction.nl
 *
 * @spec openspec/changes/persistent-llm-process/tasks.md#4-incidental-cheap-separate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\McpRunStreamController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \OCA\Hermiq\Controller\McpRunStreamController
 */
final class McpRunStreamControllerTest extends TestCase {

	/**
	 * The GET answers 405 with `Allow: POST` and nothing else.
	 *
	 * @return void
	 */
	public function testTheStreamIsRefusedWithMethodNotAllowed(): void {
		$request = $this->createMock(IRequest::class);
		$request->expects($this->never())->method('getHeader');

		$response = (new McpRunStreamController('hermiq', $request))->refuse();

		$this->assertSame(Http::STATUS_METHOD_NOT_ALLOWED, $response->getStatus());
		// getHeaders() adds the server's security headers, which need a booted server; read what was set.
		$headers = (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
		$this->assertSame('POST', $headers['Allow']);
		$this->assertSame('', $response->render());
	}//end testTheStreamIsRefusedWithMethodNotAllowed()

	/**
	 * Reachable without a session, like the POST it sits beside.
	 *
	 * @return void
	 */
	public function testTheRouteIsPublicAndNeedsNoCsrfToken(): void {
		$method = new ReflectionMethod(McpRunStreamController::class, 'refuse');

		$this->assertCount(1, $method->getAttributes(PublicPage::class));
		$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
	}//end testTheRouteIsPublicAndNeedsNoCsrfToken()

	/**
	 * GET on the run endpoint is routed here, ahead of the SPA catch-all that answered it before.
	 *
	 * @return void
	 */
	public function testTheGetIsRoutedHereBeforeTheCatchAll(): void {
		$routes = (include dirname(__DIR__, 3).'/appinfo/routes.php')['routes'];
		$names  = array_column($routes, 'name');

		$get = array_values(
			array_filter(
				$routes,
				static fn (array $route): bool => $route['url'] === '/api/mcp/run' && $route['verb'] === 'GET'
			)
		);
		$this->assertSame('mcpRunStream#refuse', $get[0]['name'] ?? null);

		$catchAll = array_search('dashboard#catchAll', $names, true);
		$this->assertNotFalse($catchAll);
		$this->assertLessThan($catchAll, array_search('mcpRunStream#refuse', $names, true));
	}//end testTheGetIsRoutedHereBeforeTheCatchAll()
}//end class
