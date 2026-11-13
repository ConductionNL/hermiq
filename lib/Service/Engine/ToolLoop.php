<?php

/**
 * Hermiq Chat Tool Loop.
 *
 * Ported from `OCA\OpenRegister\Mcp\Service\Chat\ToolManagementHandler`. The JSON-schema →
 * LLPhant `Parameter`/`FunctionInfo` conversion logic is preserved verbatim; the tool
 * SOURCE is re-pointed: instead of constructing/consulting OR's internal
 * `ToolRegistry` (and, transitively, `McpProviderBridge`), this class consumes ONLY
 * `OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade` — the documented public
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
 * hydra-console-agent-leaves threads two more per-run facts onto the invoker it
 * builds, both derived here because this is where the agent record is in scope:
 * the ARGUMENT CONSTRAINTS an argument-scoped grant declares
 * (`ToolGrantResolver::argumentConstraints()`), and the OWNING Nextcloud UID a
 * flow-queueing tool must be attributable to. Neither changes which descriptors
 * are offered — a narrowed grant resolves to the SAME catalog id — so the
 * disclosure and default-deny behaviour above is untouched.
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-3-1
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-3-2
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-2
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Closure;
use LLPhant\Chat\FunctionInfo\FunctionInfo;
use OCA\Hermiq\Kernel\Contracts\ToolLoopInterface;
use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\Agent\AgentAvailabilityService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\Hermiq\Service\Workspace\RepoEffectingGrants;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\Capability\ToolGrantResolutionException;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

// 🔴 THESE TWO USED TO NEED NO IMPORT — see the note in FacadeToolInvoker. They
// lived in this namespace until ADR-099 §5 moved the capability grammar to
// OpenRegister; a relocation that only rewrote files with an explicit `use`
// would leave this class referencing classes that are no longer here, and PHP
// would not say so until the line ran.

/**
 * Resolves an agent's allowed tool functions from the OR tool-registry facade
 * and converts them into LLPhant FunctionInfo objects.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) This class is the turn-assembly seam:
 *   grant resolution, default-deny, progressive disclosure, guardrail-policy resolution,
 *   argument-constraint parsing and owner resolution all have to happen at the ONE point
 *   where the agent record and the catalog are both in scope. Each is a small, separately
 *   tested method; the total tracks the number of governance concerns, not tangled logic.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Same cause: each collaborator is one
 *   of those concerns, injected rather than reached for.
 * @SuppressWarnings(PHPMD.LongVariable)             `$guardrailPolicyService` is a promoted
 *   constructor collaborator named after its class (GuardrailPolicyService) — shortening
 *   it would obscure which service is injected.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-3-1
 */
