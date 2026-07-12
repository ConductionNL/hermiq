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

use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
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
        $channel->onToolCall(function (array $payload) use (&$toolCalls): void {
            $toolCalls[] = $payload;
        });

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
}//end class
