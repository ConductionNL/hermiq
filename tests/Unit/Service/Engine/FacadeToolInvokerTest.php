<?php

/**
 * Unit tests for FacadeToolInvoker's run-trace-observability instrumentation.
 *
 * ToolLoopTest already covers the invoker's facade dispatch and
 * tool_call/tool_result channel frames; this file covers ONLY the new
 * RunTraceCollector threading: a successful call records a `tool` step with
 * outcome `ok`, an `isError` result records `error`, the collector and
 * channel work together without interfering, and omitting the collector
 * entirely (existing callers) is a no-op — never a fatal error.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/run-trace-observability/tasks.md#task-2-thread-the-collector-through-enginetoolloopfacadetoolinvoker
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ToolClassificationService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the invoker's RunTraceCollector integration.
 *
 * @spec openspec/changes/run-trace-observability/tasks.md#task-2-thread-the-collector-through-enginetoolloopfacadetoolinvoker
 */
class FacadeToolInvokerTest extends TestCase
{
    /**
     * A successful tool call records exactly one `tool` step with the tool
     * name and outcome `ok`.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     */
    public function testSuccessfulCallRecordsOkStep(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('invokeTool')->willReturn(
            [
                'result'  => ['meetings' => []],
                'isError' => false,
            ]
        );

        $trace   = new RunTraceCollector();
        $invoker = new FacadeToolInvoker(facade: $facade, channel: null, trace: $trace);

        $invoker->decidesk_listMeetings(limit: 5);

        $steps = $trace->toArray();
        $this->assertCount(1, $steps);
        $this->assertSame('tool', $steps[0]['type']);
        $this->assertSame('decidesk_listMeetings', $steps[0]['name']);
        $this->assertSame('ok', $steps[0]['outcome']);

    }//end testSuccessfulCallRecordsOkStep()

    /**
     * An `isError` result records outcome `error`, never `ok`.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     */
    public function testErrorResultRecordsErrorStep(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('invokeTool')->willReturn(
            [
                'result'  => ['error' => 'Unknown tool: nope'],
                'isError' => true,
            ]
        );

        $trace   = new RunTraceCollector();
        $invoker = new FacadeToolInvoker(facade: $facade, channel: null, trace: $trace);

        $invoker->nope();

        $steps = $trace->toArray();
        $this->assertCount(1, $steps);
        $this->assertSame('error', $steps[0]['outcome']);

    }//end testErrorResultRecordsErrorStep()

    /**
     * The collector and the streaming channel both fire independently for the
     * same call — neither interferes with the other.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     */
    public function testCollectorAndChannelBothRecordTheSameCall(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('invokeTool')->willReturn(
            [
                'result'  => ['ok' => true],
                'isError' => false,
            ]
        );

        $channel   = new StreamYieldChannel();
        $toolCalls = [];
        $channel->onToolCall(
                function (array $payload) use (&$toolCalls): void {
                    $toolCalls[] = $payload;
                }
                );

        $trace   = new RunTraceCollector();
        $invoker = new FacadeToolInvoker(facade: $facade, channel: $channel, trace: $trace);

        $invoker->a_tool();

        $this->assertCount(1, $toolCalls, 'The channel must still fire independently of the collector.');
        $this->assertCount(1, $trace->toArray(), 'The collector must still record independently of the channel.');

    }//end testCollectorAndChannelBothRecordTheSameCall()

    /**
     * Omitting the collector entirely (every existing caller) is a no-op —
     * the call behaves exactly as before this change, never a fatal error.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     */
    public function testNoCollectorIsANoOp(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('invokeTool')->willReturn(
            [
                'result'  => ['ok' => true],
                'isError' => false,
            ]
        );

        $invoker = new FacadeToolInvoker(facade: $facade);
        $encoded = $invoker->a_tool();

        $this->assertSame(['ok' => true], json_decode($encoded, true));

    }//end testNoCollectorIsANoOp()

    /**
     * `hermiq.searchTools` is handled Hermiq-internally against `ToolSearchService`
     * — never a facade round-trip (agent-tool-governance-and-disclosure).
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-the-model-searches-for-and-then-invokes-a-deferred-tool
     */
    public function testSearchToolsIsHandledInternallyWithoutFacadeCall(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->never())->method('invokeTool');

