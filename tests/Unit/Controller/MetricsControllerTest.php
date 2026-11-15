<?php

/**
 * Unit tests for MetricsController, the Prometheus text exposition.
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

use OCA\Hermiq\Controller\MetricsController;
use OCA\Hermiq\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MetricsController.
 *
 * @covers \OCA\Hermiq\Controller\MetricsController
 */
class MetricsControllerTest extends TestCase {

	/**
	 * The metrics body for a given OpenRegister health.
	 *
	 * @param bool $healthy Whether OpenRegister is reachable.
	 *
	 * @return string
	 */
	private static function body(bool $healthy): string {
		return "# HELP hermiq_info Static app information\n"
			. "# TYPE hermiq_info gauge\n"
			. "hermiq_info{app=\"hermiq\",version=\"0.1.0\"} 1\n"
			. "# HELP hermiq_health_status 1 when OpenRegister reachable, 0 otherwise\n"
			. "# TYPE hermiq_health_status gauge\n"
			. 'hermiq_health_status ' . ($healthy ? '1' : '0') . "\n";
	}

	/**
	 * Health follows OpenRegister's reachability, in the Prometheus text format.
	 *
	 * @return void
	 */
	public function testExposesHealth(): void {
		$service = $this->createMock(SettingsService::class);
		$service->method('isOpenRegisterAvailable')->willReturnOnConsecutiveCalls(true, false);
		$controller = new MetricsController($this->createMock(IRequest::class), $service, $this->createMock(LoggerInterface::class));

		$up = $controller->index();
		self::assertSame(200, $up->getStatus());
		self::assertSame(self::body(true), $up->getData());
		// Read the Response's own header store: getHeaders() adds \OC-backed defaults unavailable here.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($up);
		self::assertSame('text/plain; version=0.0.4; charset=utf-8', $headers['Content-Type']);

		self::assertSame(self::body(false), $controller->index()->getData());
	}

	/**
	 * A failure is an empty 500, logged.
	 *
	 * @return void
	 */
	public function testAFailureIsAnEmpty500(): void {
		$failure = new RuntimeException('boom');
		$service = $this->createMock(SettingsService::class);
		$service->method('isOpenRegisterAvailable')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('Hermiq: metrics generation failed', ['exception' => $failure]);

		$response = (new MetricsController($this->createMock(IRequest::class), $service, $logger))->index();

		self::assertSame(500, $response->getStatus());
		self::assertSame('', $response->getData());
	}
}
