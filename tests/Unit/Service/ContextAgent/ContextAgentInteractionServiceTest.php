<?php

/**
 * Unit tests for ContextAgentInteractionService (contextagent-provider).
 *
 * Covers the governed single-turn interaction: user-context guard, kill-switch gate
 * (engine never runs), conversation create/reuse via conversation_token, the
 * confirmation→approval-gate mapping (approve on 1, deny on 0, no-op without a match),
 * the actions↔tool-allowlist disclosure, and the happy-path output shape.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\ContextAgent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ContextAgent;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\ContextAgentInteractionService;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\TaskProcessing\Exception\ProcessingException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ContextAgentInteractionService.
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-1
 */
class ContextAgentInteractionServiceTest extends TestCase
{
    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var Engine&MockObject
     */
    private Engine&MockObject $engine;

    /**
     * @var ApprovalService&MockObject
     */
    private ApprovalService&MockObject $approvalService;

    /**
     * @var ScheduleService&MockObject
     */
    private ScheduleService&MockObject $scheduleService;

    /**
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Set up fresh mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->engine          = $this->createMock(Engine::class);
        $this->approvalService = $this->createMock(ApprovalService::class);
        $this->scheduleService = $this->createMock(ScheduleService::class);
        $this->appConfig       = $this->createMock(IAppConfig::class);

        // ObjectService fluent setters return self by default on a mock only if wired.
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
    }//end setUp()

    /**
     * Build the service under test.
     *
     * @return ContextAgentInteractionService
     */
    private function service(): ContextAgentInteractionService
    {
        $audit = $this->createMock(AuditTrailMapper::class);

        $redaction = $this->createMock(RedactionService::class);
        $redaction->method('redact')->willReturnArgument(0);

        return new ContextAgentInteractionService(
            $this->objectService,
            $this->engine,
            $this->approvalService,
            $this->scheduleService,
            $audit,
            $redaction,
            $this->appConfig,
            new NullLogger()
        );
    }//end service()

    /**
     * Build an agent ObjectEntity with the given uuid/org/tools.
     *
     * @param string $uuid  The agent uuid.
     * @param string $org   The organisation.
     * @param array  $tools The tool allowlist.
     *
     * @return ObjectEntity
     */
    private function agent(string $uuid, string $org='org-1', array $tools=[]): ObjectEntity
    {
        $agent = new ObjectEntity();
        $agent->setUuid($uuid);
        $agent->setOrganisation($org);
        $agent->setObject(['name' => 'Agent', 'active' => true, 'tools' => $tools]);
        return $agent;
    }//end agent()

    /**
     * Build a conversation ObjectEntity owned by the given user.
     *
     * @param string $uuid   The conversation uuid.
     * @param string $userId The owner.
     *
     * @return ObjectEntity
     */
    private function conversation(string $uuid, string $userId): ObjectEntity
    {
        $conversation = new ObjectEntity();
        $conversation->setUuid($uuid);
        $conversation->setObject(['userId' => $userId, 'agentId' => 'agent-1']);
        return $conversation;
    }//end conversation()

    /**
     * Point resolveAgent() at a configured agent (found via find()).
     *
     * @param ObjectEntity $agent The agent to return.
     *
     * @return void
     */
    private function withConfiguredAgent(ObjectEntity $agent): void
    {
        $this->appConfig->method('getValueString')->willReturn((string) $agent->getUuid());
        $this->objectService->method('find')->willReturnCallback(
            function (int | string $id) use ($agent): ?ObjectEntity {
                if ($id === $agent->getUuid()) {
                    return $agent;
                }
                return null;
            }
        );
    }//end withConfiguredAgent()

    /**
     * A null user context is a processing error.
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
     */
    public function testNullUserThrows(): void
    {
        $this->expectException(ProcessingException::class);
        $this->service()->interact(null, 'hi', null, '');
    }//end testNullUserThrows()

    /**
     * An engaged kill-switch halts the interaction and never runs the engine.
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
     */
    public function testKillSwitchHaltsBeforeEngine(): void
    {
        $this->withConfiguredAgent($this->agent('agent-1', 'org-engaged'));
        $this->scheduleService->method('isOrganisationEngaged')->with('org-engaged')->willReturn(true);
        $this->engine->expects($this->never())->method('processMessage');

        $this->expectException(ProcessingException::class);
        $this->service()->interact('alice', 'hi', null, '');
    }//end testKillSwitchHaltsBeforeEngine()

    /**
     * A first turn (empty token) creates a conversation and returns its uuid + the
     * agent's tool allowlist in actions.
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
     */
    public function testFirstTurnCreatesConversationAndReturnsShape(): void
    {
        $this->withConfiguredAgent($this->agent('agent-1', 'org-1', ['openregister.searchObjects']));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);

