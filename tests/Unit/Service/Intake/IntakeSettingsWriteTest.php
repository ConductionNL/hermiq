<?php

/**
 * Hermiq IntakeSettings write unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Intake;

use InvalidArgumentException;
use OCA\Hermiq\Service\Intake\IntakeSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for storing the abstention threshold and reporting the settings.
 *
 * @covers \OCA\Hermiq\Service\Intake\IntakeSettings
 */
class IntakeSettingsWriteTest extends TestCase {

	/**
	 * A threshold inside [0, 1] is stored as JSON and returned.
	 *
	 * @return void
	 */
	public function testAThresholdInRangeIsStored(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->exactly(3))
			->method('setValueString')
			->willReturnCallback(
				function (string $app, string $key, string $value): bool {
					$this->assertSame(['hermiq', 'intake'], [$app, $key]);
					$this->assertArrayHasKey('abstentionThreshold', json_decode($value, true));
					return true;
				}
			);
		$settings = new IntakeSettings(appConfig: $config);

		$this->assertSame(0.0, $settings->setAbstentionThreshold(0.0));
		$this->assertSame(0.65, $settings->setAbstentionThreshold(0.65));
		$this->assertSame(1.0, $settings->setAbstentionThreshold(1.0));
	}//end testAThresholdInRangeIsStored()

	/**
	 * A threshold outside [0, 1] is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAThresholdOutOfRangeIsRefused(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setValueString');
		$settings = new IntakeSettings(appConfig: $config);

		foreach ([-0.01, 1.01] as $threshold) {
			try {
				$settings->setAbstentionThreshold($threshold);
				$this->fail('Expected a refusal for ' . $threshold);
			} catch (InvalidArgumentException $e) {
				$this->assertSame(
					sprintf('A confidence threshold is between 0 and 1, and %s is outside that.', (string)$threshold),
					$e->getMessage()
				);
			}
		}
	}//end testAThresholdOutOfRangeIsRefused()

	/**
	 * all() reports the effective threshold next to the default.
	 *
	 * @return void
	 */
	public function testAllReportsTheEffectiveThresholdAndTheDefault(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnOnConsecutiveCalls('{"abstentionThreshold":0.55}', '');
		$settings = new IntakeSettings(appConfig: $config);

		$this->assertSame(['abstentionThreshold' => 0.55, 'default' => 0.8], $settings->all());
		$this->assertSame(['abstentionThreshold' => 0.8, 'default' => 0.8], $settings->all());
	}//end testAllReportsTheEffectiveThresholdAndTheDefault()
}//end class
