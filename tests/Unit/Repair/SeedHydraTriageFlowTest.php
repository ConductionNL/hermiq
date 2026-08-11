<?php

/**
 * Unit tests for the seeded triage agentflow (hydra-console-agent-leaves).
 *
 * The flow IS the deliverable here — it is data, not code — so these tests read the
 * seeded document back and assert the properties that make it safe: every step type
 * is a built-in engine node or Hermiq's own agent step (never a Hermiq-authored HTTP
 * step), and the branch cannot reach the command step on an empty triage result.
 *
 * ⚠️ THIS SUITE HAS NOW BEEN GREEN AGAINST BOTH SIDES OF THE SAME INVERSION, AND
 * WAS WRONG EACH TIME IN THE SAME WAY — it asserted where the work lived rather
 * than that the work was somewhere the engine reads:
 *
 *   before 2026-07-31  asserted `nodes[].type`   -> engine read edges -> flow inert
 *   2026-07-31         asserted NO node has type -> engine read edges -> correct that day
 *   2026-08-04         OR INVERTS (ADR-065): a NODE is the action, and
 *                      `FlowDefinitionBuilder::extractNodes()` THROWS on a node with
 *                      no type. The suite stayed green asserting exactly the shape
 *                      the engine now refuses — green precisely while the flow was
 *                      unbuildable.
 *   2026-08-12         migrated via the graph dual; the assertions swap sides again.
 *
 * 🔑 The durable lesson is not "read nodes". It is that a document-shape assertion is
 * only as good as its agreement with the CONSUMER, and the consumer is versioned. The
 * load-bearing test is now `testNoEdgeCarriesExecutableConfig()`, and its counterpart
 * `testEveryNodeDeclaresAType()` exists because a node with no type is the failure the
 * engine refuses — neither alone would have caught this.
 *
 * Since hermiq#89 a FAILED turn ends the run through the step's `onError` policy
 * instead of arriving at the gate as an empty string, so the branch no longer stands
 * between a failed LLM call and a pipeline command. It still stands between a
 * SUCCESSFUL turn that proposed nothing and that command, which is what
 * `testAnEmptyTriageResultCannotReachTheCommandStep()` pins.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedHydraTriageFlow;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the seeded triage flow's shape.
 *
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
 */
class SeedHydraTriageFlowTest extends TestCase
{

    /**
     * The step types this flow is permitted to contain: built-in OpenRegister
     * engine nodes, plus Hermiq's own agent step. Anything else — in particular an
     * HTTP step authored in Hermiq — is a spec violation.
     *
     * @var array<int, string>
     */
    private const PERMITTED_STEP_TYPES = [
        'hermiq.agent-step',
        'openregister.route',
        'openregister.end',
    ];


