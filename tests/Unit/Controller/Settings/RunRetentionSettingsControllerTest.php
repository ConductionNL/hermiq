<?php

/**
 * Unit tests for RunRetentionSettingsController.
 *
 * Reading and setting the default run retention: the bounds are reported, non-numeric input is refused before the policy is touched, a rejected value is a 422 and a storage failure a logged 500.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\Controller\Settings\RunRetentionSettingsController;
use OCA\Hermiq\Service\AiFeature\RunRetentionPolicy;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An admin reads and sets how long agent runs are kept.
 */
#[CoversClass(RunRetentionSettingsController::class)]
final class RunRetentionSettingsControllerTest extends TestCase {

	private RunRetentionPolicy&MockObject $policy;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->policy = $this->createMock(RunRetentionPolicy::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A request whose getParam() answers from $params, else the given default.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return IRequest
	 */
	private function request(array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) ? $params[$key] : $default
		);
		return $request;
	}//end request()

	/**
	 * The controller under test.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return RunRetentionSettingsController
	 */
	private function controller(array $params = []): RunRetentionSettingsController {
		return new RunRetentionSettingsController($this->request($params), $this->policy, $this->logger);
	}//end controller()

	public function testGetReportsTheDefaultTheBoundsAndTheLastCleanup(): void {
		$this->policy->method('defaultDays')->willReturn(90);
		$this->policy->method('lastCleanup')->willReturn(['at' => '2026-11-01T00:00:00+00:00', 'deleted' => 4]);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'defaultDays' => 90,
				'minimumDays' => 1,
				'maximumDays' => 3650,
				'lastCleanup' => ['at' => '2026-11-01T00:00:00+00:00', 'deleted' => 4],
			],
			$response->getData()
		);
	}//end testGetReportsTheDefaultTheBoundsAndTheLastCleanup()

	public function testGetFailureIsALogged500(): void {
		$this->policy->method('defaultDays')->willThrowException(new RuntimeException('config down'));
		$this->logger->expects($this->once())->method('error')->with(
			'[RunRetentionSettingsController] Failed to read the retention settings',
			$this->callback(static fn (array $context): bool => $context['error'] === 'config down')
		);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to read the retention settings'], $response->getData());
	}//end testGetFailureIsALogged500()

	public function testUpdateRefusesANonNumericRetention(): void {
		$this->policy->expects($this->never())->method('setDefaultDays');

		$response = $this->controller(['defaultDays' => 'forever'])->update();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'A retention is a number of days.'], $response->getData());
	}//end testUpdateRefusesANonNumericRetention()

	public function testUpdateStoresWholeDaysAndReturnsTheStoredValue(): void {
		$this->policy->expects($this->once())->method('setDefaultDays')->with(30)->willReturn(30);
		$this->policy->method('lastCleanup')->willReturn([]);

		$response = $this->controller(['defaultDays' => '30'])->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['defaultDays' => 30, 'lastCleanup' => []], $response->getData());
	}//end testUpdateStoresWholeDaysAndReturnsTheStoredValue()

	public function testUpdatePassesTheServiceRejectionThroughAs422(): void {
		$this->policy->method('setDefaultDays')->willThrowException(new InvalidArgumentException('Between 1 and 3650 days.'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller(['defaultDays' => 0])->update();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Between 1 and 3650 days.'], $response->getData());
	}//end testUpdatePassesTheServiceRejectionThroughAs422()

	public function testUpdateStorageFailureIsALogged500(): void {
		$this->policy->method('setDefaultDays')->willThrowException(new RuntimeException('write failed'));
		$this->policy->expects($this->never())->method('lastCleanup');
		$this->logger->expects($this->once())->method('error')->with('[RunRetentionSettingsController] Failed to persist the retention default', $this->anything());

		$response = $this->controller(['defaultDays' => 30])->update();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to save the retention setting'], $response->getData());
	}//end testUpdateStorageFailureIsALogged500()
}//end class
