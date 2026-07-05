<?php

/**
 * Unit tests for TenantControlController (human-approval-gate-enforcement).
 *
 * Focuses on the kill-switch authorization: only a Nextcloud instance admin or a
 * sub-admin of the organisation's NC group may read/toggle the kill-switch; a plain
 * user (or a foreign-org admin) is refused and never reaches the write path.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#5-kill-switch-toggle-endpoint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\TenantControlController;
use OCA\Hermiq\Service\TenantControlService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the human-approval-gate-enforcement TenantControlController.
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#5-kill-switch-toggle-endpoint
 */
class TenantControlControllerTest extends TestCase
{

    /**
     * A session with the given (or no) user.
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
     * A request whose params resolve from the given map.
     *
     * @param array<string,mixed> $params The param map.
     *
     * @return IRequest
     */
    private function request(array $params=[]): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default=null) => ($params[$key] ?? $default)
        );
        return $request;

    }//end request()

    /**
     * Build the controller with the given collaborators.
     *
     * @param IRequest             $request      The request.
     * @param TenantControlService $service      The kill-switch service.
     * @param IUserSession         $session      The user session.
     * @param IGroupManager        $groupManager The group manager.
     * @param ISubAdmin            $subAdmin     The sub-admin service.
     *
     * @return TenantControlController
     */
    private function controller(
        IRequest $request,
        TenantControlService $service,
        IUserSession $session,
        IGroupManager $groupManager,
        ISubAdmin $subAdmin
    ): TenantControlController {
        return new TenantControlController(
            $request,
            $service,
            $session,
            $groupManager,
            $subAdmin,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * An instance admin can read the kill-switch state.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
     */
    public function testInstanceAdminCanShow(): void
    {
        $control = new ObjectEntity();
        $control->setObject(['engaged' => true, 'reason' => 'incident']);

        $service = $this->createMock(TenantControlService::class);
        $service->method('getForOrganisation')->willReturn($control);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);

        $controller = $this->controller(
            $this->request(),
            $service,
            $this->session('root'),
            $groupManager,
            $this->createMock(ISubAdmin::class)
        );
        $response = $controller->show('org-x');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['engaged']);

    }//end testInstanceAdminCanShow()

    /**
     * A group sub-admin can toggle their organisation's kill-switch.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
     */
    public function testSubAdminCanToggle(): void
    {
        $saved = new ObjectEntity();
        $saved->setObject(['engaged' => true]);

        $service = $this->createMock(TenantControlService::class);
        $service->expects($this->once())->method('toggle')->willReturn($saved);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);
        $groupManager->method('get')->willReturn($this->createMock(IGroup::class));

        $subAdmin = $this->createMock(ISubAdmin::class);
        $subAdmin->method('isSubAdminOfGroup')->willReturn(true);

        $controller = $this->controller(
            $this->request(['engaged' => 'true', 'reason' => 'pause']),
            $service,
            $this->session('bob'),
            $groupManager,
            $subAdmin
        );
        $response = $controller->toggle('org-x');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['engaged']);

    }//end testSubAdminCanToggle()

    /**
     * A plain user (not admin, not sub-admin) cannot toggle — 403, never writes.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
     */
    public function testNonAdminCannotToggle(): void
    {
        $service = $this->createMock(TenantControlService::class);
        $service->expects($this->never())->method('toggle');

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);
        $groupManager->method('get')->willReturn($this->createMock(IGroup::class));

        $subAdmin = $this->createMock(ISubAdmin::class);
        $subAdmin->method('isSubAdminOfGroup')->willReturn(false);

        $controller = $this->controller(
            $this->request(['engaged' => 'true']),
            $service,
            $this->session('mallory'),
            $groupManager,
            $subAdmin
        );
        $response = $controller->toggle('org-x');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testNonAdminCannotToggle()

    /**
     * A non-admin cannot even read another organisation's state — 404 (no leak).
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
     */
    public function testNonAdminShowIsNotFound(): void
    {
        $service = $this->createMock(TenantControlService::class);
        $service->expects($this->never())->method('getForOrganisation');

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);
        $groupManager->method('get')->willReturn(null);

        $controller = $this->controller(
            $this->request(),
            $service,
            $this->session('mallory'),
            $groupManager,
            $this->createMock(ISubAdmin::class)
        );
        $response = $controller->show('org-x');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testNonAdminShowIsNotFound()

    /**
     * An unauthenticated caller gets 401.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
     */
    public function testUnauthenticatedIsRejected(): void
    {
        $controller = $this->controller(
            $this->request(),
            $this->createMock(TenantControlService::class),
            $this->session(null),
            $this->createMock(IGroupManager::class),
            $this->createMock(ISubAdmin::class)
        );
        $response = $controller->toggle('org-x');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testUnauthenticatedIsRejected()
}//end class
