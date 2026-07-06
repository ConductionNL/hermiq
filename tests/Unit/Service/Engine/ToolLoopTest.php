<?php

/**
 * Unit tests for ToolLoop + FacadeToolInvoker (agent-engine-port).
 *
 * Covers the Agent.tools whitelist enforcement (empty = allow all, ADR-035
 * Decision 4), per-request selectedTools narrowing including the empty-intersection
 * guard, legacy bare-id expansion, descriptor → FunctionInfo conversion (including
 * the free-form-object-to-string guard), and the invoker's facade dispatch with
 * tool_call/tool_result channel frames.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the facade-backed tool loop.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 */
class ToolLoopTest extends TestCase
{

    /**
     * An Agent ObjectEntity with the given tools whitelist.
     *
     * @param array|null $tools The tools[] whitelist (null = field absent).
     *
     * @return ObjectEntity
     */
    private function agent(?array $tools): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('agent-uuid');
        $payload = ['name' => 'Test agent'];
        if ($tools !== null) {
            $payload['tools'] = $tools;
        }

        $entity->setObject($payload);
        return $entity;

    }//end agent()

    /**
     * A null agent yields no tools and never hits the facade.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
     */
    public function testNullAgentYieldsNoToolsWithoutFacadeCall(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->never())->method('listTools');

        $loop = new ToolLoop($facade, new NullLogger());
        $this->assertSame([], $loop->listAgentFunctions(agent: null));

    }//end testNullAgentYieldsNoToolsWithoutFacadeCall()

    /**
     * An EMPTY whitelist means "all discovered tools allowed": listTools([]) is
     * called and its full result returned (ADR-035 Decision 4 default).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
     */
    public function testEmptyWhitelistAllowsAllTools(): void
    {
        $all = [
            ['name' => 'decidesk_listMeetings', 'description' => 'List', 'parameters' => []],
            ['name' => 'openregister_search', 'description' => 'Search', 'parameters' => []],
        ];

        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('listTools')
            ->with([])
            ->willReturn($all);

        $loop = new ToolLoop($facade, new NullLogger());
        $this->assertSame($all, $loop->listAgentFunctions(agent: $this->agent(tools: [])));

    }//end testEmptyWhitelistAllowsAllTools()

    /**
     * A non-empty whitelist is passed through to listTools(); bare legacy ids
     * are additionally expanded with the openregister. prefix.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
     */
    public function testWhitelistPassedThroughWithLegacyExpansion(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('listTools')
            ->with(['decidesk.listMeetings', 'objects', 'openregister.objects'])
            ->willReturn([]);

        $loop = new ToolLoop($facade, new NullLogger());
        $loop->listAgentFunctions(agent: $this->agent(tools: ['decidesk.listMeetings', 'objects']));

    }//end testWhitelistPassedThroughWithLegacyExpansion()

    /**
     * selectedTools narrows a non-empty whitelist via intersection.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
     */
    public function testSelectedToolsIntersectWhitelist(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('listTools')
            ->with(['a.one'])
            ->willReturn([]);

        $loop = new ToolLoop($facade, new NullLogger());
        $loop->listAgentFunctions(
            agent: $this->agent(tools: ['a.one', 'b.two']),
            selectedTools: ['a.one', 'c.three']
        );

    }//end testSelectedToolsIntersectWhitelist()

    /**
     * An empty whitelist-selection intersection means NO tools — the loop must
     * return [] and NOT call listTools([]) (which would mean "all", the exact
     * opposite).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
     */
    public function testEmptyIntersectionShortCircuitsToNoTools(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->never())->method('listTools');

        $loop   = new ToolLoop($facade, new NullLogger());
        $result = $loop->listAgentFunctions(
            agent: $this->agent(tools: ['a.one']),
            selectedTools: ['c.three']
        );

        $this->assertSame([], $result);

    }//end testEmptyIntersectionShortCircuitsToNoTools()

    /**
     * When the agent whitelist is empty (all allowed), selectedTools becomes the
     * effective whitelist.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
     */
    public function testSelectionBecomesWhitelistWhenAgentAllowsAll(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('listTools')
            ->with(['a.one'])
            ->willReturn([]);

        $loop = new ToolLoop($facade, new NullLogger());
        $loop->listAgentFunctions(agent: $this->agent(tools: []), selectedTools: ['a.one']);

    }//end testSelectionBecomesWhitelistWhenAgentAllowsAll()

    /**
     * Descriptors convert to FunctionInfo objects: parameters/required mapped to
     * Parameter objects, the invoker attached as the instance, and a free-form
     * object property represented as a string parameter (Ollama guard).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     */
    public function testBuildFunctionInfosConvertsDescriptors(): void
    {
        $descriptor = [
            'name'        => 'decidesk_createMeeting',
            'description' => 'Create a meeting',
            'parameters'  => [
                'properties' => [
                    'title'   => ['type' => 'string', 'description' => 'Meeting title'],
                    'count'   => ['type' => 'integer'],
                    'payload' => ['type' => 'object', 'description' => 'Free-form config'],
                ],
                'required'   => ['title'],
            ],
        ];

        $facade = $this->createMock(ToolRegistryFacade::class);
        $loop   = new ToolLoop($facade, new NullLogger());

        $infos = $loop->buildFunctionInfos(functions: [$descriptor]);

        $this->assertCount(1, $infos);
        $info = $infos[0];
        $this->assertSame('decidesk_createMeeting', $info->name);
        $this->assertInstanceOf(FacadeToolInvoker::class, $info->instance);
        $this->assertCount(3, $info->parameters);

        // Required names mapped back to Parameter OBJECTS.
        $this->assertCount(1, $info->requiredParameters);
        $this->assertSame('title', $info->requiredParameters[0]->name);

        // The free-form object degraded to a string parameter with guidance.
        $payloadParam = $info->parameters[2];
        $this->assertSame('payload', $payloadParam->name);
        $this->assertSame('string', $payloadParam->type);
        $this->assertStringContainsString('JSON object', $payloadParam->description);

    }//end testBuildFunctionInfosConvertsDescriptors()

    /**
     * The invoker routes LLPhant's magic dispatch onto invokeTool() and fans
     * tool_call/tool_result frames out to the channel.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     */
    public function testInvokerDispatchesToFacadeAndEmitsFrames(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('invokeTool')
            ->with('decidesk_listMeetings', ['limit' => 5])
            ->willReturn(
                [
                    'result'  => ['meetings' => ['a', 'b']],
                    'isError' => false,
                ]
            );

        $channel   = new StreamYieldChannel();
        $toolCalls = [];
        $results   = [];
        $channel->onToolCall(function (array $payload) use (&$toolCalls): void {
            $toolCalls[] = $payload;
        });
        $channel->onToolResult(function (array $payload) use (&$results): void {
            $results[] = $payload;
        });

        $invoker = new FacadeToolInvoker(facade: $facade, channel: $channel);

        /*
         * Simulate LLPhant's `$instance->{$name}(...$args)` dispatch with named args.
         */

        $encoded = $invoker->decidesk_listMeetings(limit: 5);

        $this->assertSame(['meetings' => ['a', 'b']], json_decode($encoded, true));
        $this->assertCount(1, $toolCalls);
        $this->assertSame('decidesk_listMeetings', $toolCalls[0]['toolId']);
        $this->assertSame(['limit' => 5], $toolCalls[0]['arguments']);
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['isError']);

    }//end testInvokerDispatchesToFacadeAndEmitsFrames()

    /**
     * Without a channel the invoker is a plain blocking facade call (no frames,
     * no error).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     */
    public function testInvokerWorksWithoutChannel(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('invokeTool')->willReturn(
            [
                'result'  => ['error' => 'Unknown tool: nope'],
                'isError' => true,
            ]
        );

        $invoker = new FacadeToolInvoker(facade: $facade);
        $encoded = $invoker->nope();

        $this->assertSame(['error' => 'Unknown tool: nope'], json_decode($encoded, true));

    }//end testInvokerWorksWithoutChannel()
}//end class
