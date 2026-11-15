<?php

/**
 * Unit tests for TraceExportSettings and TraceExportSettingsController.
 *
 * Export is off by default, the credential is kept by reference only, an unusable value
 * is refused before anything is stored, and both routes are admin-only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\TraceExport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\TraceExport;

use InvalidArgumentException;
use OCA\Hermiq\Controller\Settings\TraceExportSettingsController;
use OCA\Hermiq\Service\TraceExport\TraceExportSettings;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * An administrator configures trace export with a brokered credential.
 */
#[CoversClass(TraceExportSettings::class)]
#[CoversClass(TraceExportSettingsController::class)]
final class TraceExportSettingsTest extends TestCase {

	/**
	 * The stored app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The request parameters, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Settings over an in-memory app config.
	 *
	 * @return TraceExportSettings
	 */
	private function settings(): TraceExportSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default): string => ($this->stored[$app . '/' . $key] ?? $default));
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app . '/' . $key] = $value;
				return true;
			}
		);

		return new TraceExportSettings(appConfig: $config);

	}//end settings()

	/**
	 * Nothing saved: export is off, metadata only, both sources.
	 *
	 * @return void
	 */
	public function testExportIsOffByDefault(): void {
		$settings = $this->settings();

		self::assertSame(TraceExportSettings::DEFAULTS, $settings->get());
		self::assertFalse($settings->exports(source: 'runs'));
		self::assertFalse($settings->exports(source: 'chat'));

		$this->stored['hermiq/trace_export'] = '{"enabled":true,"content":"everything"}';
		self::assertSame(TraceExportSettings::DEFAULTS, $settings->get(), 'A stored value that no longer validates leaves export off.');

	}//end testExportIsOffByDefault()

	/**
	 * Saving keeps the endpoint and the credential reference, and no secret field exists.
	 *
	 * @return void
	 */
	public function testAnAdministratorConnectsLangfuse(): void {
		$settings = $this->settings();

		$stored = $settings->save(
			input: [
				'enabled' => 'true',
				'endpoint' => 'https://cloud.langfuse.com/api/public/otel/v1/traces',
				'credentialId' => 'cred-uuid',
				'authScheme' => 'basic',
				'username' => 'pk-lf-public',
				'sources' => 'runs',
				'secret' => 'sk-lf-should-not-be-kept',
			]
		);

		self::assertTrue($stored['enabled']);
		self::assertSame('cred-uuid', $stored['credentialId']);
		self::assertSame(['runs'], $stored['sources']);
		self::assertStringNotContainsString('sk-lf-should-not-be-kept', $this->stored['hermiq/trace_export']);
		self::assertSame($stored, $settings->get());
		self::assertTrue($settings->exports(source: 'runs'));
		self::assertFalse($settings->exports(source: 'chat'));
		self::assertSame(
			'Traces go to cloud.langfuse.com. With metadata only, no prompt or answer text leaves this server.',
			$settings->destinationNotice(settings: $stored)
		);

	}//end testAnAdministratorConnectsLangfuse()

	/**
	 * Values that cannot be used are refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testUnusableValuesAreRefused(): void {
		$settings = $this->settings();
		$cases = [
			['enabled' => true, 'endpoint' => 'https://collector.example/v1/traces'],
			['enabled' => true, 'credentialId' => 'c', 'endpoint' => 'ftp://collector.example'],
			['content' => 'everything'],
			['authScheme' => 'digest'],
			['enabled' => true, 'credentialId' => 'c', 'endpoint' => 'https://c.example', 'sources' => []],
			['enabled' => true, 'credentialId' => 'c', 'endpoint' => 'https://c.example', 'authScheme' => 'basic'],
		];

		foreach ($cases as $case) {
			try {
				$settings->save(input: $case);
				self::fail('Accepted: ' . json_encode($case));
			} catch (InvalidArgumentException $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}

		self::assertSame([], $this->stored);

	}//end testUnusableValuesAreRefused()

	/**
	 * Both routes are admin-only, so a non-admin is refused by the middleware (403).
	 *
	 * @return void
	 */
	public function testBothRoutesAreAdminOnly(): void {
		foreach (['get', 'update'] as $method) {
			$attributes = (new ReflectionMethod(TraceExportSettingsController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			self::assertCount(1, $attributes, $method);
			self::assertSame([AdminSettings::class], $attributes[0]->getArguments());
		}

	}//end testBothRoutesAreAdminOnly()

	/**
	 * The controller stores what the request carries, answers 422 on a refusal, and shows the notices.
	 *
	 * @return void
	 */
	public function testTheControllerSavesAndRefuses(): void {
		$this->params = ['enabled' => 'true', 'endpoint' => 'https://tempo.example/v1/traces', 'credentialId' => 'cred-1', 'content' => 'redacted-text'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key): mixed => ($this->params[$key] ?? null));
		$controller = new TraceExportSettingsController(request: $request, settings: $this->settings(), logger: $this->createMock(LoggerInterface::class));

		$response = $controller->update();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('cred-1', $response->getData()['settings']['credentialId']);
		self::assertSame(TraceExportSettings::COPY_NOTICE, $response->getData()['copyNotice']);
		self::assertSame(
			'Traces go to tempo.example. Prompts and answers are sent after the output filter and redaction.',
			$response->getData()['destinationNotice']
		);
		self::assertSame($response->getData(), $controller->get()->getData());

		$this->params = ['content' => 'everything'];
		$before = $this->stored;
		$refused = $controller->update();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertSame($before, $this->stored);

	}//end testTheControllerSavesAndRefuses()
}//end class
