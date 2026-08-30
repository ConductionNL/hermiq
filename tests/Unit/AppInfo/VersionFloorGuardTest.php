<?php

/**
 * Regression guard: the declared Nextcloud floor must cover every NC bootstrap API.
 *
 * hermiq's `lib/AppInfo/Application.php::register()` calls
 * `IRegistrationContext::registerTaskProcessingProvider()` (`@since 30.0.0`)
 * unconditionally for its four TaskProcessing providers. If the declared
 * `appinfo/info.xml` `<nextcloud min-version>` is lower than that API's `@since`,
 * the app fatals during bootstrap on the unsupported-but-advertised NC versions
 * (the exact "install-then-fatal" defect this change fixes).
 *
 * This test locks the invariant: for every known `@since`-gated `IRegistrationContext`
 * registration API that `register()` calls unconditionally, the API's `@since` major
 * MUST be `<=` the declared `min-version`. It fails if `min-version` is ever lowered
 * below 30 while `registerTaskProcessingProvider` remains, or if a newer-`@since`
 * unconditional NC API is added without also raising the floor (or guarding the call).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/raise-nc-minversion-taskprocessing/tasks.md#2-regression-guard
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the info.xml Nextcloud floor never drifts below a bootstrap API's @since.
 *
 * @spec openspec/changes/raise-nc-minversion-taskprocessing/tasks.md#task-2-1
 */
class VersionFloorGuardTest extends TestCase {

	/**
	 * Known `@since` (major NC version) of the `IRegistrationContext` registration APIs
	 * hermiq may call in `Application::register()`. Seeded with the one that triggered
	 * this change; extend this map when a newer-`@since` registration API is adopted.
	 *
	 * @var array<string, int>
	 */
	private const API_SINCE_MAJOR = [
		'registerTaskProcessingProvider' => 30,
		// Same NC floor as the TaskProcessing API — harmless if unused, keeps the map honest.
		'registerTaskProcessingTaskType' => 30,
	];

	/**
	 * Absolute path to the repository root (three levels up from tests/Unit/AppInfo).
	 *
	 * @return string The repo root path.
	 */
	private function repoRoot(): string {
		return dirname(__DIR__, 3);
	}//end repoRoot()

	/**
	 * Extract the declared Nextcloud `min-version` (major int) from `appinfo/info.xml`.
	 *
	 * @return int The declared min-version major (e.g. 30).
	 */
	private function declaredMinVersion(): int {
		$xml = file_get_contents($this->repoRoot() . '/appinfo/info.xml');
		$this->assertIsString($xml, 'appinfo/info.xml must be readable');

		$matched = preg_match('/<nextcloud\s+min-version="(\d+)"/', $xml, $m);
		$this->assertSame(1, $matched, 'info.xml must declare a <nextcloud min-version="…"/>');

		return (int)$m[1];
	}//end declaredMinVersion()

	/**
	 * The `IRegistrationContext` registration APIs called unconditionally in `register()`.
	 *
	 * "Unconditionally" = the `$context->api(` call is not wrapped in a version check.
	 * A call guarded by an `if (version_compare(...))` (or similar) is excluded, because
	 * such a guard makes the call safe on lower floors.
	 *
	 * @return array<int, string> The API method names called unconditionally.
	 */
	private function unconditionalRegistrationCalls(): array {
		$source = file_get_contents($this->repoRoot() . '/lib/AppInfo/Application.php');
		$this->assertIsString($source, 'lib/AppInfo/Application.php must be readable');

		$calls = [];
		foreach (array_keys(self::API_SINCE_MAJOR) as $api) {
			// Any unguarded `$context->registerXxx(` occurrence. A version_compare guard
			// on the same line would make it conditional; none exists today.
			if (preg_match('/\$context->' . preg_quote($api, '/') . '\s*\(/', $source) === 1) {
				$calls[] = $api;
			}
		}

		return $calls;
	}//end unconditionalRegistrationCalls()

