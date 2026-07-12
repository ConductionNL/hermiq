<?php

/**
 * Hermiq Chat Tool Loop.
 *
 * Ported from `OCA\OpenRegister\Service\Chat\ToolManagementHandler`. The JSON-schema →
 * LLPhant `Parameter`/`FunctionInfo` conversion logic is preserved verbatim; the tool
 * SOURCE is re-pointed: instead of constructing/consulting OR's internal
 * `ToolRegistry` (and, transitively, `McpProviderBridge`), this class consumes ONLY
 * `OCA\OpenRegister\Service\Mcp\ToolRegistryFacade` — the documented public
 * cross-app contract (`listTools()` / `invokeTool()`, gate-27 / ADR-022) — injected
 * via DI.
 *
 * Whitelist semantics (task 3.2, ADR-035 Decision 4 as declared by
 * agent-engine-schemas): `Agent.tools` is a whitelist of `{appId}.{toolName}`
 * registry ids; an EMPTY array means "every discovered tool is allowed". NOTE this
 * deliberately inverts the ported original's behavior (where an agent with no
 * `tools` rows got NO tools) — the schema chunk already declared the new default
 * and the facade's `listTools([])` implements it. An agent-less chat still gets
 * no tools (null agent → empty list), unchanged.
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;

/**
 * Resolves an agent's allowed tool functions from the OR tool-registry facade
 * and converts them into LLPhant FunctionInfo objects.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 */
class ToolLoop
{
    /**
     * Constructor.
     *
     * @param ToolRegistryFacade $toolRegistryFacade OR's public tool read/invoke surface
     *                                               (the ONLY tool dependency — never
     *                                               ToolRegistry/McpProviderBridge directly).
     * @param LoggerInterface    $logger             Logger.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     */
    public function __construct(
        private readonly ToolRegistryFacade $toolRegistryFacade,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * List the LLPhant-shaped function descriptors an agent may call.
     *
     * Applies the `Agent.tools` whitelist (empty = allow all) and the caller's
     * per-request `$selectedTools` narrowing, then queries the facade.
     *
     * @param ObjectEntity|null $agent         Agent object (null = agent-less chat, no tools).
     * @param array             $selectedTools Registry ids selected for this request
     *                                         (empty = no narrowing).
     *
     * @return array<int, array<string, mixed>> Flattened LLPhant function descriptors
     *                                          (`name`, `description`, `parameters`,
     *                                          optional `mcpId`).
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-2
     */
    public function listAgentFunctions(?ObjectEntity $agent, array $selectedTools=[]): array
    {
        if ($agent === null) {
            return [];
        }

        $whitelist = $agent->getObject()['tools'] ?? [];
        if (is_array($whitelist) === false) {
            $whitelist = [];
        }

        // Legacy compatibility (ported from the original's candidate loop): agent
        // records from older eras stored bare ids ("objects") that resolve as
        // "openregister.{id}" registry ids. Expand such entries so both forms match.
        $whitelist = $this->expandLegacyIds(ids: $whitelist);
        $selected  = $this->expandLegacyIds(ids: $selectedTools);

        // Per-request narrowing. When the agent whitelist is empty ("all allowed")
        // the selection simply becomes the whitelist; when both are non-empty they
        // intersect. An intersection that comes up EMPTY means "nothing allowed" —
        // return [] explicitly, because passing an empty whitelist to listTools()
        // would mean "all" (the exact opposite).
        if (empty($selected) === false) {
            $bothSet = (empty($whitelist) === false);
            if ($bothSet === true) {
                $whitelist = array_values(array_intersect($whitelist, $selected));
            }

            if ($bothSet === false) {
                $whitelist = $selected;
            }

            if ($bothSet === true && empty($whitelist) === true) {
                $this->logger->info(
                    message: '[ToolLoop] Selected tools do not intersect the agent whitelist — no tools enabled',
                    context: [
                        'file'          => __FILE__,
                        'line'          => __LINE__,
                        'selectedTools' => count($selectedTools),
                    ]
                );
                return [];
            }
        }//end if

        $functions = $this->toolRegistryFacade->listTools(toolWhitelist: $whitelist);

        $this->logger->debug(
            message: '[ToolLoop] Resolved agent tool functions',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'whitelistSize' => count($whitelist),
                'functionCount' => count($functions),
            ]
        );

        return $functions;

    }//end listAgentFunctions()

