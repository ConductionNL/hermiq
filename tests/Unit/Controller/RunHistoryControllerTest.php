<?php

/**
 * Unit tests for RunHistoryController (run-audit-log).
 *
 * Focuses on the ADR-005 IDOR guard: only the schedule owner may read its run
 * history; a non-owner (or a cross-tenant object the RBAC read does not return)
 * gets a 404 that leaks nothing, and an unauthenticated caller gets 401.
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
 * @spec openspec/changes/run-audit-log/tasks.md#3-run-history-read-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\RunHistoryController;
use OCA\Hermiq\Service\RunHistoryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the run-audit-log RunHistoryController.
 *
 * @spec openspec/changes/run-audit-log/tasks.md#3-run-history-read-surface
 */
class RunHistoryControllerTest extends TestCase
{

    /**
     * Build a schedule ObjectEntity owned by $owner.
     *
     * @param string $owner The owner UID.
     *
     * @return ObjectEntity
     */
    private function schedule(string $owner): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('sched-1');
        $entity->setOwner($owner);
        $entity->setObject(['name' => 'Nightly']);
        return $entity;

    }//end schedule()

    /**
     * Build the controller with the given collaborators.
     *
     * @param ObjectService     $objectService The object service.
     * @param IUserSession      $userSession   The user session.
     * @param RunHistoryService $runHistory    The run-history service.
     *
     * @return RunHistoryController
     */
    private function controller(
        ObjectService $objectService,
        IUserSession $userSession,
        RunHistoryService $runHistory
    ): RunHistoryController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                return $default;
            }
        );

        return new RunHistoryController(
            $request,
            $objectService,
            $userSession,
            $runHistory,
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
     * The owner receives the run records.
     *
     * @return void
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-4
     */
    public function testOwnerGetsRunHistory(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->method('getRunHistory')->willReturn([['id' => 'run-1', 'status' => 'ok']]);

        $controller = $this->controller($objectService, $this->session('alice'), $runHistory);
        $response   = $controller->index('sched-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(1, $data['results']);
        $this->assertSame('run-1', $data['results'][0]['id']);

    }//end testOwnerGetsRunHistory()

    /**
     * A non-owner is refused with 404 (no cross-tenant leak) and never reads logs.
     *
     * @return void
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-4
     */
    public function testNonOwnerIsRefused(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->expects($this->never())->method('getRunHistory');

        $controller = $this->controller($objectService, $this->session('mallory'), $runHistory);
        $response   = $controller->index('sched-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testNonOwnerIsRefused()

    /**
     * A schedule that the RBAC read does not return (absent/cross-tenant) → 404.
     *
     * @return void
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-4
     */
    public function testMissingScheduleIsNotFound(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(null);

        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->expects($this->never())->method('getRunHistory');

        $controller = $this->controller($objectService, $this->session('alice'), $runHistory);
        $response   = $controller->index('sched-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testMissingScheduleIsNotFound()

    /**
     * An unauthenticated caller gets 401.
     *
     * @return void
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-4
     */
    public function testUnauthenticatedIsRejected(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $runHistory    = $this->createMock(RunHistoryService::class);

        $controller = $this->controller($objectService, $this->session(null), $runHistory);
        $response   = $controller->index('sched-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testUnauthenticatedIsRejected()

    /**
     * The owner receives the run's full trace (run-trace-observability).
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-5-1
     */
    public function testOwnerGetsRunTrace(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $trace      = ['id' => 'run-1', 'scheduleId' => 'sched-1', 'steps' => [['type' => 'context']]];
        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->expects($this->once())
            ->method('getRunTrace')
            ->with('sched-1', 'run-1')
            ->willReturn($trace);

        $controller = $this->controller($objectService, $this->session('alice'), $runHistory);
        $response   = $controller->trace('sched-1', 'run-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($trace, $response->getData());

    }//end testOwnerGetsRunTrace()

    /**
     * A non-owner is refused with 404 (anti-probing) and never reads the trace —
     * identical to `index()`'s existing convention.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-5-1
     */
    public function testNonOwnerIsRefusedForTrace(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->expects($this->never())->method('getRunTrace');

        $controller = $this->controller($objectService, $this->session('mallory'), $runHistory);
        $response   = $controller->trace('sched-1', 'run-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertArrayNotHasKey('steps', $response->getData());

    }//end testNonOwnerIsRefusedForTrace()

    /**
     * An unknown run id (or one belonging to another schedule) surfaces as 404,
     * not an empty/erroring trace.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-5-1
     */
    public function testUnknownRunIsNotFound(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->method('getRunTrace')->willReturn(null);

        $controller = $this->controller($objectService, $this->session('alice'), $runHistory);
        $response   = $controller->trace('sched-1', 'nope');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testUnknownRunIsNotFound()

    /**
     * An unauthenticated caller gets 401 on the trace endpoint too.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-5-1
     */
    public function testUnauthenticatedIsRejectedForTrace(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $runHistory    = $this->createMock(RunHistoryService::class);

        $controller = $this->controller($objectService, $this->session(null), $runHistory);
        $response   = $controller->trace('sched-1', 'run-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testUnauthenticatedIsRejectedForTrace()
}//end class
