<?php

/**
 * Unit tests for AdminSettings (ai-features-to-admin).
 *
 * Covers the two `IInitialState` keys `getForm()` must provide so the relocated
 * AiFeatureRegister section (moved from the in-app `/ai-features` nav page into
 * `/settings/admin/hermiq`) resolves its Algoritmeregister visibility gating:
 * `is_admin` (resolved via IGroupManager/IUserSession, mirroring
 * DashboardController::provideKillSwitchCapability()) and `opencatalogi_available`
 * (resolved via IAppManager::isInstalled()). Also pins the unchanged
 * IDelegatedSettings accessors (getSection/getPriority/getName/getAuthorizedAppConfig).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-features-to-admin/tasks.md#task-3-provide-is_admin--opencatalogi_available-from-the-admin-settings-bootstrap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use OCA\Hermiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AdminSettings.
 *
 * @spec openspec/changes/ai-features-to-admin/tasks.md#task-3-provide-is_admin--opencatalogi_available-from-the-admin-settings-bootstrap
 */
class AdminSettingsTest extends TestCase
{
    /**
     * A user session resolving to the given UID, or an unauthenticated session when null.
     *
     * @param string|null $uid The UID, or null for unauthenticated.
     *
     * @return IUserSession
     */
    private function session(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
            return $session;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;

    }//end session()

    /**
     * getForm() provides is_admin=true and opencatalogi_available=true for an admin
     * when OpenCatalogi is installed, and returns the unchanged TemplateResponse shape.
     *
     * @return void
     */
    public function testGetFormProvidesIsAdminTrueAndOpencatalogiAvailableTrue(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppVersion')->willReturn('0.1.60');
        $appManager->method('isInstalled')->with('opencatalogi')->willReturn(true);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->with('admin-uid')->willReturn(true);

        $initialState = $this->createMock(IInitialState::class);
        $calls        = [];
        $initialState->expects($this->exactly(2))
            ->method('provideInitialState')
            ->willReturnCallback(
                function (string $key, $value) use (&$calls): void {
                    $calls[$key] = $value;
                }
            );

        $settings = new AdminSettings(
            appManager: $appManager,
            initialState: $initialState,
            groupManager: $groupManager,
            userSession: $this->session('admin-uid'),
        );

        $response = $settings->getForm();

        self::assertTrue($calls['is_admin']);
        self::assertTrue($calls['opencatalogi_available']);
        self::assertInstanceOf(TemplateResponse::class, $response);

    }//end testGetFormProvidesIsAdminTrueAndOpencatalogiAvailableTrue()

    /**
     * getForm() provides opencatalogi_available=false when OpenCatalogi is absent, and
     * is_admin=false when no user is resolvable (defensive — this page is only ever
     * reachable by a full instance admin in practice, per getAuthorizedAppConfig()).
     *
     * @return void
     */
    public function testGetFormProvidesFalseFlagsWhenOpencatalogiAbsentAndNoUser(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppVersion')->willReturn('0.1.60');
        $appManager->method('isInstalled')->with('opencatalogi')->willReturn(false);

        $groupManager = $this->createMock(IGroupManager::class);

        $initialState = $this->createMock(IInitialState::class);
        $calls        = [];
        $initialState->expects($this->exactly(2))
            ->method('provideInitialState')
            ->willReturnCallback(
                function (string $key, $value) use (&$calls): void {
                    $calls[$key] = $value;
                }
            );

        $settings = new AdminSettings(
            appManager: $appManager,
            initialState: $initialState,
            groupManager: $groupManager,
            userSession: $this->session(null),
        );

        $settings->getForm();

        self::assertFalse($calls['is_admin']);
        self::assertFalse($calls['opencatalogi_available']);

    }//end testGetFormProvidesFalseFlagsWhenOpencatalogiAbsentAndNoUser()

    /**
     * The IDelegatedSettings accessors are unchanged by this relocation: the panel
     * stays a single, non-delegated, full-admin-only Hermiq section.
     *
     * @return void
     */
    public function testDelegatedSettingsAccessorsUnchanged(): void
    {
        $settings = new AdminSettings(
            appManager: $this->createMock(IAppManager::class),
            initialState: $this->createMock(IInitialState::class),
            groupManager: $this->createMock(IGroupManager::class),
            userSession: $this->session(null),
        );

        self::assertSame('hermiq', $settings->getSection());
        self::assertSame(10, $settings->getPriority());
        self::assertNull($settings->getName());
        self::assertSame([], $settings->getAuthorizedAppConfig());

    }//end testDelegatedSettingsAccessorsUnchanged()
}//end class
