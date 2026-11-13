<?php

/**
 * Unit tests for IntakeSettingsController.
 *
 * Reading and saving the intake abstention threshold: non-numeric input is refused before the settings are touched, a rejected value is a 422 with its own message, and a storage failure is a logged 500.
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
use OCA\Hermiq\Controller\Settings\IntakeSettingsController;
use OCA\Hermiq\Service\Intake\IntakeSettings;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An admin reads and sets the intake confidence threshold.
 */
#[CoversClass(IntakeSettingsController::class)]
final class IntakeSettingsControllerTest extends TestCase {

	private IntakeSettings&MockObject $settings;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->settings = $this->createMock(IntakeSettings::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
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
	 * @return IntakeSettingsController
	 */
	private function controller(array $params = []): IntakeSettingsController {
		return new IntakeSettingsController($this->request($params), $this->settings, $this->logger);
	}//end controller()

	public function testGetReturnsAllSettings(): void {
		$this->settings->method('all')->willReturn(['abstentionThreshold' => 0.6]);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['abstentionThreshold' => 0.6], $response->getData());
	}//end testGetReturnsAllSettings()

	public function testGetFailureIsALogged500(): void {
		$this->settings->method('all')->willThrowException(new RuntimeException('config down'));
		$this->logger->expects($this->once())->method('error')->with(
			'[IntakeSettingsController] Failed to read the intake settings',
			$this->callback(static fn (array $context): bool => $context['error'] === 'config down')
		);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to read the intake settings'], $response->getData());
	}//end testGetFailureIsALogged500()

	public function testUpdateRefusesANonNumericThreshold(): void {
		$this->settings->expects($this->never())->method('setAbstentionThreshold');

		$response = $this->controller(['abstentionThreshold' => 'high'])->update();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'A confidence threshold is a number between 0 and 1.'], $response->getData());
	}//end testUpdateRefusesANonNumericThreshold()

	public function testUpdateRefusesAMissingThreshold(): void {
		$this->settings->expects($this->never())->method('setAbstentionThreshold');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->update()->getStatus());
	}//end testUpdateRefusesAMissingThreshold()

	public function testUpdateStoresTheThresholdAsAFloatAndReturnsTheSettings(): void {
		$this->settings->expects($this->once())->method('setAbstentionThreshold')->with(0.75)->willReturn(0.75);
		$this->settings->method('all')->willReturn(['abstentionThreshold' => 0.75]);

		$response = $this->controller(['abstentionThreshold' => '0.75'])->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['abstentionThreshold' => 0.75], $response->getData());
	}//end testUpdateStoresTheThresholdAsAFloatAndReturnsTheSettings()

	public function testUpdatePassesTheServiceRejectionThroughAs422(): void {
		$this->settings->method('setAbstentionThreshold')->willThrowException(new InvalidArgumentException('Out of range.'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller(['abstentionThreshold' => 2])->update();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Out of range.'], $response->getData());
	}//end testUpdatePassesTheServiceRejectionThroughAs422()

	public function testUpdateStorageFailureIsALogged500(): void {
		$this->settings->method('setAbstentionThreshold')->willThrowException(new RuntimeException('write failed'));
		$this->settings->expects($this->never())->method('all');
		$this->logger->expects($this->once())->method('error')->with('[IntakeSettingsController] Failed to persist the intake threshold', $this->anything());

		$response = $this->controller(['abstentionThreshold' => 0.5])->update();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to save the intake threshold'], $response->getData());
	}//end testUpdateStorageFailureIsALogged500()
}//end class