        $created = $this->conversation('conv-new', 'alice');
        $this->objectService->expects($this->once())->method('saveObject')->willReturn($created);

        $this->engine->method('processMessage')->willReturn(['message' => 'hello back', 'usage' => []]);

        $result = $this->service()->interact('alice', 'hi', null, '');

        $this->assertSame('hello back', $result['output']);
        $this->assertSame('conv-new', $result['conversation_token']);
        $actions = json_decode($result['actions'], true);
        $this->assertContains('openregister.searchObjects', $actions['toolAllowlist']);
    }//end testFirstTurnCreatesConversationAndReturnsShape()

    /**
     * A resolvable, user-owned conversation_token is reused (no new conversation).
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
     */
    public function testExistingTokenIsReused(): void
    {
        $agent    = $this->agent('agent-1');
        $existing = $this->conversation('conv-1', 'alice');

        $this->appConfig->method('getValueString')->willReturn('agent-1');
        $this->objectService->method('find')->willReturnCallback(
            function (int | string $id) use ($agent, $existing): ?ObjectEntity {
                if ($id === 'agent-1') {
                    return $agent;
                }
                if ($id === 'conv-1') {
                    return $existing;
                }
                return null;
            }
        );
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->objectService->expects($this->never())->method('saveObject');
        $this->engine->method('processMessage')->willReturn(['message' => 'reply']);

        $result = $this->service()->interact('alice', 'continue', null, 'conv-1');

        $this->assertSame('conv-1', $result['conversation_token']);
    }//end testExistingTokenIsReused()

    /**
     * confirmation=1 approves the user's matching pending Approval.
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
     */
    public function testConfirmationApprovesPendingApproval(): void
    {
        $this->withConfiguredAgent($this->agent('agent-1'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->objectService->method('saveObject')->willReturn($this->conversation('conv-x', 'alice'));
        $this->engine->method('processMessage')->willReturn(['message' => 'ok']);

        $approval = new ObjectEntity();
        $approval->setUuid('appr-1');
        $this->approvalService->method('listPendingForReviewer')->with('alice')->willReturn(
            [['id' => 'appr-1', 'agentId' => 'agent-1']]
        );
        $this->approvalService->method('loadApproval')->with('appr-1')->willReturn($approval);
        $this->approvalService->expects($this->once())->method('approve')->with($approval, 'alice');
        $this->approvalService->expects($this->never())->method('deny');

        $this->service()->interact('alice', 'go', 1, '');
    }//end testConfirmationApprovesPendingApproval()

    /**
     * confirmation=0 denies the user's matching pending Approval.
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
     */
    public function testConfirmationDeniesPendingApproval(): void
    {
        $this->withConfiguredAgent($this->agent('agent-1'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->objectService->method('saveObject')->willReturn($this->conversation('conv-x', 'alice'));
        $this->engine->method('processMessage')->willReturn(['message' => 'ok']);

        $approval = new ObjectEntity();
        $approval->setUuid('appr-1');
        $this->approvalService->method('listPendingForReviewer')->willReturn(
            [['id' => 'appr-1', 'agentId' => 'agent-1']]
        );
        $this->approvalService->method('loadApproval')->willReturn($approval);
        $this->approvalService->expects($this->once())->method('deny')->with($approval, 'alice', $this->anything());
        $this->approvalService->expects($this->never())->method('approve');

        $this->service()->interact('alice', 'no', 0, '');
    }//end testConfirmationDeniesPendingApproval()

    /**
     * confirmation with no matching pending approval is a no-op (no approve/deny).
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
     */
    public function testConfirmationNoPendingIsNoop(): void
    {
        $this->withConfiguredAgent($this->agent('agent-1'));
        $this->scheduleService->method('isOrganisationEngaged')->willReturn(false);
        $this->objectService->method('saveObject')->willReturn($this->conversation('conv-x', 'alice'));
        $this->engine->method('processMessage')->willReturn(['message' => 'ok']);
        $this->approvalService->method('listPendingForReviewer')->willReturn([]);
        $this->approvalService->expects($this->never())->method('approve');
        $this->approvalService->expects($this->never())->method('deny');

        $result = $this->service()->interact('alice', 'hi', 1, '');
        $this->assertSame('ok', $result['output']);
    }//end testConfirmationNoPendingIsNoop()

    /**
     * No available agent is a processing error.
     *
     * @return void
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
     */
    public function testNoAgentThrows(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $this->objectService->method('findAll')->willReturn([]);

        $this->expectException(ProcessingException::class);
        $this->service()->interact('alice', 'hi', null, '');
    }//end testNoAgentThrows()
}//end class
