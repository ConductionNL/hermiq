<?php

/**
 * Unit tests for CodeSandboxSettings: the instance-wide choice of where code runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\CodeSandbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use InvalidArgumentException;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CodeSandboxSettings.
 */
class CodeSandboxSettingsTest extends TestCase {

	/**
	 * The stored config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Settings over an in-memory app config.
	 *
	 * @param string|null $stored The stored JSON, if any.
	 *
	 * @return CodeSandboxSettings
	 */
	private function settings(?string $stored=null): CodeSandboxSettings {
		if ($stored !== null) {
			$this->store[CodeSandboxSettings::CONFIG_KEY] = $stored;
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->store[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('hermiq', $app);
				$this->store[$key] = $value;
				return true;
			}
		);

		return new CodeSandboxSettings(appConfig: $config);
	}//end settings()

	/**
	 * Nothing stored reads as the own sandbox with reach self.
	 *
	 * @return void
	 */
	public function testNothingStoredIsTheOwnSandbox(): void {
		$settings = $this->settings();

		$this->assertSame(['backend' => 'exapp', 'hostedSource' => null, 'reach' => 'self', 'installManagers' => ['pip', 'npm']], $settings->toArray());
	}//end testNothingStoredIsTheOwnSandbox()

	/**
	 * A hosted choice with a source is stored and reads back with reach external.
	 *
	 * @return void
	 */
	public function testHostedWithASourceIsStoredAndReachesExternal(): void {
		$settings = $this->settings();

		$this->assertSame(['backend' => 'hosted', 'hostedSource' => 'hosted-sandbox'], $settings->save(backend: 'hosted', hostedSource: ' hosted-sandbox '));
		$this->assertSame('hosted', $settings->backend());
		$this->assertSame('hosted-sandbox', $settings->hostedSource());
		$this->assertSame('external', $settings->reach());
	}//end testHostedWithASourceIsStoredAndReachesExternal()

	/**
	 * Hosted without a source, or an unknown backend, is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testHostedWithoutSourceAndUnknownBackendAreRefused(): void {
		$settings = $this->settings();

		try {
			$settings->save(backend: 'hosted', hostedSource: '  ');
			$this->fail('A hosted choice without a source was stored.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('A hosted sandbox needs the integriq source it is reached through.', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Choose where code runs: exapp or hosted.');
		try {
			$settings->save(backend: 'cloud');
		} finally {
			$this->assertSame([], $this->store);
		}
	}//end testHostedWithoutSourceAndUnknownBackendAreRefused()

	/**
	 * Switching back to the own sandbox drops the hosted source.
	 *
	 * @return void
	 */
	public function testTheOwnSandboxDropsTheHostedSource(): void {
		$settings = $this->settings();
		$settings->save(backend: 'hosted', hostedSource: 'hosted-sandbox');

		$this->assertSame(['backend' => 'exapp', 'hostedSource' => null], $settings->save(backend: 'exapp', hostedSource: 'hosted-sandbox'));
		$this->assertNull($settings->hostedSource());
		$this->assertSame('self', $settings->reach());
	}//end testTheOwnSandboxDropsTheHostedSource()

	/**
	 * Unreadable JSON, an unknown stored backend, or hosted without a source read as the own sandbox.
	 *
	 * @return void
	 */
	public function testAnyDoubtfulStoredValueReadsAsTheOwnSandbox(): void {
		$this->assertSame('exapp', $this->settings(stored: '{not json')->backend());
		$this->assertSame('exapp', $this->settings(stored: '{"backend":"cloud","hostedSource":"x"}')->backend());
		$this->assertSame('exapp', $this->settings(stored: '{"backend":"hosted","hostedSource":""}')->backend());
		$this->assertSame('exapp', $this->settings(stored: '{"backend":"hosted","hostedSource":7}')->backend());
	}//end testAnyDoubtfulStoredValueReadsAsTheOwnSandbox()

	/**
	 * Both registries are allowed until an admin narrows them, and a backend
	 * save keeps the narrowed list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004
	 */
	public function testTheInstallRegistriesAreBothUntilNarrowedAndSurviveABackendSave(): void {
		$settings = $this->settings();
		$this->assertSame(['pip', 'npm'], $settings->installManagers());

		$this->assertSame(['pip'], $settings->saveInstallManagers(managers: ['pip']));
		$settings->save(backend: 'exapp');

		$this->assertSame(['pip'], $settings->installManagers());
		$this->assertSame(['pip'], $settings->toArray()['installManagers']);
		$this->assertSame([], $settings->saveInstallManagers(managers: []));
	}//end testTheInstallRegistriesAreBothUntilNarrowedAndSurviveABackendSave()

	/**
	 * An unknown registry is refused and a doubtful stored list is read safely.
	 *
	 * @return void
	 */
	public function testAnUnknownRegistryIsRefusedAndADoubtfulStoredListReadsSafely(): void {
		try {
			$this->settings()->saveInstallManagers(managers: ['pip', 'cargo']);
			$this->fail('An unknown registry was stored.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Tools are installed from pip or npm.', $e->getMessage());
		}

		$this->assertSame(['pip', 'npm'], $this->settings(stored: '{"backend":"exapp","installManagers":"pip"}')->installManagers());
		$this->assertSame(['npm'], $this->settings(stored: '{"backend":"exapp","installManagers":["npm","cargo"]}')->installManagers());
	}//end testAnUnknownRegistryIsRefusedAndADoubtfulStoredListReadsSafely()
}//end class
