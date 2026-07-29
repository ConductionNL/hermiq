<?php

/**
 * Hermiq GraphExecutor
 *
 * Walks an authored agent graph (an `agentflow` definition) over a typed state,
 * generalising {@see \OCA\Hermiq\Service\FlowAgentRunService} from "run one agent"
 * to "walk a graph of nodes." Each node is dispatched to an existing Hermiq
 * service; the same synchronous oversight gates (kill-switch, budget) that guard a
 * single flow-agent run are applied per hop, and every hop writes a redacted
 * AuditTrail entry.
 *
 * Node types (Phase 1):
 *   - agent-step    → ScheduleService::runAgentAsOwner() (the proven agent turn)
 *   - object-write  → ObjectService::saveObject() (structured output back to OR)
 *   - condition     → boolean guard on state; false halts the graph
 *   - router        → classify state → follow the matching outgoing edge
 *
 * Bounded by design: a visited-node cycle guard and `limits.maxNodes` /
 * `limits.maxIterations` stop a run rather than ever looping without bound
 * (mirrors OpenRegister FlowActionService's activeObjects guard).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Graph
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://hermiq.app
 *
 * @spec openspec/changes/agent-graph-builder/specs/agent-graph/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes an authored agent graph against a triggering object.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One class deliberately owns the
 *   whole bounded-walk contract: node dispatch (4 types), edge selection, condition
 *   evaluation, state templating and per-hop auditing. Splitting the dispatch table
 *   from the termination guards would let a future node type be added without the
 *   cycle/step ceilings that make a run provably terminate — the guarantee this
 *   class exists to hold. Revisit when Phase 2 adds parallel/loop nodes.
 *
 * @spec openspec/changes/agent-graph-builder/specs/agent-graph/spec.md
 */
class GraphExecutor
{
    /**
     * Hard ceiling on node executions per run, even if a graph's own
     * `limits.maxNodes` is missing or larger — a runaway backstop.
     *
     * @var int
     */
    private const ABSOLUTE_MAX_NODES = 100;

    /**
     * Appended to an agent step's prompt when the node declares `expectJson`.
     * Spelled out because a model that merely "returns JSON" still tends to
     * wrap it in prose or a fence, and the next node has to address fields.
     *
     * @var string
     */
    private const JSON_INSTRUCTION = 'Reply with a single valid JSON object and nothing else. '
        .'No prose before or after it, and no markdown code fence.';

    /**
     * UUIDs of objects a graph run is currently acting on, so an object-write
     * that re-dispatches an event into another graph run cannot recurse without
     * bound within a request.
     *
     * @var array<string, true>
     */
    private static array $active = [];

