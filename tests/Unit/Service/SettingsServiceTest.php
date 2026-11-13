<?php

/**
 * Hermiq SettingsService read/write unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for getSettings(), updateSettings() and the bundled register version.
 *
 * @covers \OCA\Hermiq\Service\SettingsService
 */
class SettingsServiceTest extends TestCase {

	/**
	 * Build the service.
	 *
	 * @param IAppConfig  $config      The app config.
	 * @param bool        $orInstalled Whether OpenRegister is installed.
	 * @param string|null $uid         The signed-in uid, or null.
	 * @param bool        $admin       Whether that uid is an admin.
	 *
	 * @return SettingsService The service.
	 */
	private function service(IAppConfig $config, bool $orInstalled, ?string $uid, bool $admin): SettingsService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('openregister')->willReturn($orInstalled);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $id): bool => $admin && $id === $uid);

		return new SettingsService(
			appConfig: $config,
			appManager: $apps,
			container: $this->createMock(ContainerInterface::class),
			groupManager: $groups,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Settings carry the stored register, OpenRegister's presence and the admin flag.
	 *
	 * @return void
	 */
	public function testGetSettingsReportsRegisterAvailabilityAndAdmin(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('hermiq', 'register', '')->willReturn('reg-7');

		$this->assertSame(
			['register' => 'reg-7', 'openregisters' => true, 'isAdmin' => true],
			$this->service($config, true, 'admin', true)->getSettings()
		);
		$this->assertSame(
			['register' => 'reg-7', 'openregisters' => false, 'isAdmin' => false],
			$this->service($config, false, 'alice', false)->getSettings()
		);
		$this->assertFalse($this->service($config, true, null, true)->getSettings()['isAdmin']);
	}//end testGetSettingsReportsRegisterAvailabilityAndAdmin()

	/**
	 * Only known keys that are present are written, as strings.
	 *
	 * @return void
	 */
	public function testUpdateSettingsWritesOnlyKnownPresentKeys(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueString')->with('hermiq', 'register', '42');
		$config->method('getValueString')->willReturn('42');

		$result = $this->service($config, true, 'admin', true)->updateSettings(['register' => 42, 'apiKey' => 'secret']);

		$this->assertSame('42', $result['register']);
		$this->assertArrayNotHasKey('apiKey', $result);
	}//end testUpdateSettingsWritesOnlyKnownPresentKeys()

	/**
	 * An update without known keys writes nothing.
	 *
	 * @return void
	 */
	public function testUpdateSettingsWithoutKnownKeysWritesNothing(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setValueString');
		$config->method('getValueString')->willReturn('');

		$this->service($config, true, 'admin', true)->updateSettings(['register' => null, 'other' => 'x']);
	}//end testUpdateSettingsWithoutKnownKeysWritesNothing()

	/**
	 * The register config version is the bundled file's info.version.
	 *
	 * @return void
	 */
	public function testRegisterConfigVersionIsTheBundledFilesVersion(): void {
		$bundled = json_decode((string)file_get_contents(SettingsLoadConfigurationGoldenTest::REGISTER_FILE), true);

		$version = $this->service($this->createMock(IAppConfig::class), true, null, false)->getRegisterConfigVersion();

		$this->assertSame((string)$bundled['info']['version'], $version);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
	}//end testRegisterConfigVersionIsTheBundledFilesVersion()
}//end class
