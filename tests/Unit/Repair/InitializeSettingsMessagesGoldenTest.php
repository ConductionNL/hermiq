<?php

/**
 * Golden tests for the InitializeSettings repair step's messages.
 *
 * Pins every output and logger line, in order, for each branch: OpenRegister absent,
 * a forced import that succeeds (with and without a reported version), an unforced
 * import that succeeds, an import that reports a problem (with and without a
 * message), and a step that throws, including where the throw happens.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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
 * @spec openspec/specs/configuration-initialization/spec.md#req-init-002-import-configuration-on-install-upgrade
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\InitializeSettings;
use OCA\Hermiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the register-initialization repair step's messages.
 *
 * @spec openspec/specs/configuration-initialization/spec.md#req-init-002-import-configuration-on-install-upgrade
 */
class InitializeSettingsMessagesGoldenTest extends TestCase {

	/**
	 * Every output, logger and app-config write, in order.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * Run the step and answer what it wrote.
	 *
	 * @param bool $available Whether OpenRegister is available.
	 * @param string $applied The applied register version.
	 * @param array|RuntimeException $result What loadConfiguration answers or throws.
	 *
	 * @return array<int, string>
	 */
	private function runStep(bool $available, string $applied, array|RuntimeException $result): array {
		$this->log = [];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getRegisterConfigVersion')->willReturn('2.0');
		$load = $settings->method('loadConfiguration');
		if ($result instanceof RuntimeException) {
			$load->willThrowException($result);
		} else {
			$load->willReturnCallback(
				function (bool $force) use ($result): array {
					$this->log[] = 'load force=' . var_export($force, true);
					return $result;
				}
			);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($applied);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->log[] = 'set ' . $app . ' ' . $key . ' ' . $value;
				return true;
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(fn (string $m) => $this->log[] = 'info: ' . $m);
		$output->method('warning')->willReturnCallback(fn (string $m) => $this->log[] = 'warning: ' . $m);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(fn (string $m) => $this->log[] = 'log.warning: ' . $m);
		$logger->method('error')->willReturnCallback(
			fn (string $m, array $c = []) => $this->log[] = 'log.error: ' . $m . ' ' . json_encode($c)
		);

		(new InitializeSettings(settingsService: $settings, appConfig: $appConfig, logger: $logger))->run(output: $output);
		return $this->log;
	}//end runStep()

	/**
	 * Every branch, line for line.
	 *
	 * @return void
	 */
	public function testEveryBranchWritesItsLinesInOrder(): void {
		$this->assertSame(
			[
				'info: Initializing Hermiq configuration...',
				'warning: OpenRegister is not installed or enabled. Skipping auto-configuration.',
				'log.warning: Hermiq: OpenRegister not available, skipping register initialization',
			],
			$this->runStep(available: false, applied: '', result: [])
		);

		$this->assertSame(
			[
				'info: Initializing Hermiq configuration...',
				'info: Register config version changed (1.0 → 2.0) — forcing re-import.',
				'load force=true',
				'set hermiq register_version_applied 2.0',
				'info: Hermiq configuration imported successfully (version: 2.0.1)',
			],
			$this->runStep(available: true, applied: '1.0', result: ['success' => true, 'version' => '2.0.1'])
		);

		$this->assertSame(
			[
				'info: Initializing Hermiq configuration...',
				'load force=false',
				'info: Hermiq configuration imported successfully (version: unknown)',
			],
			$this->runStep(available: true, applied: '2.0', result: ['success' => true])
		);

		$this->assertSame(
			[
				'info: Initializing Hermiq configuration...',
				'info: Register config version changed ( → 2.0) — forcing re-import.',
				'load force=true',
				'warning: Hermiq configuration import issue: schema clash',
			],
			$this->runStep(available: true, applied: '', result: ['success' => false, 'message' => 'schema clash'])
		);

		$this->assertSame(
			[
				'info: Initializing Hermiq configuration...',
				'load force=false',
				'warning: Hermiq configuration import issue: unknown error',
			],
			$this->runStep(available: true, applied: '2.0', result: ['success' => false])
		);

		$this->assertSame(
			[
				'info: Initializing Hermiq configuration...',
				'info: Register config version changed (1.0 → 2.0) — forcing re-import.',
				'warning: Could not auto-configure Hermiq: boom',
				'log.error: Hermiq initialization failed {"exception":"boom"}',
			],
			$this->runStep(available: true, applied: '1.0', result: new RuntimeException('boom'))
		);
	}//end testEveryBranchWritesItsLinesInOrder()
}//end class
