<?php

/**
 * Unit tests for the form AdminSettings::getForm() renders.
 *
 * Pins the template, the version parameter read for the hermiq app id, and the
 * is_admin flag for a signed-in user who is not an administrator.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the AdminSettings form response.
 */
#[CoversClass(AdminSettings::class)]
class AdminSettingsFormTest extends TestCase {
	/**
	 * Build the settings with a signed-in user of the given UID.
	 *
	 * @param IAppManager     $appManager   The app manager
	 * @param IGroupManager   $groupManager The group manager
	 * @param IInitialState   $initialState The initial state
	 * @param string          $uid          The signed-in UID
	 *
	 * @return AdminSettings
	 */
	private function settings(IAppManager $appManager, IGroupManager $groupManager, IInitialState $initialState, string $uid): AdminSettings {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AdminSettings(
			appManager: $appManager,
			initialState: $initialState,
			groupManager: $groupManager,
			userSession: $session,
		);
	}//end settings()

	/**
	 * The form renders the admin template of the hermiq app with the version of
	 * hermiq itself as its only parameter.
	 *
	 * @return void
	 */
	public function testRendersTheAdminTemplateWithTheHermiqVersion(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('getAppVersion')->with(Application::APP_ID)->willReturn('2.4.1');
		$appManager->method('isInstalled')->willReturn(false);

		$response = $this->settings(
			$appManager,
			$this->createMock(IGroupManager::class),
			$this->createMock(IInitialState::class),
			'carol'
		)->getForm();

		$this->assertSame('settings/admin', $response->getTemplateName());
		$this->assertSame(['version' => '2.4.1'], $response->getParams());
		$this->assertSame('hermiq', Application::APP_ID);
	}//end testRendersTheAdminTemplateWithTheHermiqVersion()

	/**
	 * A signed-in user who is not an administrator gets is_admin false; the
	 * check is made for that user's own UID, and the flags are provided in order.
	 *
	 * @return void
	 */
	public function testASignedInNonAdminIsNotFlaggedAdmin(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('2.4.1');
		$appManager->expects($this->once())->method('isInstalled')->with('opencatalogi')->willReturn(true);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->once())->method('isAdmin')->with('carol')->willReturn(false);

		$provided = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->exactly(2))->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value) use (&$provided): void {
				$provided[] = [$key, $value];
			}
		);

		$this->settings($appManager, $groupManager, $initialState, 'carol')->getForm();

		$this->assertSame([['is_admin', false], ['opencatalogi_available', true]], $provided);
	}//end testASignedInNonAdminIsNotFlaggedAdmin()
}//end class
