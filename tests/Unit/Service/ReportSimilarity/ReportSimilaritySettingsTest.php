<?php

/**
 * Hermiq ReportSimilaritySettings unit tests.
 *
 * Characterisation of storing the thresholds and the per-type windows: what is
 * written, what a null leaves in place, and every refusal with its message.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\ReportSimilarity
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
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ReportSimilarity;

use InvalidArgumentException;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilaritySettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Storing the report-similarity settings.
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
 */
class ReportSimilaritySettingsTest extends TestCase {

	/**
	 * The stored JSON, as the app config holds it.
	 *
	 * @var string
	 */
	private string $raw = '';

	/**
	 * How often the settings were written.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Settings over an in-memory app config holding the given settings.
	 *
	 * @param array<string, mixed> $stored The stored settings.
	 *
	 * @return ReportSimilaritySettings
	 */
	private function settings(array $stored = []): ReportSimilaritySettings {
		$this->raw = ($stored === [] ? '' : (string)json_encode($stored));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->raw);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('hermiq', $app);
				$this->assertSame('reportSimilarity', $key);
				$this->raw = $value;
				$this->writes++;
				return true;
			}
		);

		return new ReportSimilaritySettings($config);
	}//end settings()

	/**
	 * New thresholds and windows are stored and returned.
	 *
	 * @return void
	 */
	public function testThresholdsAndWindowsAreStored(): void {
		$result = $this->settings(['extra' => 'kept'])->store(upper: 0.9, lower: 0.4, windows: ['storing' => '60', 7 => 30]);

		$this->assertSame(['upper' => 0.9, 'lower' => 0.4, 'windows' => ['storing' => 60, '7' => 30], 'defaultWindowMinutes' => 1440], $result);
		$this->assertSame(['extra' => 'kept', 'upper' => 0.9, 'lower' => 0.4, 'windows' => ['storing' => 60, 7 => 30]], json_decode($this->raw, true));
		$this->assertSame(1, $this->writes);
	}//end testThresholdsAndWindowsAreStored()

	/**
	 * A null leaves the stored value (or the default) and the stored windows in place.
	 *
	 * @return void
	 */
	public function testNullsLeaveWhatIsThere(): void {
		$result = $this->settings(['upper' => 0.95, 'windows' => ['storing' => 90]])->store(upper: null, lower: null, windows: null);

		$this->assertSame(['upper' => 0.95, 'lower' => 0.55, 'windows' => ['storing' => 90], 'defaultWindowMinutes' => 1440], $result);
		$this->assertSame(['upper' => 0.95, 'windows' => ['storing' => 90], 'lower' => 0.55], json_decode($this->raw, true));
	}//end testNullsLeaveWhatIsThere()

	/**
	 * Every refusal, with its message; nothing is written.
	 *
	 * @return array<string, array{0: float|null, 1: float|null, 2: array|null, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'upper above one' => [1.5, null, null, 'The upper threshold is a score between 0 and 1, and 1.5 is outside that.'],
			'lower below zero' => [null, -0.1, null, 'The lower threshold is a score between 0 and 1, and -0.1 is outside that.'],
			'upper checked first' => [2.0, -1.0, null, 'The upper threshold is a score between 0 and 1, and 2 is outside that.'],
			'lower equals upper' => [0.6, 0.6, null, 'The lower threshold has to sit below the upper one, or there is no middle band for a near-duplicate to fall into, which is the band a human is meant to look at.'],
			'zero window' => [null, null, ['storing' => 5, 'graffiti' => 0], "The window for 'graffiti' is a number of minutes, and it has to be at least one."],
		];
	}//end refusals()

	/**
	 * Each invalid input is refused with its message and nothing is written.
	 *
	 * @param float|null $upper   The upper threshold.
	 * @param float|null $lower   The lower threshold.
	 * @param array|null $windows The windows.
	 * @param string     $message The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testInvalidSettingsAreRefused(?float $upper, ?float $lower, ?array $windows, string $message): void {
		$settings = $this->settings();

		try {
			$settings->store(upper: $upper, lower: $lower, windows: $windows);
			$this->fail('The settings were stored.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertSame(0, $this->writes);
	}//end testInvalidSettingsAreRefused()
}//end class