class ToolLoop implements ToolLoopInterface {

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
	 * @param ToolRegistryFacade $toolRegistryFacade OR's public tool read/invoke surface
	 *                                               (the ONLY tool dependency — never
	 *                                               ToolRegistry/McpProviderBridge
	 *                                               directly).
	 * @param LoggerInterface $logger Logger.
	 * @param ToolGrantResolver $grantResolver Schema-scoped grant expansion + default-deny.
	 * @param ToolSearchService $toolSearchService Per-run resolved-set registry + `searchTools` ranking.
	 * @param ApprovalService $approvalService Human-approval gate (threaded onto
	 *                                         `FacadeToolInvoker` for un-granted
	 *                                         destructive invocations).
	 * @param IAppConfig $appConfig Reads `hermiq.tools.disclosureThreshold`.
	 * @param GuardrailPolicyService|null $guardrailPolicyService Resolves the effective
	 *                                                            GuardrailPolicy's per-tool auto/confirm/deny
	 *                                                            classification (agent-guardrails), threaded
	 *                                                            onto `FacadeToolInvoker` as a resolved
	 *                                                            toolId→classification map. Nullable/optional
	 *                                                            purely so existing test callers that omit it
	 *                                                            see zero behavior change (every tool `auto`);
	 *                                                            real DI always provides it.
	 * @param RedactionService|null $redactionService Masks secrets/PII in a dry-run's
	 *                                                `would-have-called` tool-call arguments
	 *                                                before they reach the trace
	 *                                                (run-replay-and-dry-run), threaded onto
	 *                                                `FacadeToolInvoker`. Nullable/optional so
	 *                                                existing test callers that omit it see zero
	 *                                                behavior change (only consulted when a
	 *                                                caller actually passes `dryRun: true`); real
	 *                                                DI always provides it.
	 * @param IUserSession|null $userSession Resolves the ACTING Nextcloud user, which is
	 *                                       the most authoritative owner an agent-queued
	 *                                       flow run can be attributed to
	 *                                       (hydra-console-agent-leaves). Nullable/
	 *                                       optional so existing test callers see zero
	 *                                       behavior change; with no session the owner
	 *                                       falls back to the agent record's own acting
	 *                                       user, and with neither a flow-queueing tool
	 *                                       is REFUSED rather than run unattributed.
	 * @param AgentAvailabilityService|null $availabilityService Fresh read of the agent's switch, so a
	 *                                                        turn stops at its next tool call once the
	 *                                                        agent is switched off. Null: never stops.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator, not a logic-bearing argument list (mirrors
	 *   ApprovalService's precedent in this codebase).
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-3-1
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-2
	 * @spec openspec/changes/archive/2026-07-13-agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
	 * @spec openspec/changes/archive/2026-07-13-run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
	 */
	public function __construct(
		private readonly ToolRegistryFacade $toolRegistryFacade,
		private readonly LoggerInterface $logger,
		private readonly ToolGrantResolver $grantResolver,
		private readonly ToolSearchService $toolSearchService,
		private readonly ApprovalService $approvalService,
		private readonly IAppConfig $appConfig,
		private readonly ?GuardrailPolicyService $guardrailPolicyService = null,
		private readonly ?RedactionService $redactionService = null,
		private readonly ?IUserSession $userSession = null,
		private readonly ?AgentAvailabilityService $availabilityService = null,
	) {
	}//end __construct()

	/**
	 * List the LLPhant-shaped function descriptors an agent may call.
	 *
	 * Applies the `Agent.tools` whitelist (empty = allow all) and the caller's
	 * per-request `$selectedTools` narrowing, then queries the facade.
	 *
	 * @param ObjectEntity|null $agent Agent object (null = agent-less chat, no tools).
	 * @param array $selectedTools Registry ids selected for this request
	 *                             (empty = no narrowing).
	 *
	 * @return array<int, array<string, mixed>> Flattened LLPhant function descriptors
	 *                                          (`name`, `description`, `parameters`,
	 *                                          optional `mcpId`).
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-3-2
	 */
	public function listAgentFunctions(?ObjectEntity $agent, array $selectedTools = []): array {
		if ($agent === null) {
			return [];
		}

		$whitelist = $this->narrowToSelection(
			whitelist: $this->expandLegacyIds(ids: $this->agentGrants(agent: $agent)),
			selectedTools: $selectedTools
		);
		if ($whitelist === null) {
			return [];
		}

		$functions = $this->resolveGrantedFunctions(whitelist: $whitelist);
		$this->logResolvedFunctions(whitelist: $whitelist, functions: $functions);

		// Register the FULL resolved set for this run (searchTools ranking +
		// the approval gate's grant-membership check) BEFORE any disclosure
		// narrowing — see ToolSearchService's docblock.
		$this->toolSearchService->registerResolved(descriptors: $functions);

		return $this->applyDisclosure(functions: $functions);
	}//end listAgentFunctions()

	/**
	 * Narrow the agent whitelist to the per-request selection.
	 *
	 * Legacy compatibility (ported from the original's candidate loop): agent
	 * records from older eras stored bare ids ("objects") that resolve as
	 * "openregister.{id}" registry ids, so the selection is expanded the same way
	 * as the whitelist. When the agent whitelist is empty ("all allowed") the
	 * selection simply becomes the whitelist; when both are non-empty they
	 * intersect. An intersection that comes up EMPTY means "nothing allowed" —
	 * null, because passing an empty whitelist to listTools() would mean "all"
	 * (the exact opposite).
	 *
	 * @param array<int, string> $whitelist     The legacy-expanded agent whitelist.
	 * @param array              $selectedTools Registry ids selected for this request.
	 *
	 * @return array<int, string>|null The whitelist to resolve, or null when nothing is allowed.
	 */
	private function narrowToSelection(array $whitelist, array $selectedTools): ?array {
		$selected = $this->expandLegacyIds(ids: $selectedTools);
		if (empty($selected) === true) {
			return $whitelist;
		}

		if (empty($whitelist) === true) {
			return $selected;
		}

		$whitelist = array_values(array_intersect($whitelist, $selected));
		if (empty($whitelist) === false) {
			return $whitelist;
		}

		$this->logNoIntersection(selectedCount: count($selectedTools));
		return null;
	}//end narrowToSelection()

	/**
	 * Log that the per-request selection shares no tool with the agent whitelist.
	 *
	 * @param int $selectedCount How many tools the request selected.
	 *
	 * @return void
	 */
	private function logNoIntersection(int $selectedCount): void {
		$this->logger->info(
			message: '[ToolLoop] Selected tools do not intersect the agent whitelist — no tools enabled',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'selectedTools' => $selectedCount,
			]
		);
	}//end logNoIntersection()