    /**
     * Convert facade function descriptors into LLPhant FunctionInfo objects.
     *
     * Every FunctionInfo's `$instance` is a `FacadeToolInvoker`, so LLPhant's
     * `$instance->{$name}(...$args)` dispatch lands on
     * `ToolRegistryFacade::invokeTool()`. When `$channel` is non-null the invoker
     * additionally fans `tool_call`/`tool_result` frames out to it (absorbing
     * OR's `StreamingToolInstanceWrapper` — see FacadeToolInvoker).
     *
     * @param array                   $functions Flattened LLPhant function descriptors
     *                                           (from `listAgentFunctions()`).
     * @param StreamYieldChannel|null $channel   Optional streaming channel.
     * @param RunTraceCollector|null  $trace     Optional run-trace collector; threaded
     *                                           onto the shared `FacadeToolInvoker` so
     *                                           each tool call the LLM makes is timed
     *                                           as a `tool` step (run-trace-observability).
     *
     * @return array<int, FunctionInfo> FunctionInfo objects ready for `setTools()`.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ported descriptor-to-FunctionInfo
     * conversion kept structurally intact from the OR original for parity reviewability.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     */
    public function buildFunctionInfos(array $functions, ?StreamYieldChannel $channel=null, ?RunTraceCollector $trace=null): array
    {
        $invoker = new FacadeToolInvoker(facade: $this->toolRegistryFacade, channel: $channel, trace: $trace);
        $functionInfoObjects = [];

        foreach ($functions as $func) {
            if (is_array($func) === false || isset($func['name']) === false) {
                continue;
            }

            // Create parameters array.
            $parameters = [];
            $required   = [];

            if (($func['parameters']['properties'] ?? null) !== null) {
                foreach ($func['parameters']['properties'] as $paramName => $paramDef) {
                    $paramDefArray = [];
                    if (is_array($paramDef) === true) {
                        $paramDefArray = $paramDef;
                    }

                    $parameters[] = $this->schemaToParameter(
                        name: (string) $paramName,
                        def: $paramDefArray
                    );
                }
            }

            if (($func['parameters']['required'] ?? null) !== null) {
                $required = $func['parameters']['required'];
            }

            // LLPhant's FunctionInfo expects requiredParameters as Parameter
            // OBJECTS, not name strings (ToolFormatter reads $param->name). Map
            // the required names back to the Parameter objects built above.
            $requiredParameters = [];
            foreach ($parameters as $parameterObject) {
                if (in_array($parameterObject->name, $required, true) === true) {
                    $requiredParameters[] = $parameterObject;
                }
            }

            $functionInfoObjects[] = new FunctionInfo(
                $func['name'],
                $invoker,
                $func['description'] ?? '',
                $parameters,
                $requiredParameters
            );
        }//end foreach

        return $functionInfoObjects;

    }//end buildFunctionInfos()

    /**
     * Expand bare (dot-less) tool ids with the legacy `openregister.` prefix so
     * agent records from older schema eras keep matching registry ids.
     *
     * @param array $ids Raw whitelist/selection entries.
     *
     * @return array<int, string> Expanded id list (original ids preserved).
     */
    private function expandLegacyIds(array $ids): array
    {
        $expanded = [];
        foreach ($ids as $id) {
            if (is_string($id) === false || $id === '') {
                continue;
            }

            $expanded[] = $id;
            if (str_contains($id, '.') === false) {
                $expanded[] = 'openregister.'.$id;
            }
        }

        return array_values(array_unique($expanded));

    }//end expandLegacyIds()

    /**
     * Convert a single JSON-schema property into an LLPhant Parameter.
     *
     * LLPhant's formatters require `itemsOrProperties` to be either a STRING
     * (the element type of an array of scalars) or an array of Parameter
     * OBJECTS (an object's properties / an array of objects). Passing the raw
     * JSON-schema `items`/`properties` (associative arrays of schema fragments)
     * makes FunctionFormatter read `->name` on a string and throw. This builds
     * the correct shape, recursing one level for nested objects. (Ported
     * verbatim from ToolManagementHandler.)
     *
     * @param string              $name The property name.
     * @param array<string,mixed> $def  The JSON-schema fragment for the property.
     *
     * @return Parameter
     *
     * @SuppressWarnings(PHPMD.ElseExpression) Ported object/array/scalar branch
     * structure kept intact from the OR original (load-bearing LLPhant shape
     * conversion) for parity reviewability.
     */
    private function schemaToParameter(string $name, array $def): Parameter
    {
        $type        = $def['type'] ?? 'string';
        $description = $def['description'] ?? '';
        $enum        = $def['enum'] ?? [];
        $format      = $def['format'] ?? null;
        $itemsOrProperties = null;

        if ($type === 'object') {
            $properties = $this->propertiesToParameters(properties: ($def['properties'] ?? []));
            if (count($properties) === 0) {
                // Free-form object with no declared sub-properties (e.g. a
                // schema's `properties` map). LLPhant serialises an empty
                // object schema as "properties": [] (a JSON array), which
                // Ollama rejects with "Value looks like object, but can't find
                // closing '}'". Represent it as a JSON string the model fills.
                $type        = 'string';
                $description = $this->freeFormObjectDescription(description: $description);
            } else {
                $itemsOrProperties = $properties;
            }
        } else if ($type === 'array') {
            $items    = $def['items'] ?? [];
            $itemType = 'string';
            if (is_array($items) === true && isset($items['type']) === true) {
                $itemType = (string) $items['type'];
            }

            if ($itemType === 'object') {
                $properties = $this->propertiesToParameters(properties: ($items['properties'] ?? []));
                // Same empty-object guard for arrays of free-form objects.
                if (count($properties) === 0) {
                    $itemsOrProperties = 'string';
                } else {
                    $itemsOrProperties = $properties;
                }
            } else {
                // A scalar element type is passed as a plain string.
                $itemsOrProperties = $itemType;
            }
        }//end if

        return new Parameter($name, $type, $description, $enum, $format, $itemsOrProperties);

    }//end schemaToParameter()

    /**
     * Build the description used when a free-form object (no declared
     * sub-properties) is represented as a JSON string instead.
     *
     * @param string $description Original property description (may be empty).
     *
     * @return string Description guiding the model to pass a JSON object.
     */
    private function freeFormObjectDescription(string $description): string
    {
        if ($description === '') {
            return 'A JSON object.';
        }

        return ($description.' (pass as a JSON object).');

    }//end freeFormObjectDescription()

    /**
     * Convert a JSON-schema `properties` map into an array of Parameter objects.
     *
     * @param array<string,mixed> $properties The properties map (name => schema).
     *
     * @return Parameter[]
     */
    private function propertiesToParameters(array $properties): array
    {
        $out = [];
        foreach ($properties as $propName => $propDef) {
            $propDefArray = [];
            if (is_array($propDef) === true) {
                $propDefArray = $propDef;
            }

            $out[] = $this->schemaToParameter(
                name: (string) $propName,
                def: $propDefArray
            );
        }

        return $out;

    }//end propertiesToParameters()
}//end class
