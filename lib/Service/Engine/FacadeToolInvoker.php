<?php

/**
 * Hermiq Facade Tool Invoker.
 *
 * The `$instance` object attached to every LLPhant `FunctionInfo` the ToolLoop
 * builds. LLPhant dispatches tool calls as `$instance->{$functionName}(...$args)`
 * (see `FunctionInfo::callWithArguments()`); this class catches that dispatch via
 * `__call()` and routes it to `ToolRegistryFacade::invokeTool()` — the documented
 * OR public surface — instead of a concrete `ToolInterface` method.
 *
 * Also absorbs OR's `StreamingToolInstanceWrapper`: when a `StreamYieldChannel`
 * is attached, each invocation fans a `tool_call` frame out before the facade
 * call and a `tool_result` frame after it, so SSE consumers see per-tool
 * progress exactly as they did on the OR path. With no channel the invocation
 * is a plain blocking call (load-bearing for `POST /api/chat/send`).
 *
 * run-trace-observability: when a `RunTraceCollector` is attached, each
 * invocation is additionally timed as one `tool` step (name = the registry id
 * the LLM called; outcome `ok`/`error` from the facade's `isError` flag) —
 * never the raw arguments or result, only name/timing/outcome (no secret-leak
 * surface reintroduced into the audit trail).
 *
 * agent-tool-governance-and-disclosure adds two more short-circuits BEFORE the
 * facade dispatch:
 *
 * - `hermiq.searchTools` (progressive disclosure's meta-tool) is
 *   Hermiq-INTERNAL — resolved directly against the run's `ToolSearchService`
 *   (design.md §2: "the invocation never leaves Hermiq" — no facade round-trip).
 * - A write/destructive-classified tool (`ToolGrantResolver::isWriteOrDestructive()`)
 *   NOT part of this run's resolved (grant-filtered, default-denied) set —
 *   `ToolSearchService::isGranted()` — routes through the existing
 *   `human-approval-gate` `Approval` state machine instead of executing: a
 *   pending `Approval` is created (or an already-decided one is consulted) and
 *   `ToolRegistryFacade::invokeTool()` is NOT called until it is `approved`; a
 *   `denied` `Approval` blocks the invocation permanently. This is a
 *   defense-in-depth check at the point of actual invocation — independent of
 *   whether `ToolLoop` already excluded the tool from the model's context.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 * @spec openspec/changes/run-trace-observability/tasks.md#task-2-thread-the-collector-through-enginetoolloopfacadetoolinvoker
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;

/**
 * Dispatches LLPhant tool calls onto the OR tool-registry facade.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 */
class FacadeToolInvoker
{

    /**
     * The `hermiq.searchTools` meta-tool's registry id and LLPhant-safe name
     * (dots become underscores — `McpProviderBridge::safeFunctionName()`).
     *
     * @var array<int, string>
     */
    private const SEARCH_TOOLS_NAMES = ['hermiq.searchTools', 'hermiq_searchTools'];

    /**
     * Constructor.
     *
     * @param ToolRegistryFacade      $facade            The OR public tool read/invoke surface.
     * @param StreamYieldChannel|null $channel           Optional streaming channel for
     *                                                   tool_call/tool_result frames.
     * @param RunTraceCollector|null  $trace             Optional run-trace collector; when
     *                                                   supplied, each invocation is timed
     *                                                   as a `tool` step
     *                                                   (run-trace-observability).
     * @param ToolSearchService|null  $toolSearchService Per-run resolved-set + `searchTools`
     *                                                   ranking (agent-tool-governance-and-disclosure);
     *                                                   null disables both the meta-tool
     *                                                   short-circuit and the approval-gate's
     *                                                   grant-membership check (agent-less chat).
     * @param ApprovalService|null    $approvalService   Human-approval gate; null disables the
     *                                                   destructive-invocation short-circuit
     *                                                   (existing callers, unchanged
     *                                                   behaviour).
     * @param string|null             $agentId           The acting agent's UUID; null disables
     *                                                   the approval gate (no reviewer/owner
     *                                                   to route to).
     * @param array<string,string>    $mcpIdByName       Map of LLPhant-safe function name to the
     *                                                   dotted `mcpId` — resolves the id the
     *                                                   approval gate classifies/checks (LLPhant
     *                                                   calls back with the safe name, which may
     *                                                   have dots replaced by underscores).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI + per-run governance
     *   context; every parameter is independently optional/nullable for backward
     *   compatibility with existing (pre-governance) call sites.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-4
     */
    public function __construct(
        private readonly ToolRegistryFacade $facade,
        private readonly ?StreamYieldChannel $channel=null,
        private readonly ?RunTraceCollector $trace=null,
        private readonly ?ToolSearchService $toolSearchService=null,
        private readonly ?ApprovalService $approvalService=null,
        private readonly ?string $agentId=null,
        private readonly array $mcpIdByName=[]
    ) {
    }//end __construct()