	/**
	 * Resolve the whitelist into the descriptors the agent may call, and raise
	 * when a whitelist that granted tools resolved to none.
	 *
	 * Hermiq-runner-git-capability: a repo-effecting tool (the push) resolves
	 * only from a grant pinning the repository and constraining the branch, so
	 * a bare grant is never offered. An agent that WAS granted tools but resolved
	 * to none is broken, not tool-less: downstream both look like an empty
	 * function list, so the run would continue text-only and every layer would
	 * report success while the agent silently lost every capability it was
	 * configured with. Raise it here, the last point where both facts are known.
	 *
	 * @param array<int, string> $whitelist The legacy-expanded, selection-narrowed whitelist.
	 *
	 * @return array<int, array<string, mixed>> The resolved descriptors.
	 *
	 * @throws ToolGrantResolutionException When granted tools resolve to nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RepoEffectingGrants is a pure rule over the grant grammar, like ToolGrantResolver's static classifiers.
	 */
	private function resolveGrantedFunctions(array $whitelist): array {
		$functions = RepoEffectingGrants::filterDescriptors(
			descriptors: $this->resolveFunctions(whitelist: $whitelist),
			constraints: $this->grantResolver->argumentConstraints(grants: $whitelist)
		);

		if ($this->grantResolver->resolvesToNothing(grants: $whitelist, resolvedTools: $functions) === true) {
			$this->logger->error(
				message: '[ToolLoop] Agent tool grants resolved to no tools',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'grants' => $whitelist,
				]
			);
			throw new ToolGrantResolutionException(grants: $whitelist);
		}

