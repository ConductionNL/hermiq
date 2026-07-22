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

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\Engine\ToolGrantResolutionException;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the facade-backed tool loop.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-2
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
 */
class ToolLoopTest extends TestCase
{
    /**
     * Build a ToolLoop with real (stateless) governance collaborators — a real
     * `ToolGrantResolver`/`ToolSearchService`, and a mock `ApprovalService`/
     * `IAppConfig` defaulting the disclosure threshold to 30 (agent-tool-governance-and-disclosure)
     * so pre-existing small-function-count tests never trip progressive disclosure.
     *
     * @param ToolRegistryFacade $facade    The (mocked) facade.
     * @param int                $threshold Disclosure threshold override.
     *
     * @return ToolLoop
     */
    private function loop(ToolRegistryFacade $facade, int $threshold=30): ToolLoop
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturn($threshold);

        return new ToolLoop(
            $facade,
            new NullLogger(),
            new ToolGrantResolver(),
            new ToolSearchService(),
            $this->createMock(ApprovalService::class),
            $appConfig
        );

    }//end loop()

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

        $loop = $this->loop(facade: $facade);
        $this->assertSame([], $loop->listAgentFunctions(agent: null));

    }//end testNullAgentYieldsNoToolsWithoutFacadeCall()

    /**
     * An EMPTY whitelist means "all discovered [non-write] tools allowed":
     * listTools([]) is called and its full result returned (ADR-035 Decision 4
     * default) for every descriptor default-deny does not strip. Both fixture
     * ids here are read-classified (a `.search` derived id each), so neither is
     * stripped — see `testEmptyWhitelistPostFiltersDefaultDenyWithoutASecondFacadeCall`
     * for the stripping behaviour itself, and
     * `ToolGrantResolverTest::testEmptyGrantsAllowsAllExceptDerivedWritesAndFailsClosedOnHintlessNonDerivedIds()`
     * for the hermiq-prefer-tool-hints fail-closed case this fixture deliberately avoids.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
     */
    public function testEmptyWhitelistAllowsAllTools(): void
    {
        $all = [
            ['name' => 'decidesk_listMeetings', 'mcpId' => 'decidesk.meeting.search', 'description' => 'List', 'parameters' => []],
            ['name' => 'openregister_search', 'mcpId' => 'openregister.schemas.search', 'description' => 'Search', 'parameters' => []],
        ];

        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())
            ->method('listTools')
            ->with([])
            ->willReturn($all);

        $loop = $this->loop(facade: $facade);
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
            // A resolving grant set: this test is about the whitelist that REACHES
            // the facade, and an empty return would now (correctly) raise as
            // "grants matched nothing" before the assertion could matter.
            ->willReturn([['name' => 'decidesk_listMeetings']]);

        $loop = $this->loop(facade: $facade);
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
            ->willReturn([['name' => 'a_one']]);

        $loop = $this->loop(facade: $facade);
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

        $loop   = $this->loop(facade: $facade);
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
            ->willReturn([['name' => 'a_one']]);

        $loop = $this->loop(facade: $facade);
        $loop->listAgentFunctions(agent: $this->agent(tools: []), selectedTools: ['a.one']);

    }//end testSelectionBecomesWhitelistWhenAgentAllowsAll()

    /**
     * A wildcard grant fetches the full catalog to expand it, then queries the
     * facade AGAIN with the resolved (default-denied) id set — two facade calls,
     * never a raw wildcard string reaching listTools().
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-2
     */
    public function testWildcardGrantIsExpandedAgainstCatalogBeforeFacadeQuery(): void
    {
        $catalog  = [
            ['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search'],
            ['name' => 'pipelinq_lead_get', 'mcpId' => 'pipelinq.lead.get'],
            ['name' => 'pipelinq_lead_delete', 'mcpId' => 'pipelinq.lead.delete'],
        ];
        $resolved = [
            ['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search'],
            ['name' => 'pipelinq_lead_get', 'mcpId' => 'pipelinq.lead.get'],
        ];

        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->exactly(2))
            ->method('listTools')
            ->willReturnCallback(
                function (array $whitelist) use ($catalog, $resolved): array {
                    if ($whitelist === []) {
                        return $catalog;
                    }

                    sort($whitelist);
                    $this->assertSame(['pipelinq.lead.get', 'pipelinq.lead.search'], $whitelist);
                    return $resolved;
                }
            );

        $loop   = $this->loop(facade: $facade);
        $result = $loop->listAgentFunctions(agent: $this->agent(tools: ['pipelinq.lead.*']));

        $this->assertSame($resolved, $result);

    }//end testWildcardGrantIsExpandedAgainstCatalogBeforeFacadeQuery()

    /**
     * A wildcard grant that expands to NOTHING must never re-query the facade
     * with the resulting empty id list.
     *
     * Regression — privilege ESCALATION, the inverse of a silent zero: an empty
     * whitelist means "all tools allowed" to the facade, so passing an empty
     * RESOLVED set straight through turned a grant that matched nothing into a
     * grant of the ENTIRE catalog, destructive tools included. The wildcard
     * expanding to zero is exactly the case where that happens (the grant names a
     * schema whose derived verb ids the catalog does not carry), and it is
     * indistinguishable at the facade from a legitimate "all" request.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-grant-set-that-resolves-to-no-tools-fails-loudly
     */
    public function testWildcardGrantExpandingToNothingNeverRequeriesTheFacadeWithAnEmptyWhitelist(): void
    {
        // The catalog carries no `openregister.schema.*` derived ids at all, so
        // the wildcard expands to zero.
        $catalog = [
            ['name' => 'list_schemas'],
            ['name' => 'delete_schema'],
        ];

        $facade = $this->createMock(ToolRegistryFacade::class);
        // Exactly ONE call — fetching the catalog to expand against. A second
        // call would necessarily carry the empty (= "all") whitelist.
        $facade->expects($this->once())
            ->method('listTools')
            ->with([])
            ->willReturn($catalog);

        $loop = $this->loop(facade: $facade);

        $this->expectException(ToolGrantResolutionException::class);
        $loop->listAgentFunctions(agent: $this->agent(tools: ['openregister.schema.*']));

    }//end testWildcardGrantExpandingToNothingNeverRequeriesTheFacadeWithAnEmptyWhitelist()

    /**
     * Grants that were configured but match nothing raise, rather than degrading
     * to a silent text-only run.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-grant-set-that-resolves-to-no-tools-fails-loudly
     */
    public function testGrantsResolvingToNoToolsRaise(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('listTools')->willReturn([]);

        $loop = $this->loop(facade: $facade);

        // `openregister.schemas` (plural) is not a real id — the tool is
        // `openregister.schema`. The agent loses every capability it was given.
        $this->expectException(ToolGrantResolutionException::class);
        $this->expectExceptionMessageMatches('/openregister\.schemas/');
        $loop->listAgentFunctions(agent: $this->agent(tools: ['openregister.schemas']));

    }//end testGrantsResolvingToNoToolsRaise()

    /**
     * The `__none__` sentinel means "no tools ON PURPOSE" and must NOT raise —
     * the one case where an empty function list is correct.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-grant-set-that-resolves-to-no-tools-fails-loudly
     */
    public function testExplicitNoToolsSentinelDoesNotRaise(): void
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('listTools')->willReturn([]);

        $loop = $this->loop(facade: $facade);

        $this->assertSame(
            [],
            $loop->listAgentFunctions(agent: $this->agent(tools: [ToolGrantResolver::NO_TOOLS_SENTINEL]))
        );

    }//end testExplicitNoToolsSentinelDoesNotRaise()

    /**
     * An empty whitelist calls listTools([]) exactly ONCE (preserving the
     * legacy call contract) and post-filters the returned catalog to strip
     * classifiable derived write ids (default-deny) — AND (hermiq-prefer-tool-hints)
     * a hint-less, non-derived id (`hermiq.sendMail`) is now ALSO stripped
     * (fails closed), while a hint-carrying non-derived id (`hermiq.getStatus`,
     * `readOnlyHint:true`) survives because the hint classifies it as read.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-1
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
     */
    public function testEmptyWhitelistPostFiltersDefaultDenyWithoutASecondFacadeCall(): void
    {
        $catalog = [
            ['name' => 'pipelinq_lead_search', 'mcpId' => 'pipelinq.lead.search'],
            ['name' => 'pipelinq_lead_delete', 'mcpId' => 'pipelinq.lead.delete'],
            ['name' => 'hermiq_sendMail', 'mcpId' => 'hermiq.sendMail'],
            ['name' => 'hermiq_getStatus', 'mcpId' => 'hermiq.getStatus', 'readOnlyHint' => true],
        ];

        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())->method('listTools')->with([])->willReturn($catalog);

        $loop   = $this->loop(facade: $facade);
        $result = $loop->listAgentFunctions(agent: $this->agent(tools: []));

        $ids = array_column($result, 'mcpId');
        sort($ids);
        $this->assertSame(
            ['hermiq.getStatus', 'pipelinq.lead.search'],
            $ids,
            'hermiq.sendMail (hint-less, non-derived) must fail closed; hermiq.getStatus'
            .' (readOnlyHint:true) must survive on its declared hint.'
        );

    }//end testEmptyWhitelistPostFiltersDefaultDenyWithoutASecondFacadeCall()

    /**
     * Above the disclosure threshold, only the `hermiq.searchTools` descriptor
     * is returned — never the full resolved set.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-resolved-catalog-exceeds-the-disclosure-threshold
     */
    public function testDisclosureActivatesAboveThreshold(): void
    {
        $many = [];
        for ($i = 0; $i < 5; $i++) {
            $many[] = ['name' => 'tool_'.$i, 'mcpId' => 'app.tool'.$i];
        }

        $searchTools = [['name' => 'hermiq_searchTools', 'mcpId' => 'hermiq.searchTools']];

        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('listTools')->willReturnCallback(
            function (array $whitelist) use ($many, $searchTools): array {
                if ($whitelist === ['hermiq.searchTools']) {
                    return $searchTools;
                }

                return $many;
            }
        );

        $loop   = $this->loop(facade: $facade, threshold: 3);
        $result = $loop->listAgentFunctions(agent: $this->agent(tools: ['app.tool0', 'app.tool1', 'app.tool2', 'app.tool3', 'app.tool4']));

        $this->assertSame($searchTools, $result);

    }//end testDisclosureActivatesAboveThreshold()

    /**
     * Below the disclosure threshold, all resolved descriptors are returned —
     * the meta-tool is never substituted.
     *
     * @return void
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-small-catalog-does-not-trigger-disclosure
     */
    public function testDisclosureDoesNotActivateBelowThreshold(): void
    {
        $few = [
            ['name' => 'a_tool', 'mcpId' => 'app.a'],
            ['name' => 'b_tool', 'mcpId' => 'app.b'],
        ];

        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())->method('listTools')->with(['app.a', 'app.b'])->willReturn($few);

        $loop   = $this->loop(facade: $facade, threshold: 3);
        $result = $loop->listAgentFunctions(agent: $this->agent(tools: ['app.a', 'app.b']));

        $this->assertSame($few, $result);

    }//end testDisclosureDoesNotActivateBelowThreshold()

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
        $loop   = $this->loop(facade: $facade);

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
        $channel->onToolCall(
                function (array $payload) use (&$toolCalls): void {
                    $toolCalls[] = $payload;
                }
                );
        $channel->onToolResult(
                function (array $payload) use (&$results): void {
                    $results[] = $payload;
                }
                );

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

    /**
     * `buildFunctionInfos(..., dryRun: true)` produces an invoker that
     * neutralises a write-classified descriptor's call (run-replay-and-dry-run)
     * — the facade is never invoked, and the descriptor's own `mcpId` is used
     * for classification.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
     */
    public function testBuildFunctionInfosDryRunNeutralisesWriteTool(): void
    {
        $descriptor = ['name' => 'demo_create', 'description' => 'Create a thing', 'mcpId' => 'demo.schema.create'];

        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->never())->method('invokeTool');

        $loop  = $this->loop(facade: $facade);
        $infos = $loop->buildFunctionInfos(functions: [$descriptor], trace: new RunTraceCollector(), dryRun: true);

        $invoker = $infos[0]->instance;
        $encoded = $invoker->demo_create(name: 'x');
        $decoded = json_decode($encoded, true);

        $this->assertTrue($decoded['preview']);

    }//end testBuildFunctionInfosDryRunNeutralisesWriteTool()

    /**
     * `buildFunctionInfos(..., dryRun: true)` forwards each descriptor's
     * declared hints to the classifier — a hint-less/2-segment id with
     * `readOnlyHint: true` in its own descriptor is NOT neutralised.
     *
     * @return void
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
     */
    public function testBuildFunctionInfosDryRunForwardsDescriptorHints(): void
    {
        $descriptor = [
            'name'         => 'pipelinq_searchLeads',
            'description'  => 'Search leads',
            'mcpId'        => 'pipelinq.searchLeads',
            'readOnlyHint' => true,
        ];

        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->expects($this->once())->method('invokeTool')->willReturn(['result' => [], 'isError' => false]);

        $loop  = $this->loop(facade: $facade);
        $infos = $loop->buildFunctionInfos(functions: [$descriptor], dryRun: true);

        $infos[0]->instance->pipelinq_searchLeads(query: 'x');

    }//end testBuildFunctionInfosDryRunForwardsDescriptorHints()
}//end class