    /**
     * Catch LLPhant's `$instance->{$functionName}(...$args)` dispatch and route
     * it through `ToolRegistryFacade::invokeTool()` — unless it is the
     * `hermiq.searchTools` meta-tool (handled internally) or an un-granted
     * destructive tool (routed through the human-approval gate instead).
     *
     * PHP collects named arguments into `$arguments` with string keys — exactly
     * the decoded-arguments object shape `invokeTool()` expects. The facade's
     * `{result, isError}` envelope is JSON-encoded for the LLM's tool-result
     * message (LLPhant expects a string return it can feed back as a tool turn).
     *
     * @param string               $name      The tool function name the LLM called.
     * @param array<string, mixed> $arguments Decoded arguments object.
     *
     * @return string JSON-encoded tool result for the follow-up LLM turn.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-4
     */
    public function __call(string $name, array $arguments): string
    {
        if ($this->toolSearchService !== null && in_array($name, self::SEARCH_TOOLS_NAMES, true) === true) {
            return $this->handleSearchTools(arguments: $arguments);
        }

        if ($this->requiresApprovalGate(name: $name) === true) {
            return $this->handleGatedInvocation(name: $name, arguments: $arguments);
        }

        return $this->dispatchToFacade(name: $name, arguments: $arguments);

    }//end __call()

    /**
     * The `hermiq.searchTools` meta-tool: ranks this run's resolved (already
     * grant-filtered, default-denied) descriptor set against the query and
     * returns matches directly — never a facade round-trip.
     *
     * @param array<string, mixed> $arguments Decoded arguments (`{"query": "..."}`).
     *
     * @return string JSON-encoded `{matches, count}`.
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-the-model-searches-for-and-then-invokes-a-deferred-tool
     */
    private function handleSearchTools(array $arguments): string
    {
        $query   = (string) ($arguments['query'] ?? '');
        $matches = $this->toolSearchService->search(query: $query);

        $this->channel?->emitToolCall(payload: ['toolId' => 'hermiq.searchTools', 'arguments' => $arguments]);
        $this->channel?->emitToolResult(
            payload: ['toolId' => 'hermiq.searchTools', 'result' => ['matches' => $matches], 'isError' => false]
        );

        $encoded = json_encode(['matches' => $matches, 'count' => count($matches)]);
        if (is_string($encoded) === false) {
            return '{"matches":[],"count":0}';
        }

        return $encoded;

    }//end handleSearchTools()

    /**
     * Whether `$name` must route through the human-approval gate: it is
     * write/destructive-classified AND not part of this run's resolved
     * (grant-filtered, default-denied) set.
     *
     * @param string $name The LLPhant-side function name.
     *
     * @return bool
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#requirement-un-granted-destructive-tool-invocation-routes-through-the-approval-gate
     */
    private function requiresApprovalGate(string $name): bool
    {
        if ($this->approvalService === null || $this->agentId === null || $this->toolSearchService === null) {
            return false;
        }

        $toolId = $this->resolveToolId(name: $name);
        if (ToolGrantResolver::isWriteOrDestructive(id: $toolId) === false) {
            return false;
        }

        return $this->toolSearchService->isGranted(id: $toolId) === false;

    }//end requiresApprovalGate()

