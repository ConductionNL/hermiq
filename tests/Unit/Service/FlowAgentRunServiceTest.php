<?php

/**
 * Unit tests for FlowAgentRunService (flow-agent-listener).
 *
 * Exercises the governed dispatch contract without a live Nextcloud/OpenRegister:
 *   - GATE 1 (kill-switch) halts the run before the agent is ever invoked;
 *   - GATE 2 (human approval) ensures a pending Approval and does not run;
 *   - the happy path resolves the agent, runs via ScheduleService::runAgentAsOwner
 *     (the SAME ScheduleService/Engine path a scheduled run uses), writes the
 *     result back to the triggering object's resultField, and audits the run;
 *   - a run failure is recorded as an 'error' audit entry and never throws;
 *   - malformed payloads (missing subject identity / agent / resultField) are
 *     skipped and logged, never invoking the agent.
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
 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\FlowAgentRunService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the flow-agent-listener FlowAgentRunService.
 *
 * @spec openspec/changes/flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
 */
class FlowAgentRunServiceTest extends TestCase
{

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock AgentMapper.
     *
     * @var AgentMapper&MockObject
     */
    private AgentMapper $agentMapper;

    /**
     * Mock ScheduleService (reused kill-switch check + agent-turn dispatch).
     *
     * @var ScheduleService&MockObject
     */
    private ScheduleService $scheduleService;

    /**
     * Mock ApprovalService (reused human-approval gate).
     *
     * @var ApprovalService&MockObject
     */
    private ApprovalService $approvalService;

    /**
     * Mock BudgetService (reused budget hard-cap gate + soft-threshold warning).
     *
     * @var BudgetService&MockObject
     */
    private BudgetService $budgetService;

    /**
     * Mock AuditTrailMapper (captures explicit per-run entries).
     *
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Mock AgentVersionService (agent-versioning) — a stable, non-null pin by
     * default.
     *
     * @var AgentVersionService&MockObject
     */
    private AgentVersionService $agentVersionService;

    /**
     * Recorded createAuditTrailEntry() calls: each ['action' => ..., 'context' => ...].
     *
     * @var array<int, array<string, mixed>>
     */
    private array $auditCalls = [];

    /**
     * Service under test.
     *
     * @var FlowAgentRunService
     */
    private FlowAgentRunService $service;