	/**
	 * Pure drift analyzer: list APIs whose `@since` major exceeds the declared floor.
	 *
	 * @param int $minVersion The declared min-version major.
	 * @param array<int, string> $unconditionalCalls The unconditionally-called API names.
	 * @param array<string, int> $apiSinceMajor Map of API name → `@since` major.
	 *
	 * @return array<int, string> The offending API names (empty = no drift).
	 */
	public static function violations(int $minVersion, array $unconditionalCalls, array $apiSinceMajor): array {
		$offenders = [];
		foreach ($unconditionalCalls as $api) {
			$since = ($apiSinceMajor[$api] ?? 0);
			if ($since > $minVersion) {
				$offenders[] = $api;
			}
		}

		return $offenders;
	}//end violations()

	/**
	 * The live invariant: the declared floor covers every unconditional NC bootstrap API.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/raise-nc-minversion-taskprocessing/tasks.md#task-2-1
	 */
	public function testDeclaredFloorCoversEveryUnconditionalNcApi(): void {
		$minVersion = $this->declaredMinVersion();
		$calls = $this->unconditionalRegistrationCalls();

		// registerTaskProcessingProvider must actually be one of them (guards against a
		// silent refactor that removes the call and makes the guard vacuous).
		$this->assertContains(
			'registerTaskProcessingProvider',
			$calls,
			'Application::register() is expected to call registerTaskProcessingProvider unconditionally'
		);

		$offenders = self::violations($minVersion, $calls, self::API_SINCE_MAJOR);

		$this->assertSame(
			[],
			$offenders,
			sprintf(
				'info.xml min-version="%d" is below the @since of unconditionally-called NC API(s): %s. '
				. 'Raise the floor or guard the call(s) behind a version check.',
				$minVersion,
				implode(', ', $offenders)
			)
		);

	}//end testDeclaredFloorCoversEveryUnconditionalNcApi()

	/**
	 * The declared floor is at least 30 (the `@since` of registerTaskProcessingProvider).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/raise-nc-minversion-taskprocessing/tasks.md#task-2-1
	 */
	public function testDeclaredFloorIsAtLeast30(): void {
		$this->assertGreaterThanOrEqual(
			30,
			$this->declaredMinVersion(),
			'registerTaskProcessingProvider is @since 30.0.0; the declared floor must be >= 30'
		);

	}//end testDeclaredFloorIsAtLeast30()

	/**
	 * The analyzer flags a floor lowered below a called API's `@since` (drift detection).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/raise-nc-minversion-taskprocessing/tasks.md#task-2-1
	 */
	public function testGuardFailsWhenFloorLoweredBelowApiSince(): void {
		$offenders = self::violations(29, ['registerTaskProcessingProvider'], self::API_SINCE_MAJOR);

		$this->assertContains(
			'registerTaskProcessingProvider',
			$offenders,
			'A min-version of 29 with an unconditional @since-30 call must be flagged'
		);

	}//end testGuardFailsWhenFloorLoweredBelowApiSince()

	/**
	 * The analyzer flags a newer-`@since` API added at the current floor without raising it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/raise-nc-minversion-taskprocessing/tasks.md#task-2-1
	 */
	public function testGuardFailsWhenNewerApiAddedWithoutRaisingFloor(): void {
		$offenders = self::violations(
			30,
			['registerHypotheticalNc31Api'],
			['registerHypotheticalNc31Api' => 31]
		);

		$this->assertContains(
			'registerHypotheticalNc31Api',
			$offenders,
			'An @since-31 unconditional call at floor 30 must be flagged'
		);

	}//end testGuardFailsWhenNewerApiAddedWithoutRaisingFloor()

	/**
	 * The analyzer passes at floor 30 with the current TaskProcessing registration calls.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/raise-nc-minversion-taskprocessing/tasks.md#task-2-1
	 */
	public function testGuardPassesAtFloor30WithCurrentCalls(): void {
		$offenders = self::violations(30, ['registerTaskProcessingProvider'], self::API_SINCE_MAJOR);

		$this->assertSame([], $offenders, 'Floor 30 with an @since-30 call must not be flagged');

	}//end testGuardPassesAtFloor30WithCurrentCalls()
}//end class
