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
use OCA\Hermiq\Service\FlowAgentRunService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\Hermiq\Service\WebhookAgentRunService;
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
        $this->deliveryService->method('deliverApprovalRequestForToolInvocation')->willReturn(
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
     * Build an agent ObjectEntity (owner-resolution lookup for tool-invocation
     * approvals — agent-tool-governance-and-disclosure).
     *
     * @param string $owner The owner UID.
     *
     * @return ObjectEntity
     */
    private function agentEntity(string $owner=''): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('agent-1');
        $entity->setOwner($owner);
        $entity->setObject(['name' => 'Test agent']);
        return $entity;

    }//end agentEntity()

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
     * ensurePendingApprovalForFlowRun creates exactly one pending Approval tagged
     * sourceType=flow, carrying the flowContext resume payload and the agent owner
     * as reviewer, and notifies via the flow-run delivery path.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-3-1
     */
    public function testEnsurePendingApprovalForFlowRunCreatesAndNotifies(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                $entity  = new ObjectEntity();
                $entity->setUuid('appr-flow-new');
                $entity->setObject($object);
                return $entity;
            }
        );
        $this->deliveryService->expects($this->once())->method('deliverApprovalRequestForFlowRun');

        $context = [
            'subjectUuid'      => 'obj-1',
            'subjectRegister'  => '1',
            'subjectSchema'    => '10',
            'agent'            => 'agent-uuid-1',
            'skill'            => null,
            'prompt'           => 'Classify this',
            'resultField'      => 'categorySlug',
            'requiresApproval' => true,
            'mode'             => 'async',
            'flowName'         => 'classify-tender',
            'correlationId'    => 'corr-1',
        ];

        $approval = $this->service()->ensurePendingApprovalForFlowRun($context, 'dave');

        $this->assertCount(1, $saved, 'Exactly one pending Approval must be created.');
        $this->assertSame('pending', $saved[0]['status']);
        $this->assertSame('flow', $saved[0]['sourceType']);
        $this->assertSame('corr-1', $saved[0]['correlationId']);
        $this->assertSame($context, $saved[0]['flowContext']);
        $this->assertSame('agent-uuid-1', $saved[0]['agentId']);
        $this->assertSame('dave', $saved[0]['reviewer'], 'The agent owner defaults as reviewer.');
        $this->assertSame('user', $saved[0]['reviewerType']);
        $this->assertSame('appr-flow-new', $approval->getUuid());

    }//end testEnsurePendingApprovalForFlowRunCreatesAndNotifies()

    /**
     * An existing pending Approval for the same correlationId makes
     * ensurePendingApprovalForFlowRun a no-op: it returns the existing approval
     * without creating a second one or re-notifying.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-3-1
     */
    public function testEnsurePendingApprovalForFlowRunIsIdempotent(): void
    {
        $existing = $this->approval(['status' => 'pending', 'sourceType' => 'flow', 'correlationId' => 'corr-1']);
        $this->objectService->method('findAll')->willReturn([$existing]);

        $this->objectService->expects($this->never())->method('saveObject');
        $this->deliveryService->expects($this->never())->method('deliverApprovalRequestForFlowRun');

        $result = $this->service()->ensurePendingApprovalForFlowRun(['correlationId' => 'corr-1'], 'dave');

        $this->assertSame($existing, $result);

    }//end testEnsurePendingApprovalForFlowRunIsIdempotent()

    /**
     * With no agent owner resolvable, the reviewer defaults to the `admin` group
     * rather than an empty/unroutable reviewer.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-3-1
     */
    public function testEnsurePendingApprovalForFlowRunDefaultsToAdminGroupWithNoAgentOwner(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $this->service()->ensurePendingApprovalForFlowRun(['correlationId' => 'corr-2'], '');

        $this->assertSame('admin', $saved[0]['reviewer']);
        $this->assertSame('group', $saved[0]['reviewerType']);

    }//end testEnsurePendingApprovalForFlowRunDefaultsToAdminGroupWithNoAgentOwner()

    /**
     * ensurePendingApprovalForWebhookRun creates exactly one pending Approval
     * (idempotent by correlationId) with sourceType=webhook and the stored
     * webhookContext, routed to the webhook's OWN configured reviewer (not just
     * the agent owner — the AgentWebhook schema carries its own reviewer/
     * reviewerType, unlike a flow trigger), and notifies via the webhook-run
     * delivery path.
     *
     * @return void
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-5-approvalservice-sourcetype-webhook-generalisation
     */
    public function testEnsurePendingApprovalForWebhookRunCreatesAndNotifies(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                $entity  = new ObjectEntity();
                $entity->setUuid('appr-webhook-new');
                $entity->setObject($object);
                return $entity;
            }
        );
        $this->deliveryService->expects($this->once())->method('deliverApprovalRequestForWebhookRun');

        $context = [
            'agentId'          => 'agent-uuid-1',
            'payload'          => ['event' => 'ping'],
            'correlationId'    => 'corr-webhook-1',
            'requiresApproval' => true,
            'reviewer'         => 'carol',
            'reviewerType'     => 'user',
        ];

        $approval = $this->service()->ensurePendingApprovalForWebhookRun($context, 'dave');

        $this->assertCount(1, $saved, 'Exactly one pending Approval must be created.');
        $this->assertSame('pending', $saved[0]['status']);
        $this->assertSame('webhook', $saved[0]['sourceType']);
        $this->assertSame('corr-webhook-1', $saved[0]['correlationId']);
        $this->assertSame($context, $saved[0]['webhookContext']);
        $this->assertSame('agent-uuid-1', $saved[0]['agentId']);
        $this->assertSame('carol', $saved[0]['reviewer'], 'The webhook\'s OWN configured reviewer is used, not the agent owner.');
        $this->assertSame('user', $saved[0]['reviewerType']);
        $this->assertSame('appr-webhook-new', $approval->getUuid());

    }//end testEnsurePendingApprovalForWebhookRunCreatesAndNotifies()

    /**
     * An existing pending Approval for the same correlationId makes
     * ensurePendingApprovalForWebhookRun a no-op.
     *
     * @return void
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-5-approvalservice-sourcetype-webhook-generalisation
     */
    public function testEnsurePendingApprovalForWebhookRunIsIdempotent(): void
    {
        $existing = $this->approval(['status' => 'pending', 'sourceType' => 'webhook', 'correlationId' => 'corr-webhook-1']);
        $this->objectService->method('findAll')->willReturn([$existing]);

        $this->objectService->expects($this->never())->method('saveObject');
        $this->deliveryService->expects($this->never())->method('deliverApprovalRequestForWebhookRun');

        $result = $this->service()->ensurePendingApprovalForWebhookRun(['correlationId' => 'corr-webhook-1'], 'dave');

        $this->assertSame($existing, $result);

    }//end testEnsurePendingApprovalForWebhookRunIsIdempotent()

    /**
     * With no configured reviewer, the reviewer defaults to the agent owner as
     * a `user` reviewer — mirrors resolveReviewer()'s identical Schedule fallback.
     *
     * @return void
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-5-approvalservice-sourcetype-webhook-generalisation
     */
    public function testEnsurePendingApprovalForWebhookRunDefaultsToAgentOwnerWithNoConfiguredReviewer(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $this->service()->ensurePendingApprovalForWebhookRun(['correlationId' => 'corr-webhook-2', 'reviewer' => ''], 'dave');

        $this->assertSame('dave', $saved[0]['reviewer']);
        $this->assertSame('user', $saved[0]['reviewerType']);

    }//end testEnsurePendingApprovalForWebhookRunDefaultsToAgentOwnerWithNoConfiguredReviewer()

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
     * approve on a sourceType=flow Approval resumes via FlowAgentRunService::run()
     * with the approval gate bypassed — the flow-run counterpart to
     * testApproveTransitionsAndRunsBypassed. It does NOT touch ScheduleService.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-3-3
     */
    public function testApproveFlowSourceTypeRunsFlowAgentRunServiceBypassed(): void
    {
        $flowContext = ['subjectUuid' => 'obj-1', 'subjectRegister' => '1', 'subjectSchema' => '10', 'agent' => 'agent-uuid-1'];
        $approval    = $this->approval(
                [
                    'status'        => 'pending',
                    'sourceType'    => 'flow',
                    'correlationId' => 'corr-1',
                    'flowContext'   => $flowContext,
                    'reviewer'      => 'bob',
                    'reviewerType'  => 'user',
                ]
                );

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $flowAgentRunService = $this->createMock(FlowAgentRunService::class);
        $flowAgentRunService->expects($this->once())
            ->method('run')
            ->with($flowContext, true)
            ->willReturn(true);
        $this->container->method('get')->willReturn($flowAgentRunService);

        $result = $this->service()->approve($approval, 'bob');

        $this->assertSame('approved', $result['status']);
        $this->assertTrue($result['ran'], 'Approving a flow-run must run the gated agent turn.');
        $this->assertSame('approved', $saved[0]['status']);

    }//end testApproveFlowSourceTypeRunsFlowAgentRunServiceBypassed()

    /**
     * approve on a sourceType=webhook Approval resumes via
     * WebhookAgentRunService::run() with the approval gate bypassed — the
     * webhook-run counterpart to testApproveFlowSourceTypeRunsFlowAgentRunServiceBypassed.
     * It does NOT touch ScheduleService::runNow() or FlowAgentRunService::run().
     *
     * @return void
     *
     * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-5-approvalservice-sourcetype-webhook-generalisation
     */
    public function testApproveWebhookSourceTypeRunsWebhookAgentRunServiceBypassed(): void
    {
        $webhookContext = ['agentId' => 'agent-uuid-1', 'payload' => ['event' => 'ping'], 'correlationId' => 'corr-webhook-1'];
        $approval       = $this->approval(
            [
                'status'         => 'pending',
                'sourceType'     => 'webhook',
                'correlationId'  => 'corr-webhook-1',
                'webhookContext' => $webhookContext,
                'reviewer'       => 'carol',
                'reviewerType'   => 'user',
            ]
        );

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $webhookAgentRunService = $this->createMock(WebhookAgentRunService::class);
        $webhookAgentRunService->expects($this->once())
            ->method('run')
            ->with($webhookContext, true)
            ->willReturn(true);
        $this->container->method('get')->willReturn($webhookAgentRunService);

        $result = $this->service()->approve($approval, 'carol');

        $this->assertSame('approved', $result['status']);
        $this->assertTrue($result['ran'], 'Approving a webhook-run must run the gated agent turn.');
        $this->assertSame('approved', $saved[0]['status']);

    }//end testApproveWebhookSourceTypeRunsWebhookAgentRunServiceBypassed()

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
        $mine = $this->approval(['status' => 'pending', 'scheduleId' => 's1', 'reviewer' => 'bob', 'reviewerType' => 'user']);
        $mine->setUuid('mine');
        $other = $this->approval(['status' => 'pending', 'scheduleId' => 's2', 'reviewer' => 'carol', 'reviewerType' => 'user']);
        $other->setUuid('other');

        $this->objectService->method('findAll')->willReturn([$mine, $other]);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $records = $this->service()->listPendingForReviewer('bob');

        $this->assertCount(1, $records, 'Only approvals routed to the caller are listed.');
        $this->assertSame('mine', $records[0]['id']);

    }//end testListPendingForReviewer()

    /**
     * listForOrganisation() returns every Approval the tenant-scoped read yields
     * (compliance-control-packs) — a thin, purely-additive pass-through over
     * ObjectService's own RBAC/tenancy scoping.
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-3-complianceservice-computed-evidence-mapping
     */
    public function testListForOrganisationReturnsTenantScopedApprovals(): void
    {
        $a = $this->approval(['status' => 'approved']);
        $a->setUuid('a1');
        $b = $this->approval(['status' => 'pending']);
        $b->setUuid('a2');

        $this->objectService->method('findAll')->willReturn([$a, $b]);

        $records = $this->service()->listForOrganisation();

        $this->assertCount(2, $records);

    }//end testListForOrganisationReturnsTenantScopedApprovals()

    /**
     * listForAgent() returns only Approval objects matching the given agentId,
     * system-wide (RBAC-off) — the per-agent factsheet decision history
     * (compliance-control-packs).
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-4-complianceservice-dashboard-export-and-factsheet-aggregation
     */
    public function testListForAgentFiltersByAgentId(): void
    {
        $mine = $this->approval(['status' => 'approved', 'agentId' => 'agent-1']);
        $mine->setUuid('a1');
        $other = $this->approval(['status' => 'approved', 'agentId' => 'agent-2']);
        $other->setUuid('a2');

        $this->objectService->method('findAll')->willReturn([$mine, $other]);

        $records = $this->service()->listForAgent('agent-1');

        $this->assertCount(1, $records);
        $this->assertSame('a1', $records[0]->getUuid());

        $this->assertSame([], $this->service()->listForAgent(''), 'An empty agentId short-circuits to no results.');

    }//end testListForAgentFiltersByAgentId()

    /**
     * ensurePendingApprovalForToolInvocation creates exactly one pending
     * Approval tagged sourceType=tool, carrying the toolId and the agent's
     * owner as reviewer, and notifies via the tool-invocation delivery path
     * (agent-tool-governance-and-disclosure).
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
     */
    public function testEnsurePendingApprovalForToolInvocationCreatesAndNotifies(): void
    {
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->method('find')->willReturn($this->agentEntity(owner: 'dave'));

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                $entity  = new ObjectEntity();
                $entity->setUuid('appr-tool-new');
                $entity->setObject($object);
                return $entity;
            }
        );
        $this->deliveryService->expects($this->once())->method('deliverApprovalRequestForToolInvocation');

        $approval = $this->service()->ensurePendingApprovalForToolInvocation('agent-1', 'pipelinq.lead.delete', ['id' => '7']);

        $this->assertCount(1, $saved, 'Exactly one pending Approval must be created.');
        $this->assertSame('pending', $saved[0]['status']);
        $this->assertSame('tool', $saved[0]['sourceType']);
        $this->assertSame('agent-1', $saved[0]['agentId']);
        $this->assertSame('pipelinq.lead.delete', $saved[0]['toolId']);
        $this->assertSame('dave', $saved[0]['reviewer'], 'The agent owner defaults as reviewer.');
        $this->assertSame('user', $saved[0]['reviewerType']);
        $this->assertSame('appr-tool-new', $approval->getUuid());

    }//end testEnsurePendingApprovalForToolInvocationCreatesAndNotifies()

    /**
     * An existing pending Approval for the same (agentId, toolId) pair makes
     * ensurePendingApprovalForToolInvocation a no-op: it returns the existing
     * approval without creating a second one or re-notifying.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
     */
    public function testEnsurePendingApprovalForToolInvocationIsIdempotent(): void
    {
        $existing = $this->approval(
            ['status' => 'pending', 'sourceType' => 'tool', 'agentId' => 'agent-1', 'toolId' => 'pipelinq.lead.delete']
        );
        $this->objectService->method('findAll')->willReturn([$existing]);

        $this->objectService->expects($this->never())->method('saveObject');
        $this->deliveryService->expects($this->never())->method('deliverApprovalRequestForToolInvocation');

        $result = $this->service()->ensurePendingApprovalForToolInvocation('agent-1', 'pipelinq.lead.delete', []);

        $this->assertSame($existing, $result);

    }//end testEnsurePendingApprovalForToolInvocationIsIdempotent()

    /**
     * With no resolvable agent owner, the reviewer defaults to the `admin`
     * group rather than an empty/unroutable reviewer.
     *
     * @return void
     */
    public function testEnsurePendingApprovalForToolInvocationDefaultsToAdminGroupWithNoAgentOwner(): void
    {
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->method('find')->willReturn(null);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $this->service()->ensurePendingApprovalForToolInvocation('agent-1', 'pipelinq.lead.delete', []);

        $this->assertSame('admin', $saved[0]['reviewer']);
        $this->assertSame('group', $saved[0]['reviewerType']);

    }//end testEnsurePendingApprovalForToolInvocationDefaultsToAdminGroupWithNoAgentOwner()

    /**
     * findDecidedApprovalForToolInvocation returns the most recently decided
     * (approved/denied) Approval for the pair, ignoring pending ones.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-explicitly-granted-destructive-tool-call-is-not-re-gated
     */
    public function testFindDecidedApprovalForToolInvocationReturnsMostRecent(): void
    {
        $pending = $this->approval(
            ['status' => 'pending', 'sourceType' => 'tool', 'agentId' => 'agent-1', 'toolId' => 'pipelinq.lead.delete']
        );
        $older   = $this->approval(
            [
                'status'     => 'denied',
                'sourceType' => 'tool',
                'agentId'    => 'agent-1',
                'toolId'     => 'pipelinq.lead.delete',
                'decidedAt'  => '2026-01-01T00:00:00+00:00',
            ]
        );
        $older->setUuid('older');
        $newer = $this->approval(
            [
                'status'     => 'approved',
                'sourceType' => 'tool',
                'agentId'    => 'agent-1',
                'toolId'     => 'pipelinq.lead.delete',
                'decidedAt'  => '2026-06-01T00:00:00+00:00',
            ]
        );
        $newer->setUuid('newer');

        $this->objectService->method('findAll')->willReturn([$pending, $older, $newer]);

        $result = $this->service()->findDecidedApprovalForToolInvocation('agent-1', 'pipelinq.lead.delete');

        $this->assertNotNull($result);
        $this->assertSame('newer', $result->getUuid());

    }//end testFindDecidedApprovalForToolInvocationReturnsMostRecent()

    /**
     * With no decided approval on record, findDecidedApprovalForToolInvocation
     * returns null (never fabricates a decision).
     *
     * @return void
     */
    public function testFindDecidedApprovalForToolInvocationReturnsNullWhenNoneDecided(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $this->assertNull($this->service()->findDecidedApprovalForToolInvocation('agent-1', 'pipelinq.lead.delete'));

    }//end testFindDecidedApprovalForToolInvocationReturnsNullWhenNoneDecided()

    /**
     * approve() on a sourceType=tool Approval flips status to approved but
     * resumes NOTHING (no run to resume mid-conversation) — never touches
     * ScheduleService/FlowAgentRunService via the container.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-explicitly-granted-destructive-tool-call-is-not-re-gated
     */
    public function testApproveToolSourceTypeResumesNothing(): void
    {
        $approval = $this->approval(
            [
                'status'       => 'pending',
                'sourceType'   => 'tool',
                'agentId'      => 'agent-1',
                'toolId'       => 'pipelinq.lead.delete',
                'reviewer'     => 'bob',
                'reviewerType' => 'user',
            ]
        );

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $this->container->expects($this->never())->method('get');

        $result = $this->service()->approve($approval, 'bob');

        $this->assertSame('approved', $result['status']);
        $this->assertFalse($result['ran'], 'A tool-invocation approval has nothing to resume mid-conversation.');
        $this->assertSame('approved', $saved[0]['status']);

    }//end testApproveToolSourceTypeResumesNothing()

    /**
     * The complete skill-draft decision-evidence payload the ensure call requires.
     *
     * @return array<string, mixed>
     */
    private function draftPayload(): array
    {
        return [
            'deepLink'         => 'https://cloud.example.test/index.php/apps/hermiq/skills/skill-1',
            'scanVerdict'      => 'clean',
            'noEvalEvidence'   => true,
            'learningsSummary' => 'Consolidates 3 promoted learnings entries into the skill body.',
        ];

    }//end draftPayload()

    /**
     * A draft + skill entity pair for the skill-draft ensure call.
     *
     * @return array{0: ObjectEntity, 1: ObjectEntity}
     */
    private function draftAndSkill(): array
    {
        $draft = new ObjectEntity();
        $draft->setUuid('draft-1');
        $draft->setOwner('alice');
        $draft->setObject(
            [
                'skillId' => 'skill-1',
                'status'  => 'awaiting-approval',
            ]
        );

        $skill = new ObjectEntity();
        $skill->setUuid('skill-1');
        $skill->setOwner('alice');
        $skill->setObject(['name' => 'tender-summary']);

        return [
            $draft,
            $skill,
        ];

    }//end draftAndSkill()

    /**
     * An Approval whose decision-evidence payload misses ANY required element is
     * rejected as invalid at creation — nothing is persisted, nothing is notified,
     * so a payload-incomplete Approval can never reach an inbox.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testSkillDraftApprovalWithoutFullPayloadIsInvalid(): void
    {
        [$draft, $skill] = $this->draftAndSkill();

        $this->objectService->expects($this->never())->method('saveObject');
        $this->deliveryService->expects($this->never())->method('deliverApprovalRequestForSkillDraft');

        $variants = [
            'deepLink',
            'scanVerdict',
            'noEvalEvidence',
            'learningsSummary',
        ];
        foreach ($variants as $missing) {
            $payload = $this->draftPayload();
            unset($payload[$missing]);

            $thrown = null;
            try {
                $this->service()->ensurePendingApprovalForSkillDraft(draft: $draft, skill: $skill, draftPayload: $payload);
            } catch (\InvalidArgumentException $exception) {
                $thrown = $exception;
            }

            $this->assertNotNull($thrown, 'Missing "'.$missing.'" must invalidate the Approval at creation.');
        }

    }//end testSkillDraftApprovalWithoutFullPayloadIsInvalid()

    /**
     * A COMPLETE payload creates the pending Approval (sourceType skill-draft,
     * payload aboard, reviewer = skill owner) and fires the pending-approval ping.
     * An eval-measured payload (evalDelta, no noEvalEvidence flag) is equally valid.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testSkillDraftApprovalWithCompletePayloadIsCreatedAndNotified(): void
    {
        [$draft, $skill] = $this->draftAndSkill();

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
        $this->deliveryService->expects($this->once())
            ->method('deliverApprovalRequestForSkillDraft')
            ->willReturn(new DeliveryResult(delivered: true, channel: 'notification', fellBack: false, warning: null));

        $payload = [
            'deepLink'         => 'https://cloud.example.test/index.php/apps/hermiq/skills/skill-1',
            'scanVerdict'      => 'clean',
            'evalDelta'        => 0.05,
            'noEvalEvidence'   => false,
            'learningsSummary' => 'Consolidates 3 promoted learnings entries.',
        ];

        $approval = $this->service()->ensurePendingApprovalForSkillDraft(draft: $draft, skill: $skill, draftPayload: $payload);

        $this->assertSame('appr-new', (string) $approval->getUuid());
        $this->assertSame('skill-draft', $saved[0]['sourceType']);
        $this->assertSame('draft-1', $saved[0]['draftId']);
        $this->assertSame('alice', $saved[0]['reviewer'], 'The reviewer defaults to the skill owner.');
        $this->assertSame($payload, $saved[0]['draftPayload'], 'The decision evidence rides the Approval verbatim.');

    }//end testSkillDraftApprovalWithCompletePayloadIsCreatedAndNotified()

    /**
     * Approving a skill-draft Approval from ANY surface (this is the generic
     * decision path the inbox uses) fires the apply step: the draft's content is
     * applied via the consolidation service with the decider recorded.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testApproveSkillDraftSourceTypeAppliesTheDraft(): void
    {
        $approval = $this->approval(
            [
                'status'       => 'pending',
                'sourceType'   => 'skill-draft',
                'draftId'      => 'draft-1',
                'skillId'      => 'skill-1',
                'reviewer'     => 'bob',
                'reviewerType' => 'user',
            ]
        );

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $consolidation = $this->createMock(\OCA\Hermiq\Service\SkillConsolidationService::class);
        $consolidation->method('isDraftApprovable')->with('draft-1')->willReturn(true);
        $consolidation->expects($this->once())
            ->method('applyDraft')
            ->with('draft-1', 'bob')
            ->willReturn('v-new');
        $this->container->method('get')->willReturn($consolidation);

        $result = $this->service()->approve($approval, 'bob');

        $this->assertSame('approved', $result['status']);
        $this->assertTrue($result['ran'], 'The approval transition IS the applier.');
        $this->assertSame('approved', $saved[0]['status']);

    }//end testApproveSkillDraftSourceTypeAppliesTheDraft()

    /**
     * An edited-but-not-requalified draft REFUSES the approve transition from every
     * surface: the Approval stays pending, nothing is persisted, nothing applies.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testApproveSkillDraftIsRefusedWhileAwaitingRequalification(): void
    {
        $approval = $this->approval(
            [
                'status'       => 'pending',
                'sourceType'   => 'skill-draft',
                'draftId'      => 'draft-1',
                'reviewer'     => 'bob',
                'reviewerType' => 'user',
            ]
        );

        $this->objectService->expects($this->never())->method('saveObject');

        $consolidation = $this->createMock(\OCA\Hermiq\Service\SkillConsolidationService::class);
        $consolidation->method('isDraftApprovable')->willReturn(false);
        $consolidation->expects($this->never())->method('applyDraft');
        $this->container->method('get')->willReturn($consolidation);

        $result = $this->service()->approve($approval, 'bob');

        $this->assertSame('pending', $result['status'], 'The transition is refused, not consumed.');
        $this->assertFalse($result['ran']);

    }//end testApproveSkillDraftIsRefusedWhileAwaitingRequalification()

    /**
     * Denying a skill-draft Approval (any surface) reconciles the draft to
     * `rejected` through the consolidation service.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
     */
    public function testDenySkillDraftSourceTypeReconcilesTheDraft(): void
    {
        $approval = $this->approval(
            [
                'status'       => 'pending',
                'sourceType'   => 'skill-draft',
                'draftId'      => 'draft-1',
                'reviewer'     => 'bob',
                'reviewerType' => 'user',
            ]
        );

        $this->objectService->method('saveObject')->willReturn(new ObjectEntity());

        $consolidation = $this->createMock(\OCA\Hermiq\Service\SkillConsolidationService::class);
        $consolidation->expects($this->once())
            ->method('rejectDraftByDecision')
            ->with('draft-1', 'bob', 'not convincing');
        $this->container->method('get')->willReturn($consolidation);

        $this->service()->deny($approval, 'bob', 'not convincing');

    }//end testDenySkillDraftSourceTypeReconcilesTheDraft()
}//end class