    /**
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->agentMapper     = $this->createMock(AgentMapper::class);
        $this->scheduleService = $this->createMock(ScheduleService::class);
        $this->approvalService = $this->createMock(ApprovalService::class);

        // Budget gate is not exercised by the base dispatch tests: never blocked.
        $this->budgetService = $this->createMock(BudgetService::class);
        $this->budgetService->method('isBlocked')->willReturn(false);

        $this->auditCalls       = [];
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->auditTrailMapper->method('createAuditTrailEntry')->willReturnCallback(
            function (ObjectEntity $object, string $action, array $context=[]): AuditTrail {
                $this->auditCalls[] = ['action' => $action, 'context' => $context];
                $entry = new AuditTrail();
                $entry->setAction($action);
                $entry->setChanged($context);
                return $entry;
            }
        );

        $this->agentVersionService = $this->createMock(AgentVersionService::class);
        $this->agentVersionService->method('currentVersionId')->willReturn('version-1');

        $this->service = new FlowAgentRunService(
            objectService: $this->objectService,
            agentMapper: $this->agentMapper,
            logger: $this->createMock(LoggerInterface::class),
            auditTrailMapper: $this->auditTrailMapper,
            redactionService: new RedactionService($this->createMock(IConfig::class)),
            scheduleService: $this->scheduleService,
            approvalService: $this->approvalService,
            budgetService: $this->budgetService,
            agentVersionService: $this->agentVersionService,
        );

    }//end setUp()

    /**
     * Build the triggering ObjectEntity.
     *
     * @param array<string,mixed> $data         The object's data.
     * @param string               $organisation The organisation identifier.
     *
     * @return ObjectEntity
     */
    private function object(array $data=[], string $organisation=''): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('obj-1');
        $entity->setOrganisation($organisation);
        $entity->setObject($data);
        return $entity;

    }//end object()

    /**
     * Build a default AgentRunRequestedEvent payload, with overrides.
     *
     * @param array<string,mixed> $overrides Field overrides.
     *
     * @return array<string,mixed>
     */
    private function payload(array $overrides=[]): array
    {
        $defaults = [
            'subjectUuid'      => 'obj-1',
            'subjectRegister'  => '1',
            'subjectSchema'    => '10',
            'agent'            => 'agent-uuid-1',
            'skill'            => null,
            'prompt'           => 'Classify this',
            'resultField'      => 'categorySlug',
            'requiresApproval' => false,
            'mode'             => 'async',
            'flowName'         => 'classify-tender',
            'correlationId'    => 'corr-1',
        ];

        return array_merge($defaults, $overrides);

    }//end payload()

    /**
     * Build a resolvable Agent with the given owner.
     *
     * @param string $owner The agent's owner (acting user).
     *
     * @return Agent
     */
    private function agent(string $owner='dave'): Agent
    {
        $agent = new Agent();
        $agent->setUuid('agent-uuid-1');
        $agent->setOwner($owner);
        return $agent;

    }//end agent()

    /**
     * Happy path: resolves the object + agent, runs via
     * ScheduleService::runAgentAsOwner, writes the result back to resultField,
     * and audits an 'ok' entry.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-2
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-3
     */
    public function testHappyPathWritesResultAndAudits(): void
    {
        $this->objectService->method('find')->willReturn($this->object(['name' => 'Rex']));
        $this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->scheduleService->expects($this->once())
            ->method('runAgentAsOwner')
            ->with('dave', 'agent-uuid-1', 'Classify this', '')
            ->willReturn('Kennel');

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                return new ObjectEntity();
            }
        );

        $ran = $this->service->run($this->payload());

        $this->assertTrue($ran);
        $this->assertSame('Kennel', $saved[0]['categorySlug']);
        $this->assertCount(1, $this->auditCalls);
        $this->assertSame('ok', $this->auditCalls[0]['context']['status']);
        $this->assertSame('agent-run', $this->auditCalls[0]['action']);
        $this->assertSame('version-1', $this->auditCalls[0]['context']['agentVersion'], 'agent-versioning: the executing agent version must be pinned.');

    }//end testHappyPathWritesResultAndAudits()

    /**
     * A skill reference is prefixed onto the prompt as a directive (v1 — skills
     * have no runtime injection parameter yet).
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-2
     */
    public function testSkillReferenceIsPrefixedOntoPrompt(): void
    {
        $this->objectService->method('find')->willReturn($this->object());
        $this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->scheduleService->expects($this->once())
            ->method('runAgentAsOwner')
            ->with('dave', 'agent-uuid-1', '[skill: classify-tender] Classify this', '')
            ->willReturn('output');

        $this->objectService->method('saveObject')->willReturn(new ObjectEntity());

        $this->service->run($this->payload(['skill' => 'classify-tender']));

    }//end testSkillReferenceIsPrefixedOntoPrompt()

    /**
     * GATE 1 — an engaged kill-switch halts the run before the agent is ever
     * invoked, and audits a skipped_killswitch entry.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-1
     */
    public function testKillSwitchHaltsRunBeforeAgent(): void
    {
        $this->objectService->method('find')->willReturn($this->object([], 'org-x'));
        $this->scheduleService->method('isOrganisationEngaged')->with('org-x')->willReturn(true);
        $this->scheduleService->expects($this->never())->method('runAgentAsOwner');

        $ran = $this->service->run($this->payload());

        $this->assertFalse($ran);
        $this->assertCount(1, $this->auditCalls);
        $this->assertSame('skipped_killswitch', $this->auditCalls[0]['context']['status']);

    }//end testKillSwitchHaltsRunBeforeAgent()

    /**
     * GATE 2 — requiresApproval=true (and no bypass) ensures a pending Approval
     * via ApprovalService and does NOT run the agent; audits awaiting_approval.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-1
     */
    public function testApprovalGateEnsuresPendingAndSkipsRun(): void
    {
        $this->objectService->method('find')->willReturn($this->object());
        $this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->scheduleService->expects($this->never())->method('runAgentAsOwner');

        $this->approvalService->expects($this->once())
            ->method('ensurePendingApprovalForFlowRun')
            ->with($this->anything(), 'dave');

        $ran = $this->service->run($this->payload(['requiresApproval' => true]));

        $this->assertFalse($ran);
        $this->assertCount(1, $this->auditCalls);
        $this->assertSame('awaiting_approval', $this->auditCalls[0]['context']['status']);

    }//end testApprovalGateEnsuresPendingAndSkipsRun()

    /**
     * The approval bypass (an authorised approval-run) skips GATE 2 and runs the
     * agent — mirrors ScheduleService::runNow(bypassApprovalGate: true).
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-1
     */
    public function testApprovalBypassRunsAgentWithoutGating(): void
    {
        $this->objectService->method('find')->willReturn($this->object());
        $this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->scheduleService->expects($this->once())->method('runAgentAsOwner')->willReturn('output');
        $this->approvalService->expects($this->never())->method('ensurePendingApprovalForFlowRun');
        $this->objectService->method('saveObject')->willReturn(new ObjectEntity());

        $ran = $this->service->run($this->payload(['requiresApproval' => true]), true);

        $this->assertTrue($ran);

    }//end testApprovalBypassRunsAgentWithoutGating()

    /**
     * The kill-switch halts a run even for an authorised approval-bypass — mirrors
     * ScheduleService::dispatch()'s gate ordering (kill-switch is highest priority).
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-1
     */
    public function testKillSwitchOverridesApprovalBypass(): void
    {
        $this->objectService->method('find')->willReturn($this->object([], 'org-x'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(true);
        $this->scheduleService->expects($this->never())->method('runAgentAsOwner');

        $ran = $this->service->run($this->payload(['requiresApproval' => true]), true);

        $this->assertFalse($ran);

    }//end testKillSwitchOverridesApprovalBypass()

    /**
     * GATE 2 (cost-guardrails) — a budget-exhausted organisation/agent halts a
     * flow-triggered run before the agent is ever invoked, and audits a
     * skipped_budget entry — the identical hard-cap block a scheduled tick applies.
     *
     * @return void
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-3-1
     */
    public function testBudgetHardCapHaltsRunBeforeAgent(): void
    {
        $this->objectService->method('find')->willReturn($this->object([], 'org-x'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->budgetService = $this->createMock(BudgetService::class);
        $this->budgetService->method('isBlocked')->with('org-x', 'agent-uuid-1')->willReturn(true);
        $this->scheduleService->expects($this->never())->method('runAgentAsOwner');
        $this->service = new FlowAgentRunService(
            objectService: $this->objectService,
            agentMapper: $this->agentMapper,
            logger: $this->createMock(LoggerInterface::class),
            auditTrailMapper: $this->auditTrailMapper,
            redactionService: new RedactionService($this->createMock(IConfig::class)),
            scheduleService: $this->scheduleService,
            approvalService: $this->approvalService,
            budgetService: $this->budgetService,
            agentVersionService: $this->agentVersionService,
        );

        $ran = $this->service->run($this->payload());

        $this->assertFalse($ran);
        $this->assertCount(1, $this->auditCalls);
        $this->assertSame('skipped_budget', $this->auditCalls[0]['context']['status']);

    }//end testBudgetHardCapHaltsRunBeforeAgent()

    /**
     * The budget gate halts a run even for an authorised approval-bypass — mirrors
     * ScheduleService::dispatch()'s gate ordering (budget is unconditional, like the
     * kill-switch).
     *
     * @return void
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-3-1
     */
    public function testBudgetHardCapOverridesApprovalBypass(): void
    {
        $this->objectService->method('find')->willReturn($this->object([], 'org-x'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->budgetService = $this->createMock(BudgetService::class);
        $this->budgetService->method('isBlocked')->willReturn(true);
        $this->scheduleService->expects($this->never())->method('runAgentAsOwner');
        $this->approvalService->expects($this->never())->method('ensurePendingApprovalForFlowRun');
        $this->service = new FlowAgentRunService(
            objectService: $this->objectService,
            agentMapper: $this->agentMapper,
            logger: $this->createMock(LoggerInterface::class),
            auditTrailMapper: $this->auditTrailMapper,
            redactionService: new RedactionService($this->createMock(IConfig::class)),
            scheduleService: $this->scheduleService,
            approvalService: $this->approvalService,
            budgetService: $this->budgetService,
            agentVersionService: $this->agentVersionService,
        );

        $ran = $this->service->run($this->payload(['requiresApproval' => true]), true);

        $this->assertFalse($ran);
        $this->assertSame('skipped_budget', $this->auditCalls[0]['context']['status']);

    }//end testBudgetHardCapOverridesApprovalBypass()

    /**
     * The soft-threshold check runs unconditionally (never fatal) on every flow-run
     * dispatch, with the resolved organisation/agent — a check failure must not block
     * the run (fail-open, mirrors the kill-switch read contract).
     *
     * @return void
     *
     * @spec openspec/changes/cost-guardrails/tasks.md#task-3-1
     */
    public function testBudgetSoftThresholdCheckFailureNeverBlocksRun(): void
    {
        $this->objectService->method('find')->willReturn($this->object([], 'org-x'));
        $this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->scheduleService->method('runAgentAsOwner')->willReturn('output');
        $this->budgetService = $this->createMock(BudgetService::class);
        $this->budgetService->expects($this->once())
            ->method('checkAndDeliverWarnings')
            ->with('org-x', 'agent-uuid-1')
            ->willThrowException(new RuntimeException('delivery backend down'));
        $this->budgetService->method('isBlocked')->willReturn(false);
        $this->objectService->method('saveObject')->willReturn(new ObjectEntity());
        $this->service = new FlowAgentRunService(
            objectService: $this->objectService,
            agentMapper: $this->agentMapper,
            logger: $this->createMock(LoggerInterface::class),
            auditTrailMapper: $this->auditTrailMapper,
            redactionService: new RedactionService($this->createMock(IConfig::class)),
            scheduleService: $this->scheduleService,
            approvalService: $this->approvalService,
            budgetService: $this->budgetService,
            agentVersionService: $this->agentVersionService,
        );

        $ran = $this->service->run($this->payload());

        $this->assertTrue($ran, 'A soft-threshold check failure must not block the run.');

    }//end testBudgetSoftThresholdCheckFailureNeverBlocksRun()

    /**
     * A run failure (the agent turn throws) is recorded as an 'error' audit entry
     * and never propagates — run() always returns, never throws.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-4
     */
    public function testRunFailureIsAuditedAndNeverThrows(): void
    {
        $this->objectService->method('find')->willReturn($this->object());
        $this->agentMapper->method('findByUuid')->willReturn($this->agent('dave'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->scheduleService->method('runAgentAsOwner')->willThrowException(new RuntimeException('LLM down'));

        $ran = $this->service->run($this->payload());

        $this->assertFalse($ran);
        $this->assertCount(1, $this->auditCalls);
        $this->assertSame('error', $this->auditCalls[0]['context']['status']);

    }//end testRunFailureIsAuditedAndNeverThrows()

    /**
     * A payload missing subject identity is skipped without ever touching
     * ObjectService::find() for the (absent) uuid.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-1
     */
    public function testMissingSubjectIdentityIsSkipped(): void
    {
        $this->objectService->expects($this->never())->method('find');

        $ran = $this->service->run($this->payload(['subjectUuid' => '']));

        $this->assertFalse($ran);

    }//end testMissingSubjectIdentityIsSkipped()

    /**
     * When the triggering object cannot be resolved, the run is skipped (the
     * object may have been deleted since the event was dispatched).
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-1
     */
    public function testMissingTriggeringObjectIsSkipped(): void
    {
        $this->objectService->method('find')->willReturn(null);
        $this->scheduleService->expects($this->never())->method('runAgentAsOwner');

        $ran = $this->service->run($this->payload());

        $this->assertFalse($ran);

    }//end testMissingTriggeringObjectIsSkipped()

    /**
     * When the agent reference cannot be resolved, the run is skipped without
     * ever calling ScheduleService::runAgentAsOwner.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-2
     */
    public function testUnresolvableAgentIsSkipped(): void
    {
        $this->objectService->method('find')->willReturn($this->object());
        $this->agentMapper->method('findByUuid')->willThrowException(new RuntimeException('not found'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->scheduleService->expects($this->never())->method('runAgentAsOwner');

        $ran = $this->service->run($this->payload());

        $this->assertFalse($ran);

    }//end testUnresolvableAgentIsSkipped()

    /**
     * When the resolved agent has no owner, the run is skipped — there is no
     * acting user to impersonate.
     *
     * @return void
     *
     * @spec openspec/changes/flow-agent-listener/tasks.md#task-2-2
     */
    public function testAgentWithNoOwnerIsSkipped(): void
    {
        $this->objectService->method('find')->willReturn($this->object());
        $this->agentMapper->method('findByUuid')->willReturn($this->agent(''));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->scheduleService->expects($this->never())->method('runAgentAsOwner');

        $ran = $this->service->run($this->payload());

        $this->assertFalse($ran);

    }//end testAgentWithNoOwnerIsSkipped()
}//end class
