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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;

/**
 * Dispatches LLPhant tool calls onto the OR tool-registry facade.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 */
class FacadeToolInvoker
{
    /**
     * Constructor.
     *
     * @param ToolRegistryFacade      $facade  The OR public tool read/invoke surface.
     * @param StreamYieldChannel|null $channel Optional streaming channel for
     *                                         tool_call/tool_result frames.
     * @param RunTraceCollector|null  $trace   Optional run-trace collector; when
     *                                         supplied, each invocation is timed as
     *                                         a `tool` step (run-trace-observability).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     */
    public function __construct(
        private readonly ToolRegistryFacade $facade,
        private readonly ?StreamYieldChannel $channel=null,
        private readonly ?RunTraceCollector $trace=null
    ) {
    }//end __construct()

    /**
     * Catch LLPhant's `$instance->{$functionName}(...$args)` dispatch and route
     * it through `ToolRegistryFacade::invokeTool()`.
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
     */
    public function __call(string $name, array $arguments): string
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

    }//end __call()
}//end class
