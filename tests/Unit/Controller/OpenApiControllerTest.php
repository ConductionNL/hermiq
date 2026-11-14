<?php

/**
 * Tests for the OpenAPI document endpoint.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\OpenApiController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

/**
 * The served document, its version, and the missing-document answer.
 *
 * @covers \OCA\Hermiq\Controller\OpenApiController
 */
class OpenApiControllerTest extends TestCase {

	/**
	 * A scratch app directory.
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir().'/hermiq-openapi-'.bin2hex(random_bytes(4));
		mkdir($this->dir);
	}//end setUp()

	protected function tearDown(): void {
		@unlink($this->dir.'/openapi.json');
		@rmdir($this->dir);
	}//end tearDown()

	private function controller(string $appPath): OpenApiController {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->with('hermiq')->willReturn($appPath);
		$apps->method('getAppVersion')->with('hermiq')->willReturn('0.3.1');

		return new OpenApiController($this->createMock(IRequest::class), $apps);
	}//end controller()

	/**
	 * The headers set on a response, without the defaults getHeaders() merges in from the server.
	 */
	private function headers(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headers()

	public function testTheDocumentIsServedWithTheInstalledVersion(): void {
		file_put_contents($this->dir.'/openapi.json', '{"openapi":"3.0.3","info":{"title":"hermiq","version":"0.0.0"},"paths":{},"components":{"schemas":{}}}');

		$response = $this->controller($this->dir)->show();
		$data     = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertInstanceOf(stdClass::class, $data);
		$this->assertSame('0.3.1', $data->info->version);
		$this->assertSame('3.0.3', $data->openapi);
		$this->assertSame('{"openapi":"3.0.3","info":{"title":"hermiq","version":"0.3.1"},"paths":{},"components":{"schemas":{}}}', json_encode($data));
		$this->assertSame('*', $this->headers($response)['Access-Control-Allow-Origin']);
	}//end testTheDocumentIsServedWithTheInstalledVersion()

	public function testAMissingDocumentIs404(): void {
		$response = $this->controller($this->dir)->show();

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'The OpenAPI document is not installed'], $response->getData());
		$this->assertSame('*', $this->headers($response)['Access-Control-Allow-Origin']);
	}//end testAMissingDocumentIs404()

	public function testAFileWithoutAnInfoObjectIs404(): void {
		file_put_contents($this->dir.'/openapi.json', '["not", "a", "document"]');

		$this->assertSame(404, $this->controller($this->dir)->show()->getStatus());
	}//end testAFileWithoutAnInfoObjectIs404()

	public function testTheShippedDocumentIsServed(): void {
		$response = $this->controller(dirname(__DIR__, 3))->show();
		$data     = $response->getData();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('0.3.1', $data->info->version);
		$this->assertObjectHasProperty('/openapi.json', $data->paths);
		$this->assertObjectHasProperty('contact', $data->info);
		$this->assertNotEmpty($data->servers);
	}//end testTheShippedDocumentIsServed()
}//end class
