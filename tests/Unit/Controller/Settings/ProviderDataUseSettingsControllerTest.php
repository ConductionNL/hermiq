<?php

/**
 * Unit tests for ProviderDataUseSettingsController.
 *
 * Listing and declaring what a provider does with submitted data: the declaring admin is recorded from the session, a rejected declaration is a 422 and a storage failure a logged 500.
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
use OCA\Hermiq\Controller\Settings\ProviderDataUseSettingsController;
use OCA\Hermiq\Service\AiFeature\ProviderDataUseRegistry;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An admin lists and declares provider data-use.
 */
#[CoversClass(ProviderDataUseSettingsController::class)]
final class ProviderDataUseSettingsControllerTest extends TestCase {

	private ProviderDataUseRegistry&MockObject $registry;
	private IUserSession&MockObject $session;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->registry = $this->createMock(ProviderDataUseRegistry::class);
		$this->session  = $this->createMock(IUserSession::class);
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
	 * @return ProviderDataUseSettingsController
	 */
	private function controller(array $params = []): ProviderDataUseSettingsController {
		return new ProviderDataUseSettingsController($this->request($params), $this->registry, $this->session, $this->logger);
	}//end controller()

	public function testGetListsTheDeclarationsAndTheAllowedValues(): void {
		$this->registry->method('all')->willReturn(['openai' => ['dataUse' => 'no-training']]);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['dataUse' => ['openai' => ['dataUse' => 'no-training']], 'allowed' => ProviderDataUseRegistry::DECLARABLE],
			$response->getData()
		);
	}//end testGetListsTheDeclarationsAndTheAllowedValues()

	public function testGetFailureIsALogged500(): void {
		$this->registry->method('all')->willThrowException(new RuntimeException('bad json'));
		$this->logger->expects($this->once())->method('error')->with(
			'[ProviderDataUseSettingsController] Failed to read hermiq.providerDataUse',
			$this->callback(static fn (array $context): bool => $context['error'] === 'bad json')
		);

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to read the provider data-use declarations'], $response->getData());
	}//end testGetFailureIsALogged500()

	public function testDeclareRecordsTheSignedInAdmin(): void {
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$this->session->method('getUser')->willReturn($admin);
		$this->registry->expects($this->once())
			->method('declare')
			->with('openai', 'no-training', 'https://terms.test/', 'admin')
			->willReturn(['dataUse' => 'no-training', 'declaredBy' => 'admin']);

		$response = $this->controller(['dataUse' => 'no-training', 'termsReference' => 'https://terms.test/'])->declare('openai');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['dataUse' => 'no-training', 'declaredBy' => 'admin'], $response->getData());
	}//end testDeclareRecordsTheSignedInAdmin()

	public function testDeclareWithoutASessionOrParametersPassesEmptyStrings(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->registry->expects($this->once())->method('declare')->with('mistral', '', '', '')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->declare('mistral')->getStatus());
	}//end testDeclareWithoutASessionOrParametersPassesEmptyStrings()

	public function testDeclareRejectionIsA422WithTheServiceMessage(): void {
		$this->registry->method('declare')->willThrowException(new InvalidArgumentException('Unknown data use.'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller(['dataUse' => 'sell-it'])->declare('openai');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Unknown data use.'], $response->getData());
	}//end testDeclareRejectionIsA422WithTheServiceMessage()

	public function testDeclareStorageFailureIsALogged500(): void {
		$this->registry->method('declare')->willThrowException(new RuntimeException('write failed'));
		$this->logger->expects($this->once())->method('error')->with('[ProviderDataUseSettingsController] Failed to persist hermiq.providerDataUse', $this->anything());

		$response = $this->controller(['dataUse' => 'no-training'])->declare('openai');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to save the provider data-use declaration'], $response->getData());
	}//end testDeclareStorageFailureIsALogged500()
}//end class