        $searchService = new ToolSearchService();
        $searchService->registerResolved(
            descriptors: [['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search', 'description' => 'Search leads']]
        );

        $invoker = new FacadeToolInvoker(facade: $facade, toolSearchService: $searchService);
        $encoded = $invoker->hermiq_searchTools(query: 'lead');

        $decoded = json_decode($encoded, true);
        $this->assertSame(1, $decoded['count']);
        $this->assertSame('pipelinq.lead.search', $decoded['matches'][0]['mcpId']);

    }//end testSearchToolsIsHandledInternallyWithoutFacadeCall()

    /**
     * A write/destructive tool NOT in the run's resolved (grant-filtered) set
     * routes through the approval gate: a pending Approval is created and the
     * facade is NEVER invoked.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
     */
    public function testUngrantedDestructiveToolRoutesThroughApprovalGate(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->never())->method('invokeTool');

        $approvalService = $this->createMock(ApprovalService::class);
        $approvalService->method('findDecidedApprovalForToolInvocation')->willReturn(null);

        $pending = new ObjectEntity();
        $pending->setUuid('appr-pending');
        $approvalService->expects($this->once())
            ->method('ensurePendingApprovalForToolInvocation')
            ->with('agent-1', 'pipelinq.lead.delete', ['id' => '7'])
            ->willReturn($pending);

        $searchService = new ToolSearchService();
        $searchService->registerResolved(descriptors: [['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search']]);

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            toolSearchService: $searchService,
            approvalService: $approvalService,
            agentId: 'agent-1',
            mcpIdByName: ['pipelinq_lead_delete' => 'pipelinq.lead.delete']
        );

        $encoded = $invoker->pipelinq_lead_delete(id: '7');
        $decoded = json_decode($encoded, true);

        $this->assertTrue($decoded['isError']);
        $this->assertSame('pending', $decoded['status']);
        $this->assertSame('appr-pending', $decoded['approvalId']);

    }//end testUngrantedDestructiveToolRoutesThroughApprovalGate()

    /**
     * A denied Approval blocks the invocation permanently — the facade is
     * never invoked, and no new Approval is created.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
     */
    public function testDeniedApprovalBlocksInvocationPermanently(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->never())->method('invokeTool');

        $denied = new ObjectEntity();
        $denied->setUuid('appr-denied');
        $denied->setObject(['status' => 'denied']);

        $approvalService = $this->createMock(ApprovalService::class);
        $approvalService->method('findDecidedApprovalForToolInvocation')->willReturn($denied);
        $approvalService->expects($this->never())->method('ensurePendingApprovalForToolInvocation');

        $searchService = new ToolSearchService();

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            toolSearchService: $searchService,
            approvalService: $approvalService,
            agentId: 'agent-1',
            mcpIdByName: ['pipelinq_lead_delete' => 'pipelinq.lead.delete']
        );

        $decoded = json_decode($invoker->pipelinq_lead_delete(id: '7'), true);

        $this->assertTrue($decoded['isError']);
        $this->assertSame('denied', $decoded['status']);

    }//end testDeniedApprovalBlocksInvocationPermanently()

    /**
     * An already-approved decision proceeds to the facade — no new Approval,
     * and RBAC still authorises at invoke time (the facade call is unchanged).
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-explicitly-granted-destructive-tool-call-is-not-re-gated
     */
    public function testApprovedDecisionProceedsToFacade(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('invokeTool')
            ->with('pipelinq_lead_delete', ['id' => '7'])
            ->willReturn(['result' => ['deleted' => true], 'isError' => false]);

        $approved = new ObjectEntity();
        $approved->setUuid('appr-approved');
        $approved->setObject(['status' => 'approved']);

        $approvalService = $this->createMock(ApprovalService::class);
        $approvalService->method('findDecidedApprovalForToolInvocation')->willReturn($approved);
        $approvalService->expects($this->never())->method('ensurePendingApprovalForToolInvocation');

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            toolSearchService: new ToolSearchService(),
            approvalService: $approvalService,
            agentId: 'agent-1',
            mcpIdByName: ['pipelinq_lead_delete' => 'pipelinq.lead.delete']
        );

        $decoded = json_decode($invoker->pipelinq_lead_delete(id: '7'), true);
        $this->assertSame(['deleted' => true], $decoded);

    }//end testApprovedDecisionProceedsToFacade()

    /**
     * A destructive tool that IS in the run's resolved (grant-filtered) set is
     * never gated — it dispatches straight to the facade.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-explicitly-granted-destructive-tool-call-is-not-re-gated
     */
    public function testGrantedDestructiveToolIsNotGated(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('invokeTool')
            ->with('pipelinq_lead_delete', ['id' => '7'])
            ->willReturn(['result' => ['deleted' => true], 'isError' => false]);

        $approvalService = $this->createMock(ApprovalService::class);
        $approvalService->expects($this->never())->method('findDecidedApprovalForToolInvocation');
        $approvalService->expects($this->never())->method('ensurePendingApprovalForToolInvocation');

        $searchService = new ToolSearchService();
        $searchService->registerResolved(descriptors: [['name' => 'pipelinq_lead_delete', 'mcpId' => 'pipelinq.lead.delete']]);

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            toolSearchService: $searchService,
            approvalService: $approvalService,
            agentId: 'agent-1',
            mcpIdByName: ['pipelinq_lead_delete' => 'pipelinq.lead.delete']
        );

        $decoded = json_decode($invoker->pipelinq_lead_delete(id: '7'), true);
        $this->assertSame(['deleted' => true], $decoded);

    }//end testGrantedDestructiveToolIsNotGated()

    /**
     * A curated, un-granted 2-segment tool (no verb suffix to classify from, and
     * no descriptor available since it was never part of the resolved set) now
     * routes through the approval gate — hermiq-prefer-tool-hints closes the
     * hole where such a tool was UNCLASSIFIABLE and therefore never gated at all
     * (it silently dispatched straight to the facade before this change).
     *
     * @return void
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
     */
    public function testUngrantedCuratedTwoSegmentToolNowRoutesThroughApprovalGate(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->never())->method('invokeTool');

        $approvalService = $this->createMock(ApprovalService::class);
        $approvalService->method('findDecidedApprovalForToolInvocation')->willReturn(null);

        $pending = new ObjectEntity();
        $pending->setUuid('appr-pending-curated');
        $approvalService->expects($this->once())
            ->method('ensurePendingApprovalForToolInvocation')
            ->with('agent-1', 'pipelinq.createLead', ['name' => 'Acme'])
            ->willReturn($pending);

        // A tool the LLM attempted OUTSIDE its resolved catalog — nothing was
        // ever registered for it, so ToolSearchService has no descriptor.
        $searchService = new ToolSearchService();

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            toolSearchService: $searchService,
            approvalService: $approvalService,
            agentId: 'agent-1',
            mcpIdByName: ['pipelinq_createLead' => 'pipelinq.createLead']
        );

        $decoded = json_decode($invoker->pipelinq_createLead(name: 'Acme'), true);

        $this->assertTrue($decoded['isError']);
        $this->assertSame('pending', $decoded['status']);
        $this->assertSame('appr-pending-curated', $decoded['approvalId']);

    }//end testUngrantedCuratedTwoSegmentToolNowRoutesThroughApprovalGate()

    /**
     * With no agentId (agent-less chat), the approval gate is disabled entirely
     * — a destructive tool dispatches straight to the facade, unchanged.
     *
     * @return void
     */
    public function testNoAgentIdDisablesApprovalGate(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())->method('invokeTool')->willReturn(['result' => [], 'isError' => false]);

        $approvalService = $this->createMock(ApprovalService::class);
        $approvalService->expects($this->never())->method('findDecidedApprovalForToolInvocation');

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            toolSearchService: new ToolSearchService(),
            approvalService: $approvalService,
            agentId: null,
            mcpIdByName: ['pipelinq_lead_delete' => 'pipelinq.lead.delete']
        );

        $invoker->pipelinq_lead_delete(id: '7');

    }//end testNoAgentIdDisablesApprovalGate()

    /**
     * A force-redacting RedactionService instance for dry-run argument tests.
     *
     * @return RedactionService
     */
    private function redactionService(): RedactionService
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('yes');
        return new RedactionService($config);

    }//end redactionService()

    /**
     * A side-effecting tool is neutralised in a dry-run: the facade is NEVER
     * invoked, the trace step outcome is `would-have-called` and carries the
     * tool's (redacted) arguments, and the synthetic result is marked
     * `preview: true` (run-replay-and-dry-run).
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
     */
    public function testDryRunNeutralisesSideEffectingToolWithRedactedArguments(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->never())->method('invokeTool');

        $trace   = new RunTraceCollector();
        $invoker = new FacadeToolInvoker(
            facade: $facade,
            trace: $trace,
            mcpIdByName: ['talk_sendMessage' => 'talk.schema.create'],
            dryRun: true,
            classifier: new ToolClassificationService(),
            redactionService: $this->redactionService()
        );

        $encoded = $invoker->talk_sendMessage(text: 'weekly summary', apiKey: 'sk-abcdefghijklmnop');
        $decoded = json_decode($encoded, true);

        $this->assertTrue($decoded['preview']);

        $steps = $trace->toArray();
        $this->assertCount(1, $steps);
        $this->assertSame('would-have-called', $steps[0]['outcome']);
        $this->assertSame('weekly summary', $steps[0]['arguments']['text'], 'A non-secret argument passes through unmasked.');
        $this->assertStringNotContainsString(
            'sk-abcdefghijklmnop',
            json_encode($steps[0]['arguments']),
            'A secret-shaped argument value must be masked before it reaches the trace.'
        );

    }//end testDryRunNeutralisesSideEffectingToolWithRedactedArguments()

    /**
     * A read-only-classified tool still executes for real in a dry-run — the
     * step outcome is `ok`, never `would-have-called`.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
     */
    public function testDryRunStillInvokesReadOnlyToolForReal(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())->method('invokeTool')->willReturn(
            ['result' => ['matches' => []], 'isError' => false]
        );

        $trace   = new RunTraceCollector();
        $invoker = new FacadeToolInvoker(
            facade: $facade,
            trace: $trace,
            mcpIdByName: ['demo_search' => 'demo.schema.search'],
            dryRun: true,
            classifier: new ToolClassificationService()
        );

        $invoker->demo_search(query: 'x');

        $steps = $trace->toArray();
        $this->assertCount(1, $steps);
        $this->assertSame('ok', $steps[0]['outcome']);

    }//end testDryRunStillInvokesReadOnlyToolForReal()

    /**
     * An unclassified tool (no descriptor, no verb suffix) defaults to
     * neutralised in a dry-run — fail-safe closed.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
     */
    public function testDryRunNeutralisesUnclassifiedToolByDefault(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->never())->method('invokeTool');

        $trace   = new RunTraceCollector();
        $invoker = new FacadeToolInvoker(
            facade: $facade,
            trace: $trace,
            dryRun: true,
            classifier: new ToolClassificationService()
        );

        $invoker->some_unclassified_tool(x: 1);

        $steps = $trace->toArray();
        $this->assertSame('would-have-called', $steps[0]['outcome']);

    }//end testDryRunNeutralisesUnclassifiedToolByDefault()

    /**
     * With `dryRun=false` (every pre-existing caller), a write-classified tool
     * is still dispatched to the facade for real — the flag, not merely its
     * absence, is what gates neutralisation.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
     */
    public function testDryRunFalseDispatchesWriteToolForRealUnchanged(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())->method('invokeTool')->willReturn(
            ['result' => ['ok' => true], 'isError' => false]
        );

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            mcpIdByName: ['demo_create' => 'demo.schema.create'],
            dryRun: false,
            classifier: new ToolClassificationService()
        );

        $invoker->demo_create(name: 'x');

    }//end testDryRunFalseDispatchesWriteToolForRealUnchanged()

    /**
     * agent-memory-tools: the run's own agentId is injected into the arguments of
     * exactly the three memory tool ids before the facade call — HermiqToolProvider
     * has no other way to learn which agent is running (the IMcpToolProvider ABI
     * threads no acting-agent identity into invokeTool() itself).
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
     */
    public function testAgentIdIsInjectedForMemoryToolsOnly(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('invokeTool')
            ->with('hermiq_rememberMemory', ['content' => 'x', 'scope' => 'agent', 'agentId' => 'agent-1'])
            ->willReturn(['result' => ['remembered' => true], 'isError' => false]);

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            agentId: 'agent-1',
            mcpIdByName: ['hermiq_rememberMemory' => 'hermiq.rememberMemory']
        );

        $invoker->hermiq_rememberMemory(content: 'x', scope: 'agent');

    }//end testAgentIdIsInjectedForMemoryToolsOnly()

    /**
     * A NON-memory tool's arguments are never touched by the agentId injection —
     * every other tool's behaviour is byte-for-byte unchanged.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
     */
    public function testAgentIdIsNotInjectedForNonMemoryTools(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('invokeTool')
            ->with('hermiq_listFiles', ['path' => '/'])
            ->willReturn(['result' => ['entries' => []], 'isError' => false]);

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            agentId: 'agent-1',
            mcpIdByName: ['hermiq_listFiles' => 'hermiq.listFiles']
        );

        $invoker->hermiq_listFiles(path: '/');

    }//end testAgentIdIsNotInjectedForNonMemoryTools()

    /**
     * With no agent context (agent-less chat, `agentId: null`), a memory tool's
     * arguments are left unchanged — `HermiqToolProvider` then returns its own
     * `no_agent_context` error rather than the invoker guessing an agentId.
     *
     * @return void
     *
     * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
     */
    public function testNoAgentIdLeavesMemoryToolArgumentsUnchanged(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('invokeTool')
            ->with('hermiq_recallMemory', ['query' => 'x'])
            ->willReturn(['result' => ['error' => ['code' => 'no_agent_context']], 'isError' => true]);

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            agentId: null,
            mcpIdByName: ['hermiq_recallMemory' => 'hermiq.recallMemory']
        );

        $invoker->hermiq_recallMemory(query: 'x');

    }//end testNoAgentIdLeavesMemoryToolArgumentsUnchanged()

    /**
     * A `hermiq.webFetch` call's trace step carries `target` reduced to host+path,
     * with the query string dropped ENTIRELY (web-research-tool).
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
     */
    public function testWebFetchTraceStepCarriesHostAndPathTargetWithoutQueryString(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('invokeTool')->willReturn(
            ['result' => ['url' => 'https://example.test/a/b?token=SECRET&q=1', 'truncated' => false], 'isError' => false]
        );

        $trace   = new RunTraceCollector();
        $invoker = new FacadeToolInvoker(
            facade: $facade,
            trace: $trace,
            mcpIdByName: ['hermiq_webFetch' => 'hermiq.webFetch']
        );

        $invoker->hermiq_webFetch(url: 'https://example.test/a/b?token=SECRET&q=1');

        $steps = $trace->toArray();
        $this->assertCount(1, $steps);
        $this->assertSame('example.test/a/b', $steps[0]['target']);
        $this->assertStringNotContainsString('SECRET', $steps[0]['target']);
        $this->assertStringNotContainsString('?', $steps[0]['target']);

    }//end testWebFetchTraceStepCarriesHostAndPathTargetWithoutQueryString()

    /**
     * A `hermiq.webSearch` call's trace step carries the raw query text as its
     * target (not a URL, so there is no host+path to reduce it to).
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
     */
    public function testWebSearchTraceStepCarriesTheQueryAsTarget(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('invokeTool')->willReturn(['result' => ['query' => 'nextcloud news', 'results' => []], 'isError' => false]);

        $trace   = new RunTraceCollector();
        $invoker = new FacadeToolInvoker(
            facade: $facade,
            trace: $trace,
            mcpIdByName: ['hermiq_webSearch' => 'hermiq.webSearch']
        );

        $invoker->hermiq_webSearch(query: 'nextcloud news');

        $steps = $trace->toArray();
        $this->assertSame('nextcloud news', $steps[0]['target']);

    }//end testWebSearchTraceStepCarriesTheQueryAsTarget()

    /**
     * Every OTHER tool's trace step is unaffected — no `target` key at all (zero
     * behavior change outside the two web-research tool ids).
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
     */
    public function testNonWebResearchToolStepCarriesNoTargetKey(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('invokeTool')->willReturn(['result' => ['entries' => []], 'isError' => false]);

        $trace   = new RunTraceCollector();
        $invoker = new FacadeToolInvoker(
            facade: $facade,
            trace: $trace,
            mcpIdByName: ['hermiq_listFiles' => 'hermiq.listFiles']
        );

        $invoker->hermiq_listFiles(path: '/');

        $steps = $trace->toArray();
        $this->assertArrayNotHasKey('target', $steps[0]);

    }//end testNonWebResearchToolStepCarriesNoTargetKey()
}//end class
