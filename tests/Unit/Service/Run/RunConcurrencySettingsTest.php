<?php

/**
 * Unit tests for RunConcurrencySettings.
 *
 * The limits default to 4 and 2, refuse values out of range or an organisation limit
 * above the instance limit, and admit a run only below both.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Run
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Run;

use InvalidArgumentException;
use OCA\Hermiq\Service\Run\RunConcurrencySettings;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
 */
#[CoversClass(RunConcurrencySettings::class)]
final class RunConcurrencySettingsTest extends TestCase {

	/**
	 * Settings over an app config holding one stored value.
	 *
	 * @param string $stored The stored JSON, '' for none.
	 * @param array<int, string> $writes Receives what is written.
	 *
	 * @return RunConcurrencySettings
	 */
	private function settings(string $stored, array &$writes = []): RunConcurrencySettings {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'hermiq' && $key === 'run_concurrency') ? $stored : $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$writes): bool {
				$writes[] = $app . '.' . $key . '=' . $value;
				return true;
			}
		);

		return new RunConcurrencySettings(appConfig: $appConfig);
	}//end settings()

	/**
	 * Nothing stored, or something unreadable, reads as 4 and 2.
	 *
	 * @return void
	 */
	public function testTheDefaultsAreFourAndTwo(): void {
		$this->assertSame(['maxParallel' => 4, 'maxParallelPerOrganisation' => 2], $this->settings('')->limits());
		$this->assertSame(['maxParallel' => 4, 'maxParallelPerOrganisation' => 2], $this->settings('{not json')->limits());
		$this->assertSame(
			['maxParallel' => 4, 'maxParallelPerOrganisation' => 3],
			$this->settings('{"maxParallel": 0, "maxParallelPerOrganisation": 3}')->limits()
		);
	}//end testTheDefaultsAreFourAndTwo()

	/**
	 * Stored limits are written as JSON and read back.
	 *
	 * @return void
	 */
	public function testLimitsAreStoredAndReadBack(): void {
		$writes = [];
		$stored = $this->settings('', $writes)->setLimits(maxParallel: '8', maxParallelPerOrganisation: 3);

		$this->assertSame(['maxParallel' => 8, 'maxParallelPerOrganisation' => 3], $stored);
		$this->assertSame(['hermiq.run_concurrency={"maxParallel":8,"maxParallelPerOrganisation":3}'], $writes);
		$this->assertSame(['maxParallel' => 8, 'maxParallelPerOrganisation' => 3], $this->settings('{"maxParallel":8,"maxParallelPerOrganisation":3}')->limits());
	}//end testLimitsAreStoredAndReadBack()

	/**
	 * A limit out of range, not a whole number, or an organisation limit above the instance limit is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testInvalidLimitsAreRefused(): void {
		foreach ([[0, 1], [65, 2], ['2.5', 1], [null, 1], [4, 5]] as [$instance, $organisation]) {
			$writes = [];
			try {
				$this->settings('', $writes)->setLimits(maxParallel: $instance, maxParallelPerOrganisation: $organisation);
				$this->fail('limits ' . json_encode([$instance, $organisation]) . ' were accepted');
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}

			$this->assertSame([], $writes);
		}
	}//end testInvalidLimitsAreRefused()

	/**
	 * A run is admitted only while both counts are below their limits.
	 *
	 * @return void
	 */
	public function testARunIsAdmittedOnlyBelowBothLimits(): void {
		$settings = $this->settings('{"maxParallel":4,"maxParallelPerOrganisation":2}');

		$this->assertTrue($settings->admits(runningOnInstance: 3, runningInOrganisation: 1));
		$this->assertFalse($settings->admits(runningOnInstance: 3, runningInOrganisation: 2));
		$this->assertFalse($settings->admits(runningOnInstance: 4, runningInOrganisation: 0));
	}//end testARunIsAdmittedOnlyBelowBothLimits()
}//end class
