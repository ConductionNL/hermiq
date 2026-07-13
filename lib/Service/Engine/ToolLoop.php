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
 * agent-tool-governance-and-disclosure adds two steps to `listAgentFunctions()`:
 * a grant-RESOLUTION step (`ToolGrantResolver` expands `{app}.{schema}.*`
 * wildcards and applies default-deny to write/destructive derived ids — see that
 * class) that runs BEFORE the facade is asked for descriptors, and a disclosure-
 * DECISION step that runs AFTER: when the resolved descriptor count exceeds
 * `IAppConfig('hermiq','tools.disclosureThreshold')`, only the `hermiq.searchTools`
 * meta-tool is returned and the full resolved set is registered on
 * `ToolSearchService` for deferred loading (design.md §"Progressive disclosure").
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
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-2
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCP\IAppConfig;
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
     * The `hermiq.searchTools` meta-tool's registry id (nc-native-tools provider).
     *
     * @var string
     */
    private const SEARCH_TOOLS_ID = 'hermiq.searchTools';

    /**
     * Default progressive-disclosure threshold (descriptor count) — see
     * proposal.md Open Questions.
     *
     * @var int
     */
    private const DEFAULT_DISCLOSURE_THRESHOLD = 30;

    /**
     * Constructor.
     *
     * @param ToolRegistryFacade          $toolRegistryFacade     OR's public tool read/invoke surface
     *                                                            (the ONLY tool dependency — never
     *                                                            ToolRegistry/McpProviderBridge
     *                                                            directly).
     * @param LoggerInterface             $logger                 Logger.
     * @param ToolGrantResolver           $grantResolver          Schema-scoped grant expansion + default-deny.
     * @param ToolSearchService           $toolSearchService      Per-run resolved-set registry + `searchTools` ranking.
     * @param ApprovalService             $approvalService        Human-approval gate (threaded onto
     *                                                            `FacadeToolInvoker` for un-granted
     *                                                            destructive invocations).
     * @param IAppConfig                  $appConfig              Reads `hermiq.tools.disclosureThreshold`.
     * @param GuardrailPolicyService|null $guardrailPolicyService Resolves the effective
     *                                                            GuardrailPolicy's per-tool auto/confirm/deny
     *                                                            classification (agent-guardrails), threaded
     *                                                            onto `FacadeToolInvoker` as a resolved
     *                                                            toolId→classification map. Nullable/optional
     *                                                            purely so existing test callers that omit it
     *                                                            see zero behavior change (every tool `auto`);
     *                                                            real DI always provides it.
     * @param RedactionService|null       $redactionService       Masks secrets/PII in a dry-run's
     *                                                            `would-have-called` tool-call arguments
     *                                                            before they reach the trace
     *                                                            (run-replay-and-dry-run), threaded onto
     *                                                            `FacadeToolInvoker`. Nullable/optional so
     *                                                            existing test callers that omit it see zero
     *                                                            behavior change (only consulted when a
     *                                                            caller actually passes `dryRun: true`); real
     *                                                            DI always provides it.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
     *   distinct injected collaborator, not a logic-bearing argument list (mirrors
     *   ApprovalService's precedent in this codebase).
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-2
     * @spec openspec/changes/agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
     * @spec openspec/changes/run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
     */
    public function __construct(
        private readonly ToolRegistryFacade $toolRegistryFacade,
        private readonly LoggerInterface $logger,
        private readonly ToolGrantResolver $grantResolver,
        private readonly ToolSearchService $toolSearchService,
        private readonly ApprovalService $approvalService,
        private readonly IAppConfig $appConfig,
        private readonly ?GuardrailPolicyService $guardrailPolicyService=null,
        private readonly ?RedactionService $redactionService=null
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

        $functions = $this->resolveFunctions(whitelist: $whitelist);

        $this->logger->debug(
            message: '[ToolLoop] Resolved agent tool functions',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'whitelistSize' => count($whitelist),
                'functionCount' => count($functions),
            ]
        );

        // Register the FULL resolved set for this run (searchTools ranking +
        // the approval gate's grant-membership check) BEFORE any disclosure
        // narrowing — see ToolSearchService's docblock.
        $this->toolSearchService->registerResolved(descriptors: $functions);

        return $this->applyDisclosure(functions: $functions);

    }//end listAgentFunctions()

    /**
     * Resolve the whitelist into concrete descriptors: a plain (non-wildcard,
     * non-empty) whitelist is passed straight through to the facade exactly as
     * before; an empty whitelist or one containing a `{app}.{schema}.*` grant
     * needs the full catalog first so `ToolGrantResolver` can expand it
     * (agent-tool-governance-and-disclosure task 1/2).
     *
     * @param array<int, string> $whitelist The legacy-expanded, selection-narrowed whitelist.
     *
     * @return array<int, array<string, mixed>> Flattened LLPhant function descriptors.
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-2
     */
    private function resolveFunctions(array $whitelist): array
    {
        $needsCatalog = ($whitelist === [] || $this->grantResolver->hasWildcardGrant(grants: $whitelist) === true);

        if ($needsCatalog === false) {
            return $this->toolRegistryFacade->listTools(toolWhitelist: $whitelist);
        }

        $catalog     = $this->toolRegistryFacade->listTools(toolWhitelist: []);
        $resolvedIds = $this->grantResolver->resolve(grants: $whitelist, catalog: $catalog);

        if ($whitelist === []) {
            // Preserve the single listTools([]) call contract for the legacy
            // "empty whitelist" path — post-filter the already-fetched catalog
            // rather than re-querying the facade with a concrete id list.
            return $this->filterDescriptorsByIds(descriptors: $catalog, allowedIds: $resolvedIds);
        }

        return $this->toolRegistryFacade->listTools(toolWhitelist: $resolvedIds);

    }//end resolveFunctions()

    /**
     * Keep only the descriptors whose id (`mcpId` or `name`) is in `$allowedIds`,
     * preserving original order.
     *
     * @param array<int, mixed>  $descriptors Full descriptor list. Typed loosely on purpose:
     *                                        these cross the OpenRegister tool-facade boundary,
     *                                        so each entry is re-checked below.
     * @param array<int, string> $allowedIds  Ids to keep.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterDescriptorsByIds(array $descriptors, array $allowedIds): array
    {
        $allowed = array_flip($allowedIds);

        $out = [];
        foreach ($descriptors as $descriptor) {
            if (is_array($descriptor) === false) {
                continue;
            }

            $id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
            if (is_string($id) === true && isset($allowed[$id]) === true) {
                $out[] = $descriptor;
            }
        }

        return $out;

    }//end filterDescriptorsByIds()

    /**
     * Progressive tool disclosure: above the configured threshold, return only
     * the `hermiq.searchTools` meta-tool descriptor instead of the full set (the
     * full set stays registered on `ToolSearchService` from `listAgentFunctions()`).
     *
     * @param array<int, array<string, mixed>> $functions The resolved descriptor list.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-resolved-catalog-exceeds-the-disclosure-threshold
     * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-a-small-catalog-does-not-trigger-disclosure
     */
    private function applyDisclosure(array $functions): array
    {
        $threshold = $this->appConfig->getValueInt('hermiq', 'tools.disclosureThreshold', self::DEFAULT_DISCLOSURE_THRESHOLD);

        if (count($functions) <= $threshold) {
            return $functions;
        }

        $searchToolsDescriptors = $this->toolRegistryFacade->listTools(toolWhitelist: [self::SEARCH_TOOLS_ID]);
        if ($searchToolsDescriptors === []) {
            // The meta-tool itself is not registered/reachable (e.g. not
            // granted) — fail open to the full set rather than leaving the
            // agent with zero callable tools.
            return $functions;
        }

        $this->logger->info(
            message: '[ToolLoop] Progressive tool disclosure active',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'resolvedCount' => count($functions),
                'threshold'     => $threshold,
            ]
        );

        return [$searchToolsDescriptors[0]];

    }//end applyDisclosure()

    /**
     * Convert facade function descriptors into LLPhant FunctionInfo objects.
     *
     * Every FunctionInfo's `$instance` is a `FacadeToolInvoker`, so LLPhant's
     * `$instance->{$name}(...$args)` dispatch lands on
     * `ToolRegistryFacade::invokeTool()`. When `$channel` is non-null the invoker
     * additionally fans `tool_call`/`tool_result` frames out to it (absorbing
     * OR's `StreamingToolInstanceWrapper` — see FacadeToolInvoker).
     *
     * @param array                   $functions    Flattened LLPhant function descriptors
     *                                              (from `listAgentFunctions()`).
     * @param StreamYieldChannel|null $channel      Optional streaming channel.
     * @param RunTraceCollector|null  $trace        Optional run-trace collector; threaded
     *                                              onto the shared `FacadeToolInvoker` so
     *                                              each tool call the LLM makes is timed
     *                                              as a `tool` step
     *                                              (run-trace-observability).
     * @param ObjectEntity|null       $agent        Agent object (agent-tool-governance-and-disclosure);
     *                                              threaded onto `FacadeToolInvoker` so the
     *                                              un-granted-destructive → approval-gate check and
     *                                              the `hermiq.searchTools` short-circuit know which
     *                                              agent/run they act for. Null (agent-less chat)
     *                                              simply disables both — a plain facade dispatch,
     *                                              unchanged.
     * @param string|null             $organisation The turn's organisation (agent-guardrails);
     *                                              resolved ONCE into the effective GuardrailPolicy's
     *                                              `toolPolicy` map and threaded onto the shared
     *                                              `FacadeToolInvoker`. Null/absent GuardrailPolicyService
     *                                              resolves to every tool `auto` (zero behavior change).
     * @param bool                    $dryRun       Whether this turn is a dry-run preview
     *                                              (run-replay-and-dry-run); threaded onto the
     *                                              shared `FacadeToolInvoker` along with each
     *                                              function's full descriptor (so its
     *                                              `scope`/`destructiveHint`/`readOnlyHint`, when
     *                                              set, informs the side-effect classification).
     *                                              False (every pre-existing caller) is
     *                                              byte-for-byte unchanged behavior.
     *
     * @return array<int, FunctionInfo> FunctionInfo objects ready for `setTools()`.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ported descriptor-to-FunctionInfo
     * conversion kept structurally intact from the OR original for parity reviewability.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
     * @spec openspec/changes/run-trace-observability/tasks.md#task-2-1
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
     * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-4
     * @spec openspec/changes/agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
     * @spec openspec/changes/run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
     */
    public function buildFunctionInfos(
        array $functions,
        ?StreamYieldChannel $channel=null,
        ?RunTraceCollector $trace=null,
        ?ObjectEntity $agent=null,
        ?string $organisation=null,
        bool $dryRun=false
    ): array {
        $agentId = null;
        if ($agent !== null) {
            $agentId = (string) $agent->getUuid();
        }

        $mcpIdByName       = [];
        $descriptorsByName = [];
        foreach ($functions as $func) {
            if (is_array($func) === true && isset($func['name']) === true) {
                $name = (string) $func['name'];
                $mcpIdByName[$name] = (string) ($func['mcpId'] ?? $func['name']);
                // Run-replay-and-dry-run: the full descriptor, keyed the same way, so the
                // dry-run classifier can consult a tool's declared hints when present.
                $descriptorsByName[$name] = $func;
            }
        }

        $invoker = new FacadeToolInvoker(
            facade: $this->toolRegistryFacade,
            channel: $channel,
            trace: $trace,
            toolSearchService: $this->toolSearchService,
            approvalService: $this->approvalService,
            agentId: $agentId,
            mcpIdByName: $mcpIdByName,
            toolPolicy: $this->resolveToolPolicy(organisation: $organisation),
            dryRun: $dryRun,
            descriptorsByName: $descriptorsByName,
            redactionService: $this->redactionService
        );
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
     * Resolve the effective GuardrailPolicy's `toolPolicy` list into a
     * `toolId => classification` map for O(1) lookups inside `FacadeToolInvoker`
     * (design.md Decision 6 — resolved ONCE per turn, not once per tool call).
     * `null`/absent GuardrailPolicyService or organisation resolves to an empty
     * map (every tool `auto` — zero behavior change).
     *
     * @param string|null $organisation The turn's organisation.
     *
     * @return array<string,string> toolId => classification.
     *
     * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
     */
    private function resolveToolPolicy(?string $organisation): array
    {
        if ($this->guardrailPolicyService === null) {
            return [];
        }

        $policy = $this->guardrailPolicyService->effectivePolicyFor(organisation: ($organisation ?? ''));

        // `effectivePolicyFor()`'s return shape guarantees `toolPolicy` and each entry's
        // `toolId`/`classification`, so no defensive re-checking is needed here.
        $map = [];
        foreach ($policy['toolPolicy'] as $entry) {
            $map[(string) $entry['toolId']] = (string) $entry['classification'];
        }

        return $map;

    }//end resolveToolPolicy()

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
