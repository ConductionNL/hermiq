<?php

/**
 * Tests for CodeSandboxSettingsController.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\CodeSandboxSettingsController;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxSettings;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxUnavailableException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The admin reads and saves where code runs; a doubtful choice stores nothing.
 */
class CodeSandboxSettingsControllerTest extends TestCase {

	/**
	 * The stored `codeSandbox` value.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * The controller over a real settings object on a stubbed app config.
	 *
	 * @param array<string, mixed> $params    The request parameters.
	 * @param bool                 $available What the client answers.
	 *
	 * @return CodeSandboxSettingsController
	 */
	private function controller(array $params = [], bool $available = true, ?CodeSandboxClient $client = null): CodeSandboxSettingsController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($key === CodeSandboxSettings::CONFIG_KEY && $this->stored !== '') ? $this->stored : $default);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				self::assertSame('hermiq', $app);
				self::assertSame(CodeSandboxSettings::CONFIG_KEY, $key);
				$this->stored = $value;
				return true;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		if ($client === null) {
			$client = $this->createMock(CodeSandboxClient::class);
			$client->method('isAvailable')->willReturn($available);
		}

		return new CodeSandboxSettingsController(request: $request, settings: new CodeSandboxSettings(appConfig: $config), client: $client);
	}//end controller()

	/**
	 * Every route is for admins only.
	 *
	 * @return void
	 */
	public function testBothRoutesAreAdminOnly(): void {
		foreach (['get', 'update', 'test'] as $method) {
			self::assertNotEmpty((new ReflectionMethod(CodeSandboxSettingsController::class, $method))->getAttributes(AuthorizedAdminSetting::class), $method);
		}
	}//end testBothRoutesAreAdminOnly()

	/**
	 * Nothing stored reads as hermiq's own sandbox, with whether it can take runs.
	 *
	 * @return void
	 */
	public function testTheDefaultIsTheOwnSandbox(): void {
		$data = $this->controller(available: false)->get()->getData();

		self::assertSame('exapp', $data['backend']);
		self::assertNull($data['hostedSource']);
		self::assertFalse($data['available']);
		self::assertArrayHasKey('reach', $data);
	}//end testTheDefaultIsTheOwnSandbox()

	/**
	 * A hosted choice with its source is stored and read back.
	 *
	 * @return void
	 */
	public function testAHostedChoiceIsSavedWithItsSource(): void {
		$response = $this->controller(params: ['backend' => 'hosted', 'hostedSource' => ' src-sandbox '])->update();

		self::assertSame(200, $response->getStatus());
		self::assertSame('hosted', $response->getData()['backend']);
		self::assertSame('src-sandbox', $response->getData()['hostedSource']);
		self::assertSame(['backend' => 'hosted', 'hostedSource' => 'src-sandbox', 'installManagers' => ['pip', 'npm']], json_decode($this->stored, true));
	}//end testAHostedChoiceIsSavedWithItsSource()

	/**
	 * The registries an agent may install from are saved with the backend; an
	 * unknown one is refused and stores nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public function testTheInstallRegistriesAreSavedAndAnUnknownOneStoresNothing(): void {
		$refused = $this->controller(params: ['backend' => 'exapp', 'installManagers' => ['pip', 'cargo']])->update();
		self::assertSame(422, $refused->getStatus());
		self::assertSame('', $this->stored);

		$refused = $this->controller(params: ['backend' => 'docker', 'installManagers' => ['pip']])->update();
		self::assertSame(422, $refused->getStatus());
		self::assertSame('', $this->stored);

		$response = $this->controller(params: ['backend' => 'exapp', 'installManagers' => ['pip']])->update();
		self::assertSame(200, $response->getStatus());
		self::assertSame(['pip'], $response->getData()['installManagers']);
	}//end testTheInstallRegistriesAreSavedAndAnUnknownOneStoresNothing()

	/**
	 * An unknown backend, a hosted choice without a source, or a value that is
	 * not text is refused with 422 and stores nothing.
	 *
	 * @return void
	 */
	public function testADoubtfulChoiceIsRefusedAndStoresNothing(): void {
		$cases = [
			['backend' => 'docker'],
			['backend' => 'hosted'],
			['backend' => 'hosted', 'hostedSource' => '  '],
			['backend' => ['exapp']],
			['backend' => 'hosted', 'hostedSource' => ['src']],
			[],
		];
		foreach ($cases as $params) {
			$response = $this->controller(params: $params)->update();

			self::assertSame(422, $response->getStatus(), json_encode($params));
			self::assertNotSame('', $response->getData()['error']);
			self::assertSame('', $this->stored);
		}
	}//end testADoubtfulChoiceIsRefusedAndStoresNothing()

	/**
	 * Spec scenario "An admin switches to a hosted sandbox", the test half: the
	 * test runs `print(1 + 1)` and answers whether it printed 2, with the live backend.
	 *
	 * @return void
	 */
	public function testRunATestAnswersWhetherItPrintedTwo(): void {
		$this->stored = '{"backend":"hosted","hostedSource":"hosted-sandbox"}';
		$client = $this->createMock(CodeSandboxClient::class);
		$client->expects(self::exactly(2))->method('run')
			->with('python', 'print(1 + 1)', '', [], ['wallSeconds' => 10], null)
			->willReturnOnConsecutiveCalls(
				['exitCode' => 0, 'stdout' => "2\n", 'stderr' => '', 'durationMs' => 9, 'timedOut' => false, 'files' => [], 'limits' => []],
				['exitCode' => 0, 'stdout' => "3\n", 'stderr' => '', 'durationMs' => 9, 'timedOut' => false, 'files' => [], 'limits' => []]
			);
		$controller = $this->controller(client: $client);

		$passed = $controller->test();
		self::assertSame(200, $passed->getStatus());
		self::assertTrue($passed->getData()['passed']);
		self::assertSame('2', $passed->getData()['output']);
		self::assertSame('hosted', $passed->getData()['backend']);
		self::assertSame('hosted-sandbox', $passed->getData()['hostedSource']);
		self::assertSame('external', $passed->getData()['reach']);

		self::assertFalse($controller->test()->getData()['passed']);
	}//end testRunATestAnswersWhetherItPrintedTwo()

	/**
	 * No sandbox answers 503 with the reason and the stored choice.
	 *
	 * @return void
	 */
	public function testRunATestWithoutASandboxIs503(): void {
		$client = $this->createMock(CodeSandboxClient::class);
		$client->method('run')->willThrowException(new CodeSandboxUnavailableException(CodeSandboxUnavailableException::NOT_INSTALLED));

		$response = $this->controller(client: $client)->test();

		self::assertSame(503, $response->getStatus());
		self::assertSame('No code sandbox is installed on this instance.', $response->getData()['error']);
		self::assertSame('exapp', $response->getData()['backend']);
	}//end testRunATestWithoutASandboxIs503()
}//end class
