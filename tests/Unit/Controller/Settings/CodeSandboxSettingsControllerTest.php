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
	private function controller(array $params = [], bool $available = true): CodeSandboxSettingsController {
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

		$client = $this->createMock(CodeSandboxClient::class);
		$client->method('isAvailable')->willReturn($available);

		return new CodeSandboxSettingsController(request: $request, settings: new CodeSandboxSettings(appConfig: $config), client: $client);
	}//end controller()

	/**
	 * Both routes are for admins only.
	 *
	 * @return void
	 */
	public function testBothRoutesAreAdminOnly(): void {
		foreach (['get', 'update'] as $method) {
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
		self::assertSame(['backend' => 'hosted', 'hostedSource' => 'src-sandbox'], json_decode($this->stored, true));
	}//end testAHostedChoiceIsSavedWithItsSource()

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
}//end class
