<?php

/**
 * Unit tests for RunConcurrencySettingsController.
 *
 * Reading and setting the limits on parallel scheduled runs: both routes are
 * admin-only, a refused value is a 422 with its reason and a storage failure a logged 500.
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

use OCA\Hermiq\Controller\Settings\RunConcurrencySettingsController;
use OCA\Hermiq\Service\Run\RunConcurrencySettings;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
 */
#[CoversClass(RunConcurrencySettingsController::class)]
#[UsesClass(RunConcurrencySettings::class)]
final class RunConcurrencySettingsControllerTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;

	/**
	 * What the app config holds.
	 *
	 * @var string
	 */
	private string $stored = '';

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function controller(array $params = []): RunConcurrencySettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) ? $params[$key] : $default
		);

		return new RunConcurrencySettingsController(
			request: $request,
			settings: new RunConcurrencySettings(appConfig: $this->appConfig),
			logger: $this->logger
		);
	}//end controller()

	/**
	 * Only an administrator reaches either route: Nextcloud checks the attribute before the method runs.
	 *
	 * @return void
	 */
	public function testBothRoutesAreAdminOnly(): void {
		foreach (['get', 'update'] as $method) {
			$reflection = new ReflectionMethod(RunConcurrencySettingsController::class, $method);
			$attributes = $reflection->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method . ' is an admin setting');
			$this->assertSame([AdminSettings::class], $attributes[0]->getArguments());
			$this->assertSame([], $reflection->getAttributes(NoAdminRequired::class), $method . ' is not open to non-admins');
		}
	}//end testBothRoutesAreAdminOnly()

	/**
	 * The limits in force are answered with their maximum.
	 *
	 * @return void
	 */
	public function testTheLimitsAreAnswered(): void {
		$this->stored = '{"maxParallel":6,"maxParallelPerOrganisation":3}';

		$response = $this->controller()->get();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['maxParallel' => 6, 'maxParallelPerOrganisation' => 3, 'maximum' => 64], $response->getData());
	}//end testTheLimitsAreAnswered()

	/**
	 * New limits are stored and answered.
	 *
	 * @return void
	 */
	public function testNewLimitsAreStored(): void {
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('hermiq', 'run_concurrency', '{"maxParallel":8,"maxParallelPerOrganisation":2}');

		$response = $this->controller(['maxParallel' => 8, 'maxParallelPerOrganisation' => 2])->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['maxParallel' => 8, 'maxParallelPerOrganisation' => 2, 'maximum' => 64], $response->getData());
	}//end testNewLimitsAreStored()

	/**
	 * An organisation limit above the instance limit is a 422 naming why.
	 *
	 * @return void
	 */
	public function testARefusedLimitIsA422(): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller(['maxParallel' => 2, 'maxParallelPerOrganisation' => 3])->update();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'The limit per organisation cannot be above the limit for the instance.'], $response->getData());
	}//end testARefusedLimitIsA422()

	/**
	 * A storage failure is a logged 500.
	 *
	 * @return void
	 */
	public function testAStorageFailureIsALogged500(): void {
		$this->appConfig->method('setValueString')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller(['maxParallel' => 4, 'maxParallelPerOrganisation' => 2])->update();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to save the run limits'], $response->getData());
	}//end testAStorageFailureIsALogged500()
}//end class
