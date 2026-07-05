<?php

/**
 * Unit tests for ApprovalService (human-approval-gate-enforcement).
 *
 * Exercises the gate write-path without a live Nextcloud/OpenRegister:
 *   - ensurePendingApproval is idempotent (one pending Approval per schedule, never
 *     one per tick) and notifies the resolved reviewer;
 *   - reviewer resolution (empty → owner; user; group) is copied onto the Approval;
 *   - isReviewer admits the reviewer user / group member / instance admin, and refuses
 *     the owner unless owner == reviewer (separation of duties);
 *   - approve transitions to approved and runs the schedule via ScheduleService::runNow
 *     with the approval gate bypassed; deny transitions to denied and never runs.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the human-approval-gate-enforcement ApprovalService.
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#1-approvalservice-create-pending-apply-decision
 */
class ApprovalServiceTest extends TestCase
{

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    /**
     * Mock DeliveryService.
     *
     * @var DeliveryService&MockObject
     */
    private DeliveryService $deliveryService;

    /**
     * Mock ContainerInterface (lazy ScheduleService).
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $this->groupManager    = $this->createMock(IGroupManager::class);
        $this->deliveryService = $this->createMock(DeliveryService::class);
        $this->deliveryService->method('deliverApprovalRequest')->willReturn(
            new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null)
        );
        $this->container = $this->createMock(ContainerInterface::class);

    }//end setUp()

    /**
     * Build an ApprovalService with the current mocks.
     *
     * @return ApprovalService
     */
    private function service(): ApprovalService
    {
        $userSession = $this->createMock(IUserSession::class);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($this->createMock(IUser::class));

        return new ApprovalService(
            $this->objectService,
            $userSession,
            $userManager,
            $this->groupManager,
            $this->deliveryService,
            $this->createMock(AuditTrailMapper::class),
            new RedactionService($this->createMock(IConfig::class)),
            $this->container,
            $this->createMock(LoggerInterface::class),
        );

    }//end service()

    /**
     * Build a schedule ObjectEntity.
     *
     * @param array<string,mixed> $payload The schedule payload.
     * @param string              $owner   The owner UID.
     *
     * @return ObjectEntity
     */
    private function schedule(array $payload, string $owner='alice'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('sched-1');
        $entity->setOwner($owner);
        $entity->setObject($payload);
        return $entity;

    }//end schedule()

    /**
     * Build an approval ObjectEntity.
     *
     * @param array<string,mixed> $payload The approval payload.
     * @param string              $owner   The owner UID.
     *
     * @return ObjectEntity
     */
    private function approval(array $payload, string $owner='alice'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('appr-1');
        $entity->setOwner($owner);
        $entity->setObject($payload);
        return $entity;

    }//end approval()

    /**
     * An existing pending Approval for the schedule makes ensurePendingApproval a
     * no-op: it does NOT create a second Approval and does NOT re-notify.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-4
     */
    public function testEnsurePendingApprovalIsIdempotent(): void
    {
        $existing = $this->approval(['status' => 'pending', 'scheduleId' => 'sched-1']);
        $this->objectService->method('findAll')->willReturn([$existing]);

        $this->objectService->expects($this->never())->method('saveObject');
        $this->deliveryService->expects($this->never())->method('deliverApprovalRequest');

        $this->service()->ensurePendingApproval(
            $this->schedule(['requiresApproval' => true, 'agentId' => 'agent-1', 'reviewer' => 'bob', 'reviewerType' => 'user'])
        );

    }//end testEnsurePendingApprovalIsIdempotent()

