<?php

/**
 * Unit tests for RunNowController (agent-management-ui).
 *
 * Focuses on the ADR-005 IDOR guard and the run delegation: only the schedule
 * owner may trigger a run; a non-owner (or a cross-tenant object the RBAC read
 * does not return) gets a 404 that leaks nothing and never reaches the service,
 * an unauthenticated caller gets 401, and the owner's run delegates to
 * ScheduleService::runNow and returns the refreshed run status.
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
 * @spec openspec/changes/agent-management-ui/tasks.md#1-backend-thin-run-now-endpoint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\RunNowController;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-management-ui RunNowController.
 *
 * @spec openspec/changes/agent-management-ui/tasks.md#1-backend-thin-run-now-endpoint
 */
class RunNowControllerTest extends TestCase
{

    /**
     * Build a schedule ObjectEntity owned by $owner with the given payload.
     *
     * @param string              $owner   The owner UID.
     * @param array<string,mixed> $payload The schedule object body.
     *
     * @return ObjectEntity
     */
    private function schedule(string $owner, array $payload=[]): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('sched-1');
        $entity->setOwner($owner);
        $entity->setObject($payload);
        return $entity;

    }//end schedule()

    /**
     * Build the controller with the given collaborators.
     *
     * @param ObjectService   $objectService   The object service.
     * @param IUserSession    $userSession     The user session.
     * @param ScheduleService $scheduleService The schedule service.
     *
     * @return RunNowController
     */
    private function controller(
        ObjectService $objectService,
        IUserSession $userSession,
        ScheduleService $scheduleService
    ): RunNowController {
        return new RunNowController(
            $this->createMock(IRequest::class),
            $objectService,
            $userSession,
            $scheduleService,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

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
     * The owner triggers a run: runNow is invoked and the refreshed status returned.
     *
     * @return void
     *
     * @spec openspec/changes/agent-management-ui/tasks.md#task-1-3
     */
    public function testOwnerRunsSchedule(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->schedule('alice', ['lastStatus' => 'ok', 'lastError' => null, 'nextRun' => '2030-01-01T08:00:00+00:00'])
        );

        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->expects($this->once())->method('runNow');

        $controller = $this->controller($objectService, $this->session('alice'), $scheduleService);
        $response   = $controller->run('sched-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('sched-1', $data['scheduleId']);

    }//end testOwnerRunsSchedule()

    /**
     * An OpenRegister agent error recorded on the schedule surfaces as status=error.
     *
     * @return void
     *
     * @spec openspec/changes/agent-management-ui/tasks.md#task-1-3
     */
    public function testAgentErrorSurfacesAsErrorStatus(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(
            $this->schedule('alice', ['lastStatus' => 'error', 'lastError' => 'Undefined column: agents.foo'])
        );

        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->expects($this->once())->method('runNow');

        $controller = $this->controller($objectService, $this->session('alice'), $scheduleService);
        $response   = $controller->run('sched-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('error', $data['status']);
        $this->assertStringContainsString('Undefined column', (string) $data['error']);

    }//end testAgentErrorSurfacesAsErrorStatus()

    /**
     * A non-owner is refused with 404 (no cross-tenant leak) and never runs.
     *
     * @return void
     *
     * @spec openspec/changes/agent-management-ui/tasks.md#task-1-3
     */
    public function testNonOwnerIsRefused(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->expects($this->never())->method('runNow');

        $controller = $this->controller($objectService, $this->session('mallory'), $scheduleService);
        $response   = $controller->run('sched-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testNonOwnerIsRefused()

    /**
     * A schedule the RBAC read does not return (absent/cross-tenant) → 404, no run.
     *
     * @return void
     *
     * @spec openspec/changes/agent-management-ui/tasks.md#task-1-3
     */
    public function testMissingScheduleIsNotFound(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(null);

        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->expects($this->never())->method('runNow');

        $controller = $this->controller($objectService, $this->session('alice'), $scheduleService);
        $response   = $controller->run('sched-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testMissingScheduleIsNotFound()

    /**
     * An unauthenticated caller gets 401 and never runs.
     *
     * @return void
     *
     * @spec openspec/changes/agent-management-ui/tasks.md#task-1-3
     */
    public function testUnauthenticatedIsRejected(): void
    {
        $objectService   = $this->createMock(ObjectService::class);
        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->expects($this->never())->method('runNow');

        $controller = $this->controller($objectService, $this->session(null), $scheduleService);
        $response   = $controller->run('sched-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testUnauthenticatedIsRejected()
}//end class
