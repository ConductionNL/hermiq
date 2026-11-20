<?php
/**
 * Unit tests for the EnableEngineOnInstall repair step.
 *
 * A new install gets the in-app agent engine on; an install that already
 * stores a value, on or off, keeps it.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\EnableEngineOnInstall;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Repair\EnableEngineOnInstall
 *
 * @spec exclude Product decision (engine on by default for new installs); no capability spec covers the install default.
 */
final class EnableEngineOnInstallTest extends TestCase {

	/**
	 * A new install, with no stored flag, gets `engine.enabled=true`.
	 *
	 * @return void
	 */
	public function testANewInstallTurnsTheEngineOn(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('hasKey')
			->with('hermiq', 'engine.enabled')
			->willReturn(false);
		$appConfig->expects($this->once())
			->method('setValueString')
			->with('hermiq', 'engine.enabled', 'true');

		(new EnableEngineOnInstall($appConfig))->run($this->createMock(IOutput::class));
	}//end testANewInstallTurnsTheEngineOn()

	/**
	 * A stored value (an administrator switched the engine off) is kept.
	 *
	 * @return void
	 */
	public function testAStoredValueIsKept(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')
			->with('hermiq', 'engine.enabled')
			->willReturn(true);
		$appConfig->expects($this->never())->method('setValueString');
		$appConfig->expects($this->never())->method('setValueBool');

		(new EnableEngineOnInstall($appConfig))->run($this->createMock(IOutput::class));
	}//end testAStoredValueIsKept()

	/**
	 * The step is registered for install only, never on upgrade, so an
	 * existing install keeps an unset flag (read as off).
	 *
	 * @return void
	 */
	public function testTheStepRunsOnInstallOnly(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		$install = array_map('strval', $info->xpath('/info/repair-steps/install/step'));
		$upgrade = array_map('strval', $info->xpath('/info/repair-steps/post-migration/step'));

		$this->assertContains(EnableEngineOnInstall::class, $install);
		$this->assertNotContains(EnableEngineOnInstall::class, $upgrade);
	}//end testTheStepRunsOnInstallOnly()
}//end class
