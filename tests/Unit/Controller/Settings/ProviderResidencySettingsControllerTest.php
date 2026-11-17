<?php

/**
 * Unit tests for ProviderResidencySettingsController.
 *
 * Listing and declaring where a provider processes data: a rejected declaration is a 422 with the service message and a storage failure a logged 500.
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
use OCA\Hermiq\Controller\Settings\ProviderResidencySettingsController;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An admin lists and declares provider residency.
 */
#[CoversClass(ProviderResidencySettingsController::class)]
final class ProviderResidencySettingsControllerTest extends TestCase {

	private ProviderResidencyRegistry&MockObject $registry;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->registry = $this->createMock(ProviderResidencyRegistry::class);
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
	 * @return ProviderResidencySettingsController
	 */
	private function controller(array $params = []): ProviderResidencySettingsController {
		return new ProviderResidencySettingsController($this->request($params), $this->registry, $this->logger);
	}//end controller()

	public function testGetListsTheResidenciesAndTheAllowedValues(): void {
		$this->registry->method('all')->willReturn(['ollama' => ['residency' => 'on-premise']]);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['residencies' => ['ollama' => ['residency' => 'on-premise']], 'allowed' => ProviderResidencyRegistry::DECLARABLE_RESIDENCIES],
			$response->getData()
		);
	}//end testGetListsTheResidenciesAndTheAllowedValues()

	public function testGetFailureIsALogged500(): void {
		$this->registry->method('all')->willThrowException(new RuntimeException('bad json'));
		$this->logger->expects($this->once())->method('error')->with(
			'[ProviderResidencySettingsController] Failed to read hermiq.providerResidency',
			$this->callback(static fn (array $context): bool => $context['error'] === 'bad json')
		);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to read the provider residency declarations'], $response->getData());
	}//end testGetFailureIsALogged500()

	public function testDeclareResidencyForwardsResidencyAndLocation(): void {
		$this->registry->expects($this->once())
			->method('declareResidency')
			->with('openai', 'eu', 'Frankfurt')
			->willReturn(['residency' => 'eu', 'location' => 'Frankfurt']);

		$response = $this->controller(['residency' => 'eu', 'location' => 'Frankfurt'])->declareResidency('openai');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['residency' => 'eu', 'location' => 'Frankfurt'], $response->getData());
	}//end testDeclareResidencyForwardsResidencyAndLocation()

	public function testDeclareResidencyWithoutParametersPassesEmptyStrings(): void {
		$this->registry->expects($this->once())->method('declareResidency')->with('openai', '', '')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->declareResidency('openai')->getStatus());
	}//end testDeclareResidencyWithoutParametersPassesEmptyStrings()

	public function testDeclareResidencyRejectionIsA422WithTheServiceMessage(): void {
		$this->registry->method('declareResidency')->willThrowException(new InvalidArgumentException('Unknown residency.'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller(['residency' => 'moon'])->declareResidency('openai');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Unknown residency.'], $response->getData());
	}//end testDeclareResidencyRejectionIsA422WithTheServiceMessage()

	public function testDeclareResidencyStorageFailureIsALogged500(): void {
		$this->registry->method('declareResidency')->willThrowException(new RuntimeException('write failed'));
		$this->logger->expects($this->once())->method('error')->with('[ProviderResidencySettingsController] Failed to persist hermiq.providerResidency', $this->anything());

		$response = $this->controller(['residency' => 'eu'])->declareResidency('openai');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to save the provider residency declaration'], $response->getData());
	}//end testDeclareResidencyStorageFailureIsALogged500()
}//end class