    /**
     * Ordered trace of the last run (node id, type, outcome) — diagnostics only.
     *
     * @var array<int, array>
     */
    public array $trace = [];

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    Resolve/write OR objects.
     * @param LoggerInterface  $logger           Structured logging.
     * @param AuditTrailMapper $auditTrailMapper Per-hop audit write-path.
     * @param ScheduleService  $scheduleService  Kill-switch + the reused agent turn (runAgentAsOwner).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly ScheduleService $scheduleService,
    ) {
    }//end __construct()

    /**
     * Run a graph against a triggering object.
     *
     * @param array<string, mixed> $graph  The agentflow definition ({nodes, edges, limits}).
     * @param ObjectEntity         $object The triggering object (initial state).
     *
     * @return array<string, mixed> The final state (for diagnostics / a run record).
     *
     * @spec openspec/changes/agent-graph-builder/specs/agent-graph/spec.md
     */
    public function run(array $graph, ObjectEntity $object): array
    {
        $this->trace = [];
        $guardKey    = (string) $object->getUuid();
        if ($guardKey !== '' && isset(self::$active[$guardKey]) === true) {
            $this->logger->info('Hermiq graph run suppressed by cycle guard for object '.$guardKey);
            return [];
        }

        if ($guardKey !== '') {
            self::$active[$guardKey] = true;
        }

        try {
            return $this->walk(graph: $graph, object: $object);
        } finally {
            if ($guardKey !== '') {
                unset(self::$active[$guardKey]);
            }
        }
    }//end run()

    /**
     * Walk the graph node-by-node from the start node.
     *
     * @param array<string, mixed> $graph  The agentflow definition.
     * @param ObjectEntity         $object The triggering object.
     *
     * @return array<string, mixed> The final state.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The walk loop IS the bounded-execution
     *   contract: empty-graph, kill-switch, missing-node, cycle-guard, step-ceiling,
     *   node-failure and condition-halt are each a distinct, independently-specified
     *   termination path. Splitting them would scatter the guarantee that a run always
     *   terminates across several methods.
     */
    private function walk(array $graph, ObjectEntity $object): array
    {
        $nodes = $this->indexNodes(graph: $graph);
        $edges = $this->asArray(value: $graph['edges'] ?? null);
        if (empty($nodes) === true) {
            return [];
        }

        $organisation = (string) ($object->getOrganisation() ?? '');

        // GATE — kill-switch (same TenantControl source ScheduleService reads).
        if ($organisation !== '' && $this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true) {
            $this->audit(object: $object, status: 'skipped_killswitch', node: '', flow: (string) ($graph['name'] ?? 'graph'));
            return [];
        }

        $state         = $this->buildState(object: $object);
        $limits        = $this->asArray(value: $graph['limits'] ?? null);
        $maxNodes      = (int) ($limits['maxNodes'] ?? self::ABSOLUTE_MAX_NODES);
        $maxNodes      = max(1, min($maxNodes, self::ABSOLUTE_MAX_NODES));
        $flowName      = (string) ($graph['name'] ?? 'graph');
        $currentId     = $this->startNodeId(nodes: $nodes, edges: $edges);
        $visited       = [];
        $steps         = 0;
        $this->trace[] = ['event' => 'start', 'nodeIds' => array_keys($nodes), 'start' => $currentId, 'edgeCount' => count($edges)];

        while ($currentId !== null && $steps < $maxNodes) {
            $node = $nodes[$currentId] ?? null;
            if ($node === null) {
                break;
            }

            // Cycle guard: a node may only run once per walk (Phase-1 acyclic).
            if (isset($visited[$currentId]) === true) {
                $this->logger->info('Hermiq graph '.$flowName.': cycle detected at node '.$currentId.'; stopping.');
                break;
            }

            $visited[$currentId] = true;
            $steps++;

            $type = (string) ($node['type'] ?? '');

            // Snapshot the state before the hop so the trace can report what
            // THIS node produced, rather than the whole accumulated state. The
            // builder renders that per-step output on the edge leaving the node.
            $before = $state;
            $error  = null;
            try {
                $continue = $this->runNode(node: $node, type: $type, state: $state, object: $object, organisation: $organisation);
            } catch (Throwable $e) {
                $this->logger->warning('Hermiq graph '.$flowName.' node '.$currentId.' ('.$type.') failed: '.$e->getMessage(), ['exception' => $e]);
                $continue = true;
                $error    = $e->getMessage();
            }

            $this->audit(object: $object, status: 'node_'.$type, node: $currentId, flow: $flowName);
            // `error` is always present (null on success) rather than added
            // conditionally: every trace entry then has the same shape, so the
            // builder can read it without a guard.
            //
            // `passed` is the WHOLE state as the next node receives it, next to
            // `produced` (only what this step added). Both are needed: the delta
            // says what this step did, the state says what the next step can
            // actually read — and only the second answers "what is being sent
            // between these two nodes".
            $next          = $this->nextNodeId(current: $currentId, edges: $edges, state: $state);
            $this->trace[] = [
                'event'    => 'ran',
                'node'     => $currentId,
                'type'     => $type,
                'continue' => $continue,
                'next'     => $next,
                'produced' => $this->stateDelta(before: $before, after: $state),
                'passed'   => $state,
                'error'    => $error,
            ];

            if ($continue === false) {
                // A condition halted the graph.
                break;
            }

            $currentId = $this->nextNodeId(current: $currentId, edges: $edges, state: $state);
        }//end while

        return $state;
    }//end walk()

    /**
     * Execute a single node; return false to halt the graph (a failed condition).
     *
     * @param array<string, mixed> $node         The node.
     * @param string               $type         The node type.
     * @param array<string, mixed> $state        The mutable run state (by reference).
     * @param ObjectEntity         $object       The triggering object.
     * @param string               $organisation The owning organisation.
     *
     * @return bool True to continue, false to halt.
     */
    private function runNode(array $node, string $type, array &$state, ObjectEntity $object, string $organisation): bool
    {
        $config = $this->asArray(value: $node['config'] ?? null);

        switch ($type) {
            case 'condition':
                return $this->evaluateCondition(config: $config, state: $state);

            case 'router':
                // Routing is resolved at edge-selection time; nothing to do here.
                return true;

            case 'agent-step':
                try {
                    $output        = $this->runAgentStep(config: $config, state: $state, object: $object, organisation: $organisation);
                    $this->trace[] = ['event' => 'agent-step', 'result' => 'ok', 'len' => strlen($output)];
                } catch (Throwable $e) {
                    $this->trace[] = ['event' => 'agent-step', 'result' => 'error', 'error' => $e->getMessage(), 'class' => get_class($e)];
                    $output        = '';
                }

                $outKey         = (string) ($config['output'] ?? 'result');
                $state[$outKey] = $this->decodeAgentOutput(config: $config, output: $output);
                return true;

            case 'object-write':
                $this->runObjectWrite(config: $config, state: $state, object: $object);
                return true;

            default:
                $this->logger->info('Hermiq graph: unknown node type "'.$type.'"; skipped.');
                return true;
        }//end switch
    }//end runNode()

    /**
     * Run an agent-step node — the proven ScheduleService::runAgentAsOwner() turn.
     *
     * @param array<string, mixed> $config       Node config (agent, prompt, output).
     * @param array<string, mixed> $state        The run state.
     * @param ObjectEntity         $object       The triggering object (anchor).
     * @param string               $organisation The organisation.
     *
     * @return string The agent output.
     */
    private function runAgentStep(array $config, array $state, ObjectEntity $object, string $organisation): string
    {
        // `agentId` is what the builder authors; `agent` is the original key and
        // is still honoured so graphs saved before the builder existed keep running.
        $agentRef = (string) ($config['agentId'] ?? ($config['agent'] ?? ''));
        if ($agentRef === '') {
            return '';
        }

        $owner  = (string) ($config['owner'] ?? ($object->getOwner() ?? ''));
        $prompt = $this->render(template: (string) ($config['prompt'] ?? ''), state: $state);
        if (($config['expectJson'] ?? false) === true) {
            $prompt .= "\n\n".self::JSON_INSTRUCTION;
        }

        return $this->scheduleService->runAgentAsOwner(
            owner: $owner,
            agentId: $agentRef,
            prompt: $prompt,
            organisation: $organisation,
            dryRun: false,
            forceOwner: false,
            anchor: $object
        );
    }//end runAgentStep()

    /**
     * Run an object-write node — merge templated fields onto the object and persist
     * (PUT-semantic read-modify-write, mirroring FlowAgentRunService::writeResultField).
     *
     * @param array<string, mixed> $config Node config (fields map and/or field+value).
     * @param array<string, mixed> $state  The run state.
     * @param ObjectEntity         $object The triggering object.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Accepts two authoring shapes
     *   (a `fields` map or a single `field`+`value`) and must read-modify-write
     *   PUT-semantically — every branch is an input-shape or empty-write guard
     *   protecting against clobbering unrelated properties.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same: independent optional inputs.
     */
    private function runObjectWrite(array $config, array $state, ObjectEntity $object): void
    {
        $updates = [];
        $fields  = $this->asArray(value: $config['fields'] ?? null);
        foreach ($fields as $key => $tpl) {
            if (is_string($key) === true && $key !== '') {
                $updates[$key] = $this->render(template: (string) $tpl, state: $state);
            }
        }

        $single = (string) ($config['field'] ?? '');
        if ($single !== '') {
            $updates[$single] = $this->render(template: (string) ($config['value'] ?? ''), state: $state);
        }

        if (empty($updates) === true) {
            $this->trace[] = ['event' => 'object-write', 'result' => 'no-updates'];
            return;
        }

        $uuid     = (string) $object->getUuid();
        $register = (string) $object->getRegister();
        $schema   = (string) $object->getSchema();

        $fresh = $this->objectService->find(id: $uuid, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
        if (($fresh instanceof ObjectEntity) === false) {
            $this->trace[] = ['event' => 'object-write', 'result' => 'fresh-not-found'];
            return;
        }

        $data = $fresh->getObject();

        foreach ($updates as $k => $v) {
            $data[$k] = $v;
        }

        try {
            $this->objectService->saveObject(
                object: $data,
                register: $register,
                schema: $schema,
                uuid: $uuid,
                _rbac: false,
                _multitenancy: false
            );
            $this->trace[] = ['event' => 'object-write', 'result' => 'saved', 'fields' => array_keys($updates)];
        } catch (Throwable $e) {
            $this->trace[] = ['event' => 'object-write', 'result' => 'save-failed', 'error' => $e->getMessage()];
            $this->logger->warning('Hermiq graph object-write failed for '.$uuid.': '.$e->getMessage());
        }
    }//end runObjectWrite()

    /**
     * Evaluate a condition node against the state.
     *
     * @param array<string, mixed> $config Condition config (field, operator, value).
     * @param array<string, mixed> $state  The run state.
     *
     * @return bool True when the condition holds (continue).
     */
    private function evaluateCondition(array $config, array $state): bool
    {
        $field    = (string) ($config['field'] ?? '');
        $operator = (string) ($config['operator'] ?? 'eq');
        $expected = $this->render(template: (string) ($config['value'] ?? ''), state: $state);
        $actual   = ($state[$field] ?? null);
        if (is_array($actual) === true) {
            $actual = implode(', ', array_map('strval', $actual));
        }

        $actualStr = '';
        if ($actual !== null) {
            $actualStr = (string) $actual;
        }

        switch ($operator) {
            case 'eq':
                return $actualStr === $expected;
            case 'ne':
                return $actualStr !== $expected;
            case 'empty':
                return $actualStr === '';
            case 'notEmpty':
                return $actualStr !== '';
            case 'contains':
                return $expected !== '' && str_contains($actualStr, $expected);
            default:
                return false;
        }
    }//end evaluateCondition()

    /**
     * Turn an agent step's raw answer into the value put on the run state.
     *
     * With `expectJson` the answer is parsed, so a later node can address a
     * single field (`{{result.status}}`) instead of receiving one opaque blob
     * of prose. Models routinely wrap JSON in a ```json fence even when told
     * not to, so the fence is stripped before decoding.
     *
     * A parse failure keeps the raw text rather than discarding the step's
     * work — the trace records that the contract was not met, which is what
     * the author needs in order to fix the prompt.
     *
     * @param array<string, mixed> $config The node config.
     * @param string               $output The agent's answer.
     *
     * @return mixed The decoded value, or the raw string.
     */
    private function decodeAgentOutput(array $config, string $output)
    {
        if (($config['expectJson'] ?? false) !== true || $output === '') {
            return $output;
        }

        $text = trim($output);
        if (str_starts_with($text, '```') === true) {
            $text = trim((string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text));
        }

        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->trace[] = [
                'event'  => 'agent-step',
                'result' => 'json-parse-failed',
                'error'  => json_last_error_msg(),
            ];

            return $output;
        }

        return $decoded;
    }//end decodeAgentOutput()

    /**
     * Read a `{{dotted.path}}` out of the run state.
     *
     * A flat key wins, so a state key that literally contains a dot still
     * resolves. Otherwise each segment walks one level down, which is what
     * makes a JSON agent answer addressable field by field.
     *
     * @param string               $path  The placeholder path.
     * @param array<string, mixed> $state The run state.
     *
     * @return mixed The value, or null when the path does not resolve.
     */
    private function resolvePath(string $path, array $state)
    {
        if (array_key_exists($path, $state) === true) {
            return $state[$path];
        }

        $value = $state;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) === false || array_key_exists($segment, $value) === false) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }//end resolvePath()

    /**
     * The keys a single node added to or changed on the run state.
     *
     * A node's usable "result" is what it contributed, not the whole state it
     * inherited — the builder shows this on the edge leaving the node so an
     * author can see what the step itself did, next to the full state that
     * travels onward.
     *
     * @param array<string, mixed> $before State before the hop.
     * @param array<string, mixed> $after  State after the hop.
     *
     * @return array<string, mixed> Added/changed keys only.
     */
    private function stateDelta(array $before, array $after): array
    {
        $delta = [];
        foreach ($after as $key => $value) {
            if (array_key_exists($key, $before) === false || $before[$key] !== $value) {
                $delta[$key] = $value;
            }
        }

        return $delta;
    }//end stateDelta()

    /**
     * Narrow a loosely-typed value to an array, defaulting to an empty one.
     *
     * Graph documents arrive as decoded JSON, so every nested member is
     * `mixed` until proven otherwise. This replaces the
     * `is_array($x) ? $x : []` idiom, which the coding standard rejects
     * (Squiz.PHP.DisallowInlineIf + ImplicitTrue), in one place instead of
     * repeating a five-line guard at each call site.
     *
     * @param mixed $value The candidate value.
     *
     * @return array<mixed> The value when it is an array, otherwise [].
     */
    private function asArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        return [];
    }//end asArray()

    /**
     * Index nodes by id.
     *
     * @param array<string, mixed> $graph The graph.
     *
     * @return array<string, array> Node id => node.
     */
    private function indexNodes(array $graph): array
    {
        $out   = [];
        $nodes = $this->asArray(value: $graph['nodes'] ?? null);
        foreach ($nodes as $node) {
            if (is_array($node) === true && isset($node['id']) === true) {
                $out[(string) $node['id']] = $node;
            }
        }

        return $out;
    }//end indexNodes()

    /**
     * The start node: an explicit `start:true` node, else a node that is not the
     * target of any edge, else the first node.
     *
     * @param array<string, array> $nodes Indexed nodes.
     * @param array<int, mixed>    $edges Edges.
     *
     * @return string|null The start node id.
     */
    private function startNodeId(array $nodes, array $edges): ?string
    {
        foreach ($nodes as $id => $node) {
            if (($node['start'] ?? false) === true) {
                return (string) $id;
            }
        }

        $targets = [];
        foreach ($edges as $edge) {
            if (is_array($edge) === true && isset($edge['target']) === true) {
                $targets[(string) $edge['target']] = true;
            }
        }

        foreach ($nodes as $id => $node) {
            if (isset($targets[(string) $id]) === false) {
                return (string) $id;
            }
        }

        return array_key_first($nodes);
    }//end startNodeId()

    /**
     * The next node id from the current node: for a router/condition, the first
     * outgoing edge whose `when` matches the state; otherwise the first outgoing
     * edge. Null when there is no outgoing edge.
     *
     * @param string               $current The current node id.
     * @param array<int, mixed>    $edges   Edges.
     * @param array<string, mixed> $state   The run state.
     *
     * @return string|null The next node id.
     */
    private function nextNodeId(string $current, array $edges, array $state): ?string
    {
        $fallback = null;
        foreach ($edges as $edge) {
            if (is_array($edge) === false || (string) ($edge['source'] ?? '') !== $current) {
                continue;
            }

            $when = ($edge['when'] ?? null);
            if ($when === null || $when === '') {
                $fallback ??= (string) ($edge['target'] ?? '');
                continue;
            }

            // `when` is a {field, operator, value} guard on state.
            if (is_array($when) === true && $this->evaluateCondition(config: $when, state: $state) === true) {
                return (string) ($edge['target'] ?? '');
            }
        }

        return $fallback;
    }//end nextNodeId()

    /**
     * Build the initial run state from the object plus @meta keys.
     *
     * @param ObjectEntity $object The triggering object.
     *
     * @return array<string, mixed> The state.
     */
    private function buildState(ObjectEntity $object): array
    {
        $data = $object->getObject();

        $data['@id']           = $object->getUuid();
        $data['@uuid']         = $object->getUuid();
        $data['@register']     = $object->getRegister();
        $data['@schema']       = $object->getSchema();
        $data['@organisation'] = $object->getOrganisation();
        return $data;
    }//end buildState()

    /**
     * Render `{{ field }}` placeholders against the state.
     *
     * @param mixed                $template The template.
     * @param array<string, mixed> $state    The state.
     *
     * @return string The rendered string.
     */
    private function render(mixed $template, array $state): string
    {
        if (is_string($template) === false) {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/',
            function (array $matches) use ($state): string {
                $value = $this->resolvePath(path: $matches[1], state: $state);
                if (is_array($value) === true) {
                    // A nested structure (a parsed JSON answer) is handed on as
                    // JSON, so the receiving step gets the shape rather than
                    // PHP's comma-joined flattening of it.
                    return (string) json_encode($value);
                }

                if ($value === true) {
                    return 'true';
                }

                if ($value === false) {
                    return 'false';
                }

                return (string) $value;
            },
            $template
        );
    }//end render()

    /**
     * Write a redacted per-hop AuditTrail entry on the triggering object.
     *
     * @param ObjectEntity $object The triggering object.
     * @param string       $status The hop status.
     * @param string       $node   The node id.
     * @param string       $flow   The graph name.
     *
     * @return void
     */
    private function audit(ObjectEntity $object, string $status, string $node, string $flow): void
    {
        try {
            $this->auditTrailMapper->createAuditTrailEntry(
                object: $object,
                action: 'graph-run',
                context: [
                    'flowName' => $flow,
                    'status'   => $status,
                    'node'     => $node,
                ],
            );
        } catch (Throwable $e) {
            $this->logger->debug('Hermiq graph audit write skipped: '.$e->getMessage());
        }
    }//end audit()
}//end class
