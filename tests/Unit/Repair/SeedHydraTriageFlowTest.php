<?php

/**
 * Unit tests for the seeded triage agentflow (hydra-console-agent-leaves).
 *
 * The flow IS the deliverable here — it is data, not code — so these tests read the
 * seeded document back and assert the two properties that make it safe: every node
 * type is a built-in engine node or Hermiq's own agent step (never a
 * Hermiq-authored HTTP step), and the branch cannot reach the command step on an
 * empty triage result.
 *
 * Since hermiq#436 a FAILED turn ends the run through the step's `onError` policy
 * instead of arriving here as an empty string, so the branch no longer stands
 * between a failed LLM call and a pipeline command. It still stands between a
 * SUCCESSFUL turn that proposed nothing and that command, which is the case the
 * second test pins; a refactor removing it would still be a real regression.
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

use OCA\Hermiq\Repair\SeedHydraTriageAgent;
use OCA\Hermiq\Repair\SeedHydraTriageFlow;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the seeded triage flow's shape.
 *
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
 */
class SeedHydraTriageFlowTest extends TestCase
{

    /**
     * The node types this flow is permitted to contain: built-in OpenRegister
     * engine nodes, plus Hermiq's own agent step. Anything else — in particular an
     * HTTP step authored in Hermiq — is a spec violation.
     *
     * @var array<int, string>
     */
    private const PERMITTED_NODE_TYPES = [
        'hermiq.agent-step',
        'openregister.route',
        'openregister.stop',
    ];

    /**
     * Build the repair step (its container is never reached by `flowObject()`).
     *
     * @return SeedHydraTriageFlow
     */
    private function step(): SeedHydraTriageFlow
    {
        return new SeedHydraTriageFlow(
            container: $this->createMock(ContainerInterface::class),
            logger: $this->createMock(LoggerInterface::class)
        );

    }//end step()

    /**
     * The flow declares its trigger the way `HermiqFlowResolver::flowsForTrigger()`
     * matches it: event plus register plus schema.
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
     * Every node is a built-in engine node or `hermiq.agent-step` — none opens an
     * HTTP client from Hermiq code.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-the-flow-contains-no-hermiq-authored-http-step
     */
    public function testEveryNodeIsBuiltInOrTheAgentStep(): void
    {
        foreach ($this->step()->flowObject()['nodes'] as $node) {
            $this->assertContains($node['type'], self::PERMITTED_NODE_TYPES);
        }

    }//end testEveryNodeIsBuiltInOrTheAgentStep()

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
        $nodes = [];
        foreach ($this->step()->flowObject()['nodes'] as $node) {
            $nodes[$node['id']] = $node;
        }

        $gate = $nodes['gate'];
        $this->assertSame('openregister.route', $gate['type']);
        $this->assertSame('no-result', $gate['config']['default']);
        $this->assertCount(1, $gate['config']['rules']);

        $rule = $gate['config']['rules'][0];
        $this->assertSame('command', $rule['output']);
        $this->assertSame(['!!' => ['var' => 'json.triage.label']], $rule['condition']);

    }//end testAnEmptyTriageResultCannotReachTheCommandStep()

    /**
     * With the OpenConnector-backed command node absent — the state today — the
     * command branch STOPS with the proposed label already recorded on the run's
     * items, and never degrades into writing anything.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#scenario-the-command-node-being-unavailable-does-not-fail-open
     */
    public function testTheCommandBranchStopsWhileTheCommandNodeIsAbsent(): void
    {
        $nodes = [];
        foreach ($this->step()->flowObject()['nodes'] as $node) {
            $nodes[$node['id']] = $node;
        }

        $this->assertSame('openregister.stop', $nodes['command']['type']);
        $this->assertFalse($nodes['command']['config']['error']);
        $this->assertStringContainsString('No forge write was attempted', $nodes['command']['config']['message']);

    }//end testTheCommandBranchStopsWhileTheCommandNodeIsAbsent()

    /**
     * Router outputs must equal TARGET NODE IDS: the engine delivers an item only
     * to the place matching the tag the router put on it, so an output naming a
     * node that no edge reaches would silently drop every item.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
     */
    public function testEveryEdgeAndRouterOutputNamesAKnownNode(): void
    {
        $flow  = $this->step()->flowObject();
        $ids   = array_column($flow['nodes'], 'id');
        $gate  = null;

        foreach ($flow['nodes'] as $node) {
            if ($node['id'] === 'gate') {
                $gate = $node;
            }
        }

        foreach ($flow['edges'] as $edge) {
            $this->assertContains($edge['source'], $ids);
            $this->assertContains($edge['target'], $ids);
        }

        $this->assertContains($gate['config']['default'], $ids);
        foreach ($gate['config']['rules'] as $rule) {
            $this->assertContains($rule['output'], $ids);
        }

    }//end testEveryEdgeAndRouterOutputNamesAKnownNode()

    /**
     * The agent step names the seeded agent and asks for JSON, so the branch has a
     * field to read rather than prose to guess at.
     *
     * @return void
     *
     * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-object-leaf/spec.md#requirement-the-triage-loop-is-a-seeded-agentflow-not-bespoke-code
     */
    public function testTheAgentStepNamesTheSeededAgentAndExpectsJson(): void
    {
        $triage = null;
        foreach ($this->step()->flowObject()['nodes'] as $node) {
            if ($node['type'] === 'hermiq.agent-step') {
                $triage = $node;
            }
        }

        $this->assertNotNull($triage);
        $this->assertSame(SeedHydraTriageAgent::AGENT_NAME, $triage['config']['agentId']);
        $this->assertTrue($triage['config']['expectJson']);
        $this->assertSame(SeedHydraTriageFlow::TRIAGE_OUTPUT_KEY, $triage['config']['output']);

    }//end testTheAgentStepNamesTheSeededAgentAndExpectsJson()
}//end class