    /**
     * With no open Approval, ensurePendingApproval creates exactly one pending
     * Approval carrying the resolved reviewer, and notifies the reviewer.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-1
     */
    public function testEnsurePendingApprovalCreatesAndNotifies(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                $entity  = new ObjectEntity();
                $entity->setUuid('appr-new');
                $entity->setObject($object);
                return $entity;
            }
        );
        $this->deliveryService->expects($this->once())->method('deliverApprovalRequest');

        $this->service()->ensurePendingApproval(
            $this->schedule(
                ['requiresApproval' => true, 'agentId' => 'agent-1', 'prompt' => 'p', 'reviewer' => 'bob', 'reviewerType' => 'user']
            )
        );

        $this->assertCount(1, $saved, 'Exactly one pending Approval must be created.');
        $this->assertSame('pending', $saved[0]['status']);
        $this->assertSame('sched-1', $saved[0]['scheduleId']);
        $this->assertSame('bob', $saved[0]['reviewer'], 'The resolved reviewer must be copied onto the Approval.');
        $this->assertSame('user', $saved[0]['reviewerType']);

    }//end testEnsurePendingApprovalCreatesAndNotifies()

    /**
     * An empty reviewer defaults to the schedule owner (backward compatible).
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-2
     */
    public function testEmptyReviewerDefaultsToOwner(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $this->service()->ensurePendingApproval(
            $this->schedule(['requiresApproval' => true, 'agentId' => 'agent-1'], 'carol')
        );

        $this->assertSame('carol', $saved[0]['reviewer'], 'An empty reviewer must default to the owner.');
        $this->assertSame('user', $saved[0]['reviewerType']);

    }//end testEmptyReviewerDefaultsToOwner()

    /**
     * isReviewer: the reviewer user is admitted; a different non-admin user (incl. the
     * owner when owner != reviewer) is refused; an instance admin is always admitted.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-2
     */
    public function testIsReviewerUserAndAdmin(): void
    {
        $this->groupManager->method('isAdmin')->willReturnCallback(
            static fn (string $uid): bool => ($uid === 'root')
        );

        $approval = $this->approval(['status' => 'pending', 'reviewer' => 'bob', 'reviewerType' => 'user'], 'alice');
        $service  = $this->service();

        $this->assertTrue($service->isReviewer($approval, 'bob'), 'The reviewer user may decide.');
        $this->assertFalse($service->isReviewer($approval, 'alice'), 'The owner (≠ reviewer) may NOT decide.');
        $this->assertFalse($service->isReviewer($approval, 'mallory'), 'A stranger may not decide.');
        $this->assertTrue($service->isReviewer($approval, 'root'), 'An instance admin may always decide.');

    }//end testIsReviewerUserAndAdmin()

    /**
     * isReviewer with a group reviewer admits any member of the reviewer group.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-2
     */
    public function testIsReviewerGroupMember(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturnCallback(
            static fn (string $uid, string $gid): bool => ($uid === 'bob' && $gid === 'reviewers')
        );

        $approval = $this->approval(['status' => 'pending', 'reviewer' => 'reviewers', 'reviewerType' => 'group']);
        $service  = $this->service();

        $this->assertTrue($service->isReviewer($approval, 'bob'), 'A reviewer-group member may decide.');
        $this->assertFalse($service->isReviewer($approval, 'eve'), 'A non-member may not decide.');

    }//end testIsReviewerGroupMember()

    /**
     * approve transitions the Approval to approved and runs the schedule via
     * ScheduleService::runNow with the approval gate bypassed.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
     */
    public function testApproveTransitionsAndRunsBypassed(): void
    {
        $approval = $this->approval(['status' => 'pending', 'scheduleId' => 'sched-1', 'reviewer' => 'bob', 'reviewerType' => 'user']);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );
        $this->objectService->method('find')->willReturn($this->schedule(['agentId' => 'agent-1']));

        $scheduleService = $this->createMock(ScheduleService::class);
        $scheduleService->expects($this->once())
            ->method('runNow')
            ->with($this->isInstanceOf(ObjectEntity::class), true);
        $this->container->method('get')->willReturn($scheduleService);

        $result = $this->service()->approve($approval, 'bob');

        $this->assertSame('approved', $result['status']);
        $this->assertTrue($result['ran'], 'Approving must run the gated schedule.');
        $this->assertSame('approved', $saved[0]['status']);
        $this->assertSame('bob', $saved[0]['decidedBy']);

    }//end testApproveTransitionsAndRunsBypassed()

    /**
     * approve on a non-pending Approval is a no-op: no run.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
     */
    public function testApproveNonPendingIsNoop(): void
    {
        $approval = $this->approval(['status' => 'approved', 'scheduleId' => 'sched-1']);

        $this->container->expects($this->never())->method('get');

        $result = $this->service()->approve($approval, 'bob');

        $this->assertFalse($result['ran'], 'An already-decided approval must not run again.');

    }//end testApproveNonPendingIsNoop()

    /**
     * deny transitions the Approval to denied with a reason and never runs.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-3
     */
    public function testDenyTransitionsAndDoesNotRun(): void
    {
        $approval = $this->approval(['status' => 'pending', 'scheduleId' => 'sched-1', 'reviewer' => 'bob', 'reviewerType' => 'user']);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        // A run would resolve ScheduleService from the container — assert it never does.
        $this->container->expects($this->never())->method('get');

        $this->service()->deny($approval, 'bob', 'not safe right now');

        $this->assertSame('denied', $saved[0]['status']);
        $this->assertSame('bob', $saved[0]['decidedBy']);
        $this->assertSame('not safe right now', $saved[0]['reason']);

    }//end testDenyTransitionsAndDoesNotRun()

    /**
     * listPendingForReviewer returns only pending approvals routed to the user.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
     */
    public function testListPendingForReviewer(): void
    {
        $mine  = $this->approval(['status' => 'pending', 'scheduleId' => 's1', 'reviewer' => 'bob', 'reviewerType' => 'user']);
        $mine->setUuid('mine');
        $other = $this->approval(['status' => 'pending', 'scheduleId' => 's2', 'reviewer' => 'carol', 'reviewerType' => 'user']);
        $other->setUuid('other');

        $this->objectService->method('findAll')->willReturn([$mine, $other]);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $records = $this->service()->listPendingForReviewer('bob');

        $this->assertCount(1, $records, 'Only approvals routed to the caller are listed.');
        $this->assertSame('mine', $records[0]['id']);

    }//end testListPendingForReviewer()
}//end class