		return $functions;
	}//end resolveGrantedFunctions()

	/**
	 * Log the size of the resolved function set against the whitelist.
	 *
	 * @param array<int, string>               $whitelist The resolved whitelist.
	 * @param array<int, array<string, mixed>> $functions The resolved descriptors.
	 *
	 * @return void
	 */
	private function logResolvedFunctions(array $whitelist, array $functions): void {
		$this->logger->debug(
			message: '[ToolLoop] Resolved agent tool functions',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'whitelistSize' => count($whitelist),
				'functionCount' => count($functions),
			]
		);
	}//end logResolvedFunctions()

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
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-2
	 */
	private function resolveFunctions(array $whitelist): array {
		$needsCatalog = ($whitelist === [] || $this->grantResolver->hasWildcardGrant(grants: $whitelist) === true);

		if ($needsCatalog === false) {
			// Hydra-console-agent-leaves: the facade matches on CATALOG ids, so an
			// argument-scoped grant must be reduced to its underlying id before it
			// gets here — otherwise the narrowed grant would match nothing and the
			// agent would silently lose the capability it was configured with. A
			// grant carrying no constraints is returned verbatim, so this is a
			// no-op for every pre-existing whitelist. Still exactly one facade
			// call, unchanged.
			return $this->toolRegistryFacade->listTools(
				toolWhitelist: $this->grantResolver->baseToolIds(grants: $whitelist)
			);
		}

		$catalog = $this->toolRegistryFacade->listTools(toolWhitelist: []);
		$resolvedIds = $this->grantResolver->resolve(grants: $whitelist, catalog: $catalog);

		if ($whitelist === []) {
			// Preserve the single listTools([]) call contract for the legacy
			// "empty whitelist" path — post-filter the already-fetched catalog
			// rather than re-querying the facade with a concrete id list.
			return $this->filterDescriptorsByIds(descriptors: $catalog, allowedIds: $resolvedIds);
		}

		if ($resolvedIds === []) {
			// The grants expanded to NOTHING. Never hand that to the facade: an
			// empty whitelist there means "all tools allowed", so a grant that
			// matched nothing would silently escalate into a grant of the entire
			// catalog, destructive tools included — the exact inverse of the
			// failure this method's caller guards against. Return the honest
			// empty set and let listAgentFunctions() raise on it.
			return [];
		}

		return $this->toolRegistryFacade->listTools(toolWhitelist: $resolvedIds);
	}//end resolveFunctions()

	/**
	 * Keep only the descriptors whose id (`mcpId` or `name`) is in `$allowedIds`,
	 * preserving original order.
	 *
	 * @param array<int, mixed> $descriptors Full descriptor list. Typed loosely on purpose:
	 *                                       these cross the OpenRegister tool-facade boundary,
	 *                                       so each entry is re-checked below.
	 * @param array<int, string> $allowedIds Ids to keep.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function filterDescriptorsByIds(array $descriptors, array $allowedIds): array {
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
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-resolved-catalog-exceeds-the-disclosure-threshold
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-small-catalog-does-not-trigger-disclosure
	 */
	private function applyDisclosure(array $functions): array {
		$threshold = $this->appConfig->getValueInt('hermiq', 'tools.disclosureThreshold', self::DEFAULT_DISCLOSURE_THRESHOLD);

		if (count($functions) <= $threshold) {
			return $functions;
		}

		$searchDescriptors = $this->toolRegistryFacade->listTools(toolWhitelist: [self::SEARCH_TOOLS_ID]);
		if ($searchDescriptors === []) {
			// The meta-tool itself is not registered/reachable (e.g. not
			// granted) — fail open to the full set rather than leaving the
			// agent with zero callable tools.
			return $functions;
		}

		$this->logger->info(
			message: '[ToolLoop] Progressive tool disclosure active',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'resolvedCount' => count($functions),
				'threshold' => $threshold,
			]
		);

		return [$searchDescriptors[0]];
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
	 * @param array $functions Flattened LLPhant function descriptors
	 *                         (from `listAgentFunctions()`).
	 * @param StreamYieldChannel|null $channel Optional streaming channel.
	 * @param RunTraceCollector|null $trace Optional run-trace collector; threaded
	 *                                      onto the shared `FacadeToolInvoker` so
	 *                                      each tool call the LLM makes is timed
	 *                                      as a `tool` step
	 *                                      (run-trace-observability).
	 * @param ObjectEntity|null $agent Agent object (agent-tool-governance-and-disclosure);
	 *                                 threaded onto `FacadeToolInvoker` so the
	 *                                 un-granted-destructive → approval-gate check and
	 *                                 the `hermiq.searchTools` short-circuit know which
	 *                                 agent/run they act for. Null (agent-less chat)
	 *                                 simply disables both — a plain facade dispatch,
	 *                                 unchanged.
	 * @param string|null $organisation The turn's organisation (agent-guardrails);
	 *                                  resolved ONCE into the effective GuardrailPolicy's
	 *                                  `toolPolicy` map and threaded onto the shared
	 *                                  `FacadeToolInvoker`. Null/absent GuardrailPolicyService
	 *                                  resolves to every tool `auto` (zero behavior change).
	 * @param bool $dryRun Whether this turn is a dry-run preview
	 *                     (run-replay-and-dry-run); threaded onto the
	 *                     shared `FacadeToolInvoker` along with each
	 *                     function's full descriptor (so its
	 *                     `scope`/`destructiveHint`/`readOnlyHint`, when
	 *                     set, informs the side-effect classification).
	 *                     False (every pre-existing caller) is
	 *                     byte-for-byte unchanged behavior.
	 *
	 * @return array<int, FunctionInfo> FunctionInfo objects ready for `setTools()`.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Dry-run preview (run-replay-and-dry-run)
	 * is a cross-cutting mode threaded through the engine as a flag by design.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-3-1
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-3
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-4
	 * @spec openspec/changes/archive/2026-07-13-agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
	 * @spec openspec/changes/archive/2026-07-13-run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
	 */
	public function buildFunctionInfos(
		array $functions,
		?StreamYieldChannel $channel = null,
		?RunTraceCollector $trace = null,
		?ObjectEntity $agent = null,
		?string $organisation = null,
		bool $dryRun = false,
	): array {
		$run = new ToolSurfaceRun();
		$run->functions = $functions;
		$run->channel = $channel;
		$run->trace = $trace;
		$run->agent = $agent;
		$run->organisation = $organisation;
		$run->dryRun = $dryRun;

		return (new FunctionInfoBuilder())->build(functions: $functions, invoker: $this->createInvoker(run: $run));
	}//end buildFunctionInfos()

	/**
	 * The one FacadeToolInvoker every FunctionInfo of a tool-surface build shares.
	 *
	 * @param ToolSurfaceRun $run The build's descriptors and turn.
	 *
	 * @return FacadeToolInvoker
	 */
	private function createInvoker(ToolSurfaceRun $run): FacadeToolInvoker {
		$agentId = $this->agentIdOf(agent: $run->agent);

		return new FacadeToolInvoker(
			facade: $this->toolRegistryFacade,
			channel: $run->channel,
			trace: $run->trace,
			services: new InvocationServices(
				toolSearchService: $this->toolSearchService,
				approvalService: $this->approvalService,
				redactionService: $this->redactionService
			),
			scope: new InvocationScope(
				agentId: $agentId,
				dryRun: $run->dryRun,
				ownerUid: $this->resolveOwnerUid(agent: $run->agent),
				maxToolCalls: (new AgentAvailability())->maxToolCalls(agent: $run->agent),
				agentStillOn: $this->agentStillOn(agentId: $agentId)
			),
			policy: new InvocationPolicy(
				mcpIdByName: $this->mcpIdsByName(functions: $run->functions),
				toolPolicy: $this->resolveToolPolicy(organisation: $run->organisation),
				descriptorsByName: $this->descriptorsByName(functions: $run->functions),
				argumentConstraints: $this->grantResolver->argumentConstraints(grants: $this->agentGrants(agent: $run->agent)),
				waivedConstraintSets: $this->grantResolver->waivedConstraintSets(grants: $this->agentGrants(agent: $run->agent))
			)
		);
	}//end createInvoker()

	/**
	 * The acting agent's uuid, or null for agent-less chat.
	 *
	 * @param ObjectEntity|null $agent The acting agent, or null.
	 *
	 * @return string|null
	 */
	private function agentIdOf(?ObjectEntity $agent): ?string {
		if ($agent === null) {
			return null;
		}

		return (string)$agent->getUuid();
	}//end agentIdOf()

	/**
	 * The registry id of each named descriptor, keyed by function name; a
	 * descriptor without `mcpId` maps to its own name.
	 *
	 * @param array<int|string, mixed> $functions Flattened LLPhant function descriptors.
	 *
	 * @return array<string, string>
	 */
	private function mcpIdsByName(array $functions): array {
		$mcpIdByName = [];
		foreach ($this->descriptorsByName(functions: $functions) as $name => $func) {
			$mcpIdByName[$name] = (string)($func['mcpId'] ?? $func['name']);
		}

		return $mcpIdByName;
	}//end mcpIdsByName()

	/**
	 * The full descriptor of each named function, keyed by function name, so
	 * the dry-run classifier can consult a tool's declared hints when present.
	 *
	 * @param array<int|string, mixed> $functions Flattened LLPhant function descriptors.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function descriptorsByName(array $functions): array {
		$descriptorsByName = [];
		foreach ((new FunctionInfoBuilder())->namedDescriptors(functions: $functions) as $func) {
			$descriptorsByName[(string)$func['name']] = $func;
		}

		return $descriptorsByName;
	}//end descriptorsByName()

	/**
	 * A fresh read of the agent's switch for the tool invoker, so a turn stops at
	 * its next tool call once the agent is switched off (agents-switch-off-and-stop).
	 *
	 * @param string|null $agentId The acting agent, or null for agent-less chat.
	 *
	 * @return Closure|null The read, or null when there is no agent to switch.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
	 */
	private function agentStillOn(?string $agentId): ?Closure {
		if ($agentId === null || $agentId === '' || $this->availabilityService === null) {
			return null;
		}

		$availability = $this->availabilityService;

		return static fn (): bool => $availability->isOn(agentId: $agentId);
	}//end agentStillOn()

	/**
	 * The raw `Agent.tools` grant strings for an agent, or `[]` when there is no
	 * agent (agent-less chat) or the field is not a list.
	 *
	 * Read straight off the agent record rather than off the already-resolved
	 * descriptor list, because resolution deliberately DISCARDS the constraints —
	 * an argument-scoped grant resolves to the underlying catalog id, so the
	 * narrowing exists only in the grant string.
	 *
	 * @param ObjectEntity|null $agent The acting agent, or null.
	 *
	 * @return array<int, string> The raw grants.
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
	 */
	private function agentGrants(?ObjectEntity $agent): array {
		if ($agent === null) {
			return [];
		}

		$grants = ($agent->getObject()['tools'] ?? []);
		if (is_array($grants) === false) {
			return [];
		}

		return $grants;
	}//end agentGrants()

	/**
	 * The owning Nextcloud UID this run acts as, or null when none can be resolved.
	 *
	 * Order, most authoritative first: the ACTING session user (a person made this
	 * request), then the agent record's own `actingUser` (a scheduled or
	 * flow-triggered run declares who it runs as), then the agent object's owner.
	 * Nothing is defaulted: when all three are empty the result is null and
	 * `FacadeToolInvoker` REFUSES a flow-queueing tool rather than queueing an
	 * unattributed run, because a flow's terminal step may command an external
	 * system and "who told it to do that" has to be answerable from the record.
	 *
	 * @param ObjectEntity|null $agent The acting agent, or null.
	 *
	 * @return string|null The owning UID, or null when unresolvable.
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-a-flow-invoked-as-an-agent-tool-is-attributed-to-an-owning-uid
	 */
	private function resolveOwnerUid(?ObjectEntity $agent): ?string {
		$sessionUid = $this->sessionUid();
		if ($sessionUid !== null || $agent === null) {
			return $sessionUid;
		}

		return $this->agentRecordUid(agent: $agent);
	}//end resolveOwnerUid()

	/**
	 * The acting session user's UID, or null when there is no (named) session user.
	 *
	 * @return string|null
	 */
	private function sessionUid(): ?string {
		$sessionUser = $this->userSession?->getUser();
		if ($sessionUser !== null && trim($sessionUser->getUID()) !== '') {
			return trim($sessionUser->getUID());
		}

		return null;
	}//end sessionUid()

	/**
	 * The UID an agent record declares it runs as (`actingUser`, then `user`),
	 * falling back to the agent object's owner; null when all are empty.
	 *
	 * @param ObjectEntity $agent The acting agent.
	 *
	 * @return string|null
	 */
	private function agentRecordUid(ObjectEntity $agent): ?string {
		$data = $agent->getObject();
		foreach (['actingUser', 'user'] as $field) {
			$candidate = trim((string)($data[$field] ?? ''));
			if ($candidate !== '') {
				return $candidate;
			}
		}

		$owner = trim((string)$agent->getOwner());
		if ($owner !== '') {
			return $owner;
		}

		return null;
	}//end agentRecordUid()

	/**
	 * Expand bare (dot-less) tool ids with the legacy `openregister.` prefix so
	 * agent records from older schema eras keep matching registry ids.
	 *
	 * @param array $ids Raw whitelist/selection entries.
	 *
	 * @return array<int, string> Expanded id list (original ids preserved).
	 */
	private function expandLegacyIds(array $ids): array {
		$expanded = [];
		foreach ($ids as $id) {
			if (is_string($id) === false || $id === '') {
				continue;
			}

			$expanded[] = $id;

			// The no-tools sentinel is a marker, not a legacy bare tool id —
			// expanding it to `openregister.__none__` would leave a grant list
			// that no longer reads as "deliberately tool-less", and the
			// resolves-to-nothing guard would then reject a perfectly valid agent.
			if ($id === ToolGrantResolver::NO_TOOLS_SENTINEL) {
				continue;
			}

			if (str_contains($id, '.') === false) {
				$expanded[] = 'openregister.' . $id;
			}
		}

		return array_values(array_unique($expanded));
	}//end expandLegacyIds()

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
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
	 */
	private function resolveToolPolicy(?string $organisation): array {
		if ($this->guardrailPolicyService === null) {
			return [];
		}

		$policy = $this->guardrailPolicyService->effectivePolicyFor(organisation: ($organisation ?? ''));

		// `effectivePolicyFor()`'s return shape guarantees `toolPolicy` and each entry's
		// `toolId`/`classification`, so no defensive re-checking is needed here.
		$map = [];
		foreach ($policy['toolPolicy'] as $entry) {
			$map[(string)$entry['toolId']] = (string)$entry['classification'];
		}

		return $map;
	}//end resolveToolPolicy()

}//end class
