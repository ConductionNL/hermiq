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
use OCA\Hermiq\Service\EngineRequiredException;
use OCA\Hermiq\Service\RunHistoryService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
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
     * @param ObjectService       $objectService   The object service.
     * @param IUserSession        $userSession     The user session.
     * @param RunHistoryService   $runHistory      The run-history service.
     * @param ScheduleService|null $scheduleService The schedule service (run-replay-and-dry-run's
     *                                              replay() action); defaults to an unconfigured
     *                                              mock — only exercised by replay()-specific tests.
     *
     * @return RunHistoryController
     */
    private function controller(
        ObjectService $objectService,
        IUserSession $userSession,
        RunHistoryService $runHistory,
        ?ScheduleService $scheduleService=null
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
            $scheduleService ?? $this->createMock(ScheduleService::class),
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
     * A THROWING lookup is the same 404, and still reads no history.
     *
     * `ObjectService::find()` documents `@throws Exception If the object is not
     * found` and only returns null on some paths, so "absent" reaches this
     * controller both ways. `index()` and `replay()` call `loadOwnedSchedule()`
     * BEFORE opening their own try block, so before the fix the throw escaped to
     * the dispatcher as a framework 500 with a stack trace on a
     * `#[NoAdminRequired]` route.
     *
     * @return void
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-4
     */
    public function testThrowingScheduleLookupIsNotFound(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willThrowException(new DoesNotExistException('no such object'));

        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->expects($this->never())->method('getRunHistory');

        $controller = $this->controller($objectService, $this->session('alice'), $runHistory);

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->index('sched-1')->getStatus());

    }//end testThrowingScheduleLookupIsNotFound()

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
     * @spec openspec/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
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
     * @spec openspec/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
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
     * @spec openspec/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
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
     * @spec openspec/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
     */
    public function testUnauthenticatedIsRejectedForTrace(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $runHistory    = $this->createMock(RunHistoryService::class);

        $controller = $this->controller($objectService, $this->session(null), $runHistory);
        $response   = $controller->trace('sched-1', 'run-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testUnauthenticatedIsRejectedForTrace()

    /**
     * The owner replays a run: the pre-fetched original trace's `prompt` is
     * validated, `ScheduleService::replayRun()` is invoked, and its outcome
     * returned (run-replay-and-dry-run).
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome
     */
    public function testOwnerReplaysRun(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $originalTrace = ['id' => 'run-1', 'prompt' => 'the recorded prompt', 'steps' => [], 'status' => 'ok', 'summary' => 'orig'];
        $runHistory    = $this->createMock(RunHistoryService::class);
        $runHistory->method('getRunTrace')->willReturn($originalTrace);

        $replayResult    = ['status' => 'ok', 'scheduleId' => 'sched-1', 'replayOf' => 'run-1', 'original' => [], 'replay' => [], 'diff' => []];
        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->expects($this->once())
            ->method('replayRun')
            ->with($this->schedule('alice'), 'run-1', $originalTrace)
            ->willReturn($replayResult);

        $controller = $this->controller($objectService, $this->session('alice'), $runHistory, $scheduleService);
        $response   = $controller->replay('sched-1', 'run-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($replayResult, $response->getData());

    }//end testOwnerReplaysRun()

    /**
     * A non-owner is refused replay with 404 and never replays.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome
     */
    public function testNonOwnerIsRefusedForReplay(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $runHistory      = $this->createMock(RunHistoryService::class);
        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->expects($this->never())->method('replayRun');

        $controller = $this->controller($objectService, $this->session('mallory'), $runHistory, $scheduleService);
        $response   = $controller->replay('sched-1', 'run-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testNonOwnerIsRefusedForReplay()

    /**
     * A run with no persisted prompt (recorded before this change shipped) is
     * refused cleanly with 404, never a crash, and never reaches
     * `ScheduleService::replayRun()`.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome
     */
    public function testReplayRefusesCleanlyWhenPromptMissing(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->method('getRunTrace')->willReturn(['id' => 'run-1', 'steps' => [], 'status' => 'ok']);

        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->expects($this->never())->method('replayRun');

        $controller = $this->controller($objectService, $this->session('alice'), $runHistory, $scheduleService);
        $response   = $controller->replay('sched-1', 'run-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testReplayRefusesCleanlyWhenPromptMissing()

    /**
     * A governance gate blocking the replay surfaces as 409 with the gate reason.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-respect-existing-governance-gates-without-mutating-schedule-state
     */
    public function testReplayBlockedByGovernanceGateReturnsConflict(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->method('getRunTrace')->willReturn(['id' => 'run-1', 'prompt' => 'x', 'steps' => [], 'status' => 'ok']);

        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->method('replayRun')->willReturn(['status' => 'blocked', 'gate' => 'awaiting_approval']);

        $controller = $this->controller($objectService, $this->session('alice'), $runHistory, $scheduleService);
        $response   = $controller->replay('sched-1', 'run-1');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame('awaiting_approval', $response->getData()['gate']);

    }//end testReplayBlockedByGovernanceGateReturnsConflict()

    /**
     * The in-app engine being off surfaces as a clear, actionable 422.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-require-the-in-app-agent-engine
     */
    public function testReplayRefusedWithoutEngineReturns422(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($this->schedule('alice'));

        $runHistory = $this->createMock(RunHistoryService::class);
        $runHistory->method('getRunTrace')->willReturn(['id' => 'run-1', 'prompt' => 'x', 'steps' => [], 'status' => 'ok']);

        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->method('replayRun')->willThrowException(new EngineRequiredException());

        $controller = $this->controller($objectService, $this->session('alice'), $runHistory, $scheduleService);
        $response   = $controller->replay('sched-1', 'run-1');

        $this->assertSame(422, $response->getStatus());

    }//end testReplayRefusedWithoutEngineReturns422()
}//end class
