<?php

/**
 * Pins how the stored intake threshold is read, before its split.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Intake;

use OCA\Hermiq\Service\Intake\IntakeSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Intake\IntakeSettings
 */
class IntakeSettingsTest extends TestCase {

	/**
	 * Stored values and the threshold each reads as.
	 *
	 * @return array<string, array{0: string, 1: float}>
	 */
	public static function storedValues(): array {
		return [
			'nothing stored' => ['', 0.8],
			'not json' => ['{oops', 0.8],
			'a bare number' => ['0.5', 0.8],
			'no threshold key' => ['{"other":1}', 0.8],
			'a word' => ['{"abstentionThreshold":"high"}', 0.8],
			'below zero' => ['{"abstentionThreshold":-0.1}', 0.8],
			'above one' => ['{"abstentionThreshold":1.5}', 0.8],
			'zero' => ['{"abstentionThreshold":0}', 0.0],
			'one' => ['{"abstentionThreshold":1}', 1.0],
			'a numeric string' => ['{"abstentionThreshold":"0.65"}', 0.65],
			'a fraction' => ['{"abstentionThreshold":0.42}', 0.42],
		];
	}//end storedValues()

	/**
	 * Each stored value reads as its threshold; anything unusable as the default.
	 *
	 * @param string $stored   The stored config value.
	 * @param float  $expected The threshold read.
	 *
	 * @return void
	 */
	#[DataProvider('storedValues')]
	public function testTheStoredThresholdIsReadOrDefaulted(string $stored, float $expected): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('hermiq', 'intake', '')->willReturn($stored);

		$this->assertSame($expected, (new IntakeSettings($config))->abstentionThreshold());
	}//end testTheStoredThresholdIsReadOrDefaulted()
}//end class