    /**
     * Build the repair step (its container is never reached by `flowObject()`).
     *
     * @return SeedHydraTriageFlow The step under test.
     */
    private function step(): SeedHydraTriageFlow
    {
        return new SeedHydraTriageFlow(
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class)
        );

    }//end step()

    /**
     * A container serving both dependencies the seed resolves lazily.
     *
     * 🔴 `OrganisationService` is not optional garnish here. Every flow read is
     * organisation-scoped, so a flow written without one is invisible to every
     * tenant AND blocks its own re-seed (hermiq#140). The step therefore refuses
     * to write when no organisation resolves — which means a container that does
     * not serve `OrganisationService` produces a SKIP, not a seed. Tests that
     * assert an insert must supply it.
     *
     * @param FlowMapper  $mapper  The flow mapper to serve.
     * @param string|null $orgUuid The default organisation UUID, or null to
     *                             simulate an instance where none resolves.
     *
     * @return ContainerInterface
     */
    private function containerWith(FlowMapper $mapper, ?string $orgUuid='org-default-uuid'): ContainerInterface
    {
        $organisations = $this->createMock(OrganisationService::class);
        $organisations->method('getDefaultOrganisationUuid')->willReturn($orgUuid);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($mapper, $organisations) {
                if ($id === FlowMapper::class) {
                    return $mapper;
                }

                if ($id === OrganisationService::class) {
                    return $organisations;
                }

                throw new RuntimeException('not available in this test: '.$id);
            }
        );

        return $container;

    }//end containerWith()


    /**
     * The flow's edges, keyed by id.
     *
     * @return array<string, array> The edges.
     */
    private function edges(): array
    {
        $edges = [];
        foreach ($this->step()->flowObject()['edges'] as $edge) {
            $edges[$edge['id']] = $edge;
        }

        return $edges;

    }//end edges()


    /**
     * The flow's nodes, keyed by id — which is where the WORK lives (ADR-065).
     *
     * @return array<string, array> The nodes.
     */
    private function nodes(): array
    {
        $nodes = [];
        foreach ($this->step()->flowObject()['nodes'] as $node) {
            $nodes[$node['id']] = $node;
        }

        return $nodes;

    }//end nodes()


    /**
     * The ids of the nodes one node's outgoing edges reach.
     *
     * @param string $nodeId The source node.
     *
     * @return array<int, string> The reachable node ids.
     */
    private function targetsOf(string $nodeId): array
    {
        $targets = [];
        foreach ($this->step()->flowObject()['edges'] as $edge) {
            if (in_array($nodeId, (array) $edge['from'], true) === false) {
                continue;
            }

            foreach ((array) $edge['to'] as $target) {
                $targets[] = $target;
            }
        }

        return $targets;

    }//end targetsOf()


    /**
     * The flow declares its trigger the way OpenRegister's engine matches it:
     * event plus register plus schema.
     *
     * The matcher is `OCA\OpenRegister\Service\Flow\FlowLocator::flowsForTrigger()`,
     * which reads `FlowMapper::findByTrigger($trigger, $register, $schema)` — the
     * `trigger` / `trigger_register` / `trigger_schema` columns of OpenRegister's
     * native flow store. It is NOT `HermiqFlowResolver`, which this docblock used
     * to name: the per-app `IFlowResolver` / `FlowResolverRegistry` layer existed
     * only because flows were once OpenRegister OBJECTS in a per-app register, and
     * flow-engine-unification deleted the whole layer along with Hermiq's copy of
     * it. Hermiq owns no flow resolver, controller or execution service.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-a-new-finding-triggers-the-seeded-triage-flow
     */
    public function testTheFlowDeclaresItsTrigger(): void
    {
        $flow = $this->step()->flowObject();

        $this->assertSame(SeedHydraTriageFlow::FLOW_NAME, $flow['name']);
        $this->assertSame('object.created', $flow['trigger']);
        $this->assertSame('hydra', $flow['triggerRegister']);
        $this->assertSame('finding', $flow['triggerSchema']);

    }//end testTheFlowDeclaresItsTrigger()


    /**
     * The flow ships DISABLED and unowned: a trigger fires with no acting user, so
     * enabling it is the deliberate human act that supplies the owner an
     * attributable run needs. A repair step running as no one cannot honestly
     * claim to be that person.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-an-unresolvable-owner-blocks-dispatch
     */
    public function testTheFlowShipsDisabledAndUnowned(): void
    {
        $flow = $this->step()->flowObject();

        $this->assertFalse($flow['enabled']);
        $this->assertSame('', $flow['owner']);

    }//end testTheFlowShipsDisabledAndUnowned()


    /**
     * No EDGE carries `type` or `config` — an edge is sequence, and the engine
     * refuses a document where one carries behaviour.
     *
     * `FlowDefinitionBuilder::refuseLegacyShape()` throws on any edge with a
     * non-empty `type`, and refuses rather than reinterprets on purpose: "a
     * half-migrated flow would run, skip the step nobody claimed, and report
     * success". So this is not a style rule — a single typed edge makes the whole
     * flow unbuildable.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
     */
    public function testNoEdgeCarriesExecutableConfig(): void
    {
        foreach ($this->step()->flowObject()['edges'] as $edge) {
            $this->assertArrayNotHasKey('type', $edge, "Edge '{$edge['id']}' carries a type the engine refuses.");
            $this->assertArrayNotHasKey('config', $edge, "Edge '{$edge['id']}' carries config the engine never reads.");
        }

    }//end testNoEdgeCarriesExecutableConfig()


    /**
     * Every node declares a `type` — the other half of the same contract.
     *
     * `FlowDefinitionBuilder::extractNodes()` throws on a node without one,
     * because "a node is the action that runs, so a node without a type is a step
     * that does nothing while reporting success". This is the assertion whose
     * ABSENCE let the suite stay green from 2026-08-04 while the seeded flow could
     * not be lowered to a Definition at all.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
     */
    public function testEveryNodeDeclaresAType(): void
    {
        $nodes = $this->step()->flowObject()['nodes'];
        $this->assertNotEmpty($nodes, 'A flow with no nodes executes nothing.');

        foreach ($nodes as $node) {
            $this->assertArrayHasKey('type', $node, "Node '{$node['id']}' declares no type; the engine refuses it.");
            $this->assertNotSame('', trim((string) $node['type']), "Node '{$node['id']}' has an empty type.");
        }

    }//end testEveryNodeDeclaresAType()


    /**
     * Every step is a built-in engine node or `hermiq.agent-step` — none opens an
     * HTTP client from Hermiq code. And at least one node does work, so the flow
     * is not a graph of pure pass-throughs.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-the-flow-contains-no-hermiq-authored-http-step
     */
    public function testEveryStepIsBuiltInOrTheAgentStep(): void
    {
        $typed = 0;
        foreach ($this->nodes() as $node) {
            if (isset($node['type']) === false) {
                continue;
            }

            $typed++;
            $this->assertContains($node['type'], self::PERMITTED_STEP_TYPES);
        }

        $this->assertGreaterThan(0, $typed, 'No node carries a type — the flow would do nothing.');

    }//end testEveryStepIsBuiltInOrTheAgentStep()


    /**
     * The router's ONLY rule routes to the command step, and it fires only when the
     * agent actually proposed a label; everything else falls to the no-result stop.
     *
     * A turn that succeeds but proposes nothing leaves `json.triage.label` empty,
     * so the rule is false — which is the whole point of the branch. (A turn that
     * FAILS no longer arrives here at all; see the class docblock.)
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-an-empty-agent-step-result-never-reaches-the-command-step
     */
    public function testAnEmptyTriageResultCannotReachTheCommandStep(): void
    {
        $gate = $this->nodes()['gate'];

        $this->assertSame('openregister.route', $gate['type']);
        $this->assertSame('no-result-stop', $gate['config']['default']);
        $this->assertCount(1, $gate['config']['rules']);

        $rule = $gate['config']['rules'][0];
        $this->assertSame('command-stop', $rule['output']);
        $this->assertSame(['!!' => ['var' => 'json.triage.label']], $rule['condition']);

    }//end testAnEmptyTriageResultCannotReachTheCommandStep()


    /**
     * With no command step wired up — the state today — the command branch STOPS
     * with the proposed label already recorded on the run's items, and never
     * degrades into writing anything.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-the-command-node-being-unavailable-does-not-fail-open
     */
    public function testTheCommandBranchStopsWhileNoCommandStepIsWiredUp(): void
    {
        $command = $this->nodes()['command-stop'];

        // `openregister.end`, not the `openregister.stop` alias: `EndNode::getId()`
        // is the canonical id and `FlowNodeRegistry:76` only aliases the old
        // spelling, which hydra#533 moved all eleven of its flows off of "before
        // the alias expires".
        $this->assertSame('openregister.end', $command['type']);
        $this->assertFalse($command['config']['error']);
        $this->assertStringContainsString('No forge write was attempted', $command['config']['message']);

    }//end testTheCommandBranchStopsWhileNoCommandStepIsWiredUp()


    /**
     * Every endpoint names a declared node, and every router output names a node
     * the ROUTING NODE ITSELF reaches through one of its own outgoing edges.
     *
     * The second half is the sharp one, and the inversion MOVED WHAT IT COMPARES
     * AGAINST without changing a word of its intent. `FlowEngine::advanceItems()`
     * distributes items only to the places on the firing transition's own output
     * list, and post-inversion those places are the TARGET NODE IDS —
     * `FlowGraph::inPlace()` returns the node id unprefixed for exactly this
     * reason, and `FlowItemPlacement::itemsForOutput()` compares an item's tag
     * against that place name. So an output still naming the old place
     * (`command`, `no-result`) matches nothing, and every routed item is dropped
     * into an empty branch with no error at all.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
     */
    public function testEveryEndpointAndRouterOutputNamesAReachablePlace(): void
    {
        $flow = $this->step()->flowObject();
        $ids  = array_column($flow['nodes'], 'id');

        foreach ($flow['edges'] as $edge) {
            foreach (array_merge((array) $edge['from'], (array) $edge['to']) as $endpoint) {
                $this->assertContains($endpoint, $ids);
            }
        }

        $gate    = $this->nodes()['gate'];
        $targets = $this->targetsOf('gate');

        $this->assertNotEmpty($targets, 'The routing node reaches nothing at all.');
        $this->assertContains($gate['config']['default'], $targets);
        foreach ($gate['config']['rules'] as $rule) {
            $this->assertContains($rule['output'], $targets);
        }

    }//end testEveryEndpointAndRouterOutputNamesAReachablePlace()


    /**
     * The agent step asks for JSON, so the branch has a field to read rather than
     * prose to guess at — and it names its agent by UUID or not at all.
     *
     * `AgentMapper::findByUuid()` matches the `uuid` COLUMN, so the seeded agent's
     * NAME — which this flow used to carry — resolves to nothing. An empty string
     * is the honest fallback when the agent cannot be found at seed time: it is
     * refused at both validate and execute time, where a name is not.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
     */
    public function testTheAgentStepExpectsJsonAndNamesItsAgentByUuidOrNotAtAll(): void
    {
        $triage = $this->nodes()['triage'];

        $this->assertSame('hermiq.agent-step', $triage['type']);
        $this->assertTrue($triage['config']['expectJson']);
        $this->assertSame(SeedHydraTriageFlow::TRIAGE_OUTPUT_KEY, $triage['config']['output']);

        $agentId = $triage['config']['agentId'];
        if ($agentId !== '') {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $agentId,
                'An agent step must name its agent by uuid; a display name never resolves.'
            );
        }

    }//end testTheAgentStepExpectsJsonAndNamesItsAgentByUuidOrNotAtAll()


    /**
     * 🔴 The WRITE path. Every test above this one reads `flowObject()`, which
     * is a pure array builder — so the whole file could pass with `run()`
     * throwing on its first line, and it did: `/api/flows?app=hermiq` returned
     * `{"results":[],"total":0}` on a clean install (hermiq CI run
     * 30878205902), while the `agent` seed in the same `<install>` block
     * succeeded. The flow store rewrite (#134) shipped nine hours earlier with
     * no coverage of its own write.
     *
     * A seed's contract is that it WRITES. That is what this asserts.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
     */
    public function testRunInsertsTheFlowWhenTheStoreIsEmpty(): void
    {
        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findAllFlows')->willReturn([]);
        $mapper->expects($this->once())->method('insert');

        $container = $this->containerWith($mapper);

        $step = new SeedHydraTriageFlow(
            container: $container,
            logger: $this->createMock(LoggerInterface::class)
        );

        $step->run($this->createMock(IOutput::class));

    }//end testRunInsertsTheFlowWhenTheStoreIsEmpty()


    /**
     * 🔑 NEGATIVE CONTROL for the test above: the seed is idempotent by name,
     * so a store that already holds the flow must NOT be written to. Without
     * this, `testRunInsertsTheFlowWhenTheStoreIsEmpty()` would also pass if
     * `run()` inserted unconditionally.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
     */
    public function testRunDoesNotInsertWhenTheFlowIsAlreadyPresent(): void
    {
        $existing = $this->createMock(Flow::class);
        $existing->method('__call')->willReturn(SeedHydraTriageFlow::FLOW_NAME);

        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findAllFlows')->willReturn([$existing]);
        $mapper->expects($this->never())->method('insert');

        $container = $this->containerWith($mapper);

        $step = new SeedHydraTriageFlow(
            container: $container,
            logger: $this->createMock(LoggerInterface::class)
        );

        $step->run($this->createMock(IOutput::class));

    }//end testRunDoesNotInsertWhenTheFlowIsAlreadyPresent()

    /**
     * 🔴 A FAILED seed records the exception class and message where a log tail
     * cannot discard it.
     *
     * This step has been silently writing nothing on clean installs
     * (hermiq#140). It logged the failure every time — and it made no
     * difference, because CI keeps a 50-line log tail and the install output is
     * thousands of lines earlier. Two separate investigations narrowed it only
     * as far as "something in here threw".
     *
     * The breadcrumb is what turns the next occurrence into a diagnosis:
     * `occ config:app:get hermiq hydra_triage_flow_seed_detail`. The CLASS is
     * asserted as well as the message, because "missing table", "constraint
     * violation" and "container resolution" are three different bugs that
     * produce three similar sentences.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
     */
    public function testAFailedSeedRecordsTheExceptionWhereItCanBeRead(): void
    {
        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findAllFlows')->willReturn([]);
        $mapper->method('insert')->willThrowException(
            new RuntimeException('an undiagnosable install-time failure')
        );

        $container = $this->containerWith($mapper);

        $recorded = [];
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) use (&$recorded): bool {
                $recorded[$key] = $value;
                return true;
            }
        );

        $step = new SeedHydraTriageFlow(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
            appConfig: $appConfig
        );

        // Must NOT throw — a failed seed may not break the install it is part of.
        $step->run($this->createMock(IOutput::class));

        $this->assertSame('failed', $recorded[SeedHydraTriageFlow::OUTCOME_KEY] ?? null);
        $this->assertStringContainsString(
            RuntimeException::class,
            $recorded[SeedHydraTriageFlow::OUTCOME_DETAIL_KEY] ?? '',
            'The exception CLASS must be recorded — the message alone has twice failed to identify this bug.'
        );
        $this->assertStringContainsString(
            'an undiagnosable install-time failure',
            $recorded[SeedHydraTriageFlow::OUTCOME_DETAIL_KEY] ?? ''
        );

    }//end testAFailedSeedRecordsTheExceptionWhereItCanBeRead()

    /**
     * 🔴 THE CONTROL. A SUCCESSFUL seed records `seeded`, not `failed`.
     *
     * Without this, the test above passes on an implementation that writes
     * "failed" unconditionally — which would make the breadcrumb worse than
     * useless, since it would accuse a working install.
     *
     * @return void
     */
    public function testASuccessfulSeedRecordsSeeded(): void
    {
        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findAllFlows')->willReturn([]);

        $container = $this->containerWith($mapper);

        $recorded = [];
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) use (&$recorded): bool {
                $recorded[$key] = $value;
                return true;
            }
        );

        $step = new SeedHydraTriageFlow(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
            appConfig: $appConfig
        );

        $step->run($this->createMock(IOutput::class));

        $this->assertSame('seeded', $recorded[SeedHydraTriageFlow::OUTCOME_KEY] ?? null);

    }//end testASuccessfulSeedRecordsSeeded()

    /**
     * 🔴 The seeded flow carries the DEFAULT ORGANISATION (hermiq#140).
     *
     * Every flow read is organisation-scoped: `FlowService::findAll()` resolves
     * the caller's active organisation and `FlowMapper::findAllFlows()` adds
     * `WHERE organisation = :org`. A row written with a NULL organisation
     * therefore matches nothing and is invisible to every tenant — the write
     * succeeds and the flow may as well not exist, which is exactly how this
     * shipped silently for weeks.
     *
     * Ownerless stays deliberate (enabling supplies the owner). Org-less was
     * the bug.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
     */
    public function testTheSeededFlowCarriesTheDefaultOrganisation(): void
    {
        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findAllFlows')->willReturn([]);

        $written = null;
        $mapper->method('insert')->willReturnCallback(
            function (Flow $flow) use (&$written): Flow {
                $written = $flow;
                return $flow;
            }
        );

        $step = new SeedHydraTriageFlow(
            container: $this->containerWith($mapper, 'org-default-uuid'),
            logger: $this->createMock(LoggerInterface::class)
        );

        $step->run($this->createMock(IOutput::class));

        $this->assertNotNull($written, 'the flow must be written');
        $this->assertSame(
            'org-default-uuid',
            $written->getOrganisation(),
            'Without an organisation the row is invisible to every tenant — every flow read is org-scoped.'
        );
        $this->assertNull($written->getOwner(), 'ownerless stays deliberate: enabling supplies the owner');
        $this->assertFalse($written->getEnabled(), 'the flow still ships disabled');

    }//end testTheSeededFlowCarriesTheDefaultOrganisation()

    /**
     * 🔴 With NO resolvable organisation the step writes NOTHING.
     *
     * This is the load-bearing half. An absent flow is recoverable — the next
     * install or upgrade seeds it. An org-less orphan is NOT: it is invisible
     * to every tenant AND the step's own idempotency check reads the mapper
     * directly, finds it, and concludes "already present", so it blocks its own
     * re-seed forever while reporting success.
     *
     * Refusing to write is therefore strictly better than writing a row nobody
     * can reach.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
     */
    public function testNoResolvableOrganisationWritesNothingRatherThanAnOrphan(): void
    {
        $mapper = $this->createMock(FlowMapper::class);
        $mapper->method('findAllFlows')->willReturn([]);
        $mapper->expects($this->never())->method('insert');

        $recorded = [];
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) use (&$recorded): bool {
                $recorded[$key] = $value;
                return true;
            }
        );

        $step = new SeedHydraTriageFlow(
            container: $this->containerWith($mapper, null),
            logger: $this->createMock(LoggerInterface::class),
            appConfig: $appConfig
        );

        $step->run($this->createMock(IOutput::class));

        $this->assertSame(
            'unavailable',
            $recorded[SeedHydraTriageFlow::OUTCOME_KEY] ?? null,
            'The breadcrumb must say the seed was skipped, not that it succeeded.'
        );
        $this->assertStringContainsString(
            'organisation',
            $recorded[SeedHydraTriageFlow::OUTCOME_DETAIL_KEY] ?? '',
            'and it must say WHY, so the next reader is not left guessing again'
        );

    }//end testNoResolvableOrganisationWritesNothingRatherThanAnOrphan()
}//end class