    /**
     * Consult (or create) the tool-invocation `Approval` and either dispatch to
     * the facade (an already-`approved` decision) or block (pending/denied).
     *
     * @param string               $name      The LLPhant-side function name.
     * @param array<string, mixed> $arguments Decoded arguments object.
     *
     * @return string JSON-encoded outcome for the follow-up LLM turn.
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
     */
    private function handleGatedInvocation(string $name, array $arguments): string
    {
        $toolId = $this->resolveToolId(name: $name);

        $decided = $this->approvalService->findDecidedApprovalForToolInvocation(
            agentId: (string) $this->agentId,
            toolId: $toolId
        );

        if ($decided !== null && (string) ($decided->getObject()['status'] ?? '') === 'approved') {
            return $this->dispatchToFacade(name: $name, arguments: $arguments);
        }

        $envelope = ['isError' => true, 'error' => 'approval_required', 'toolId' => $toolId];
        if ($decided !== null) {
            $envelope['status']     = 'denied';
            $envelope['approvalId'] = (string) $decided->getUuid();
            $envelope['message']    = 'This action was denied by a reviewer and cannot be run.';
        } else {
            $approval = $this->approvalService->ensurePendingApprovalForToolInvocation(
                agentId: (string) $this->agentId,
                toolId: $toolId,
                arguments: $arguments
            );

            $envelope['status']     = 'pending';
            $envelope['approvalId'] = (string) $approval->getUuid();
            $envelope['message']    = 'This action requires human approval before it can run.';
        }

        $this->channel?->emitToolResult(payload: ['toolId' => $toolId, 'result' => $envelope, 'isError' => true]);

        $encoded = json_encode($envelope);
        if (is_string($encoded) === false) {
            return '{"isError":true,"error":"approval_required"}';
        }

        return $encoded;

    }//end handleGatedInvocation()

    /**
     * Resolve the LLPhant-side function name back to the dotted `mcpId` the
     * grant/approval logic classifies against.
     *
     * @param string $name The LLPhant-side function name.
     *
     * @return string
     */
    private function resolveToolId(string $name): string
    {
        return ($this->mcpIdByName[$name] ?? $name);

    }//end resolveToolId()

    /**
     * The pre-existing plain dispatch: forward the call to
     * `ToolRegistryFacade::invokeTool()`, emitting channel frames / trace step.
     *
     * @param string               $name      The tool function name the LLM called.
     * @param array<string, mixed> $arguments Decoded arguments object.
     *
     * @return string JSON-encoded tool result for the follow-up LLM turn.
     */
    private function dispatchToFacade(string $name, array $arguments): string
    {
        $this->channel?->emitToolCall(
            payload: [
                'toolId'    => $name,
                'arguments' => $arguments,
            ]
        );

        $traceToken = null;
        if ($this->trace !== null) {
            $traceToken = $this->trace->startStep(type: 'tool', name: $name);
        }

        // The facade's return shape is a documented contract:
        // {result: array, isError: bool} (ai-mcp REQ-006).
        $envelope = $this->facade->invokeTool(toolId: $name, arguments: $arguments);

        if ($this->trace !== null && $traceToken !== null) {
            $outcome = 'ok';
            if ($envelope['isError'] === true) {
                $outcome = 'error';
            }

            $this->trace->endStep(token: $traceToken, outcome: $outcome);
        }

        $this->channel?->emitToolResult(
            payload: [
                'toolId'  => $name,
                'result'  => $envelope['result'],
                'isError' => $envelope['isError'],
            ]
        );

        $encoded = json_encode($envelope['result']);
        if (is_string($encoded) === false) {
            return '{"error":"Tool result could not be encoded"}';
        }

        return $encoded;

    }//end dispatchToFacade()
}//end class
