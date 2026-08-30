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
 * agent-tool-governance-and-disclosure adds two more short-circuits BEFORE the
 * facade dispatch:
 *
 * - `hermiq.searchTools` (progressive disclosure's meta-tool) is
 *   Hermiq-INTERNAL — resolved directly against the run's `ToolSearchService`
 *   (design.md §2: "the invocation never leaves Hermiq" — no facade round-trip).
 * - A write/destructive-classified tool (`ToolGrantResolver::isWriteOrDestructive()`)
 *   NOT part of this run's resolved (grant-filtered, default-denied) set —
 *   `ToolSearchService::isGranted()` — routes through the existing
 *   `human-approval-gate` `Approval` state machine instead of executing: a
 *   pending `Approval` is created (or an already-decided one is consulted) and
 *   `ToolRegistryFacade::invokeTool()` is NOT called until it is `approved`; a
 *   `denied` `Approval` blocks the invocation permanently. This is a
 *   defense-in-depth check at the point of actual invocation — independent of
 *   whether `ToolLoop` already excluded the tool from the model's context.
 *
 * agent-guardrails adds a THIRD, higher-priority short-circuit, checked before
 * the grant/approval-gate one above: the effective `GuardrailPolicy`'s
 * per-tool `toolPolicy` classification (`auto`|`confirm`|`deny`, resolved ONCE
 * per turn by `ToolLoop` into the `$toolPolicy` map this class receives).
 * `deny` refuses the call outright (a `tool` trace step, outcome `denied`) and
 * never reaches the grant/approval-gate check or the facade. `confirm` refuses
 * the FIRST attempt and creates a pending `Approval` (`sourceType: "toolcall"`,
 * design.md Decision 4) — a subsequent, argument-IDENTICAL retry within the
 * approval's validity window is the one and only invocation that proceeds
 * (`consumedAt` then blocks any further replay). A tool absent from the
 * `toolPolicy` map is `auto` — falls through unchanged to the pre-existing
 * grant/approval-gate check, zero behavior change.
 *
 * web-research-tool extends `dispatchToFacade()`'s trace step with an OPTIONAL
 * redacted `target` for exactly `hermiq.webSearch`/`hermiq.webFetch` — "which
 * external host did this agent reach" is itself the compliance-relevant fact for an
 * outbound web call (`run-audit-log` MODIFIED requirement). This reuses the
 * `$extra` parameter `RunTraceCollector::endStep()` already gained for
 * run-replay-and-dry-run's redacted `would-have-called` arguments (design.md's
 * original plan — a dedicated fourth `?string $target` parameter — is superseded by
 * that already-shipped, more general mechanism; no `RunTraceCollector` change is
 * needed). `resolveWebResearchTarget()` reduces a `webFetch` URL to host+path with
 * the query string dropped ENTIRELY (never selectively masked — simpler and safer
 * than `RedactionService::redactQueryString()`'s per-field masking, since a fetch
 * URL's query string is exactly where a secret-shaped value could accidentally end
 * up) and passes a `webSearch` query through as-is (not a URL, so there is no
 * host/path to reduce), capped defensively. Every other tool's step is unaffected.
 *
 * run-replay-and-dry-run adds a FOURTH check, at the single point every
 * governance path above eventually funnels into when it does NOT refuse —
 * `dispatchToFacade()`, the only place `ToolRegistryFacade::invokeTool()` is
 * ever actually called. When `$dryRun` is true and `ToolClassificationService`
 * classifies the tool as side-effecting (fail-safe closed default — see that
 * class), the facade is NEVER invoked: a `tool` trace step with
 * `outcome='would-have-called'` and REDACTED arguments is recorded instead,
 * and a synthetic, clearly-labelled result is returned to the LLM so a
 * multi-step plan can keep reasoning. A read-only-classified tool is still
 * invoked for real (accurate preview data). This is deliberately the ONE
 * narrow exception to this class's "never the raw arguments" trace rule
 * (`run-trace-observability` Risk 4) — see `RedactionService`.
 *
 * hydra-console-agent-leaves adds a FIFTH check, immediately after the guardrail
 * `deny` short-circuit and BEFORE everything else — argument-constraint
 * enforcement for `ToolGrantResolver`'s argument-scoped grants, plus owner
 * attribution for a tool that queues a flow run.
 *
 * - **Constraints.** A grant of the form `{toolId}?arg=value&other=in:a,b,c`
 *   narrows a single multi-target tool (one that selects its target from an
 *   argument) to one specific capability. The grant is parsed at turn assembly by
 *   `ToolGrantResolver::argumentConstraints()` and handed to this class as a
 *   `toolId => alternative constraint sets` map; a call whose arguments satisfy
 *   none of the alternatives is REFUSED with a structured
 *   `grant_constraint_violated` result — never an exception — and never reaches
 *   the facade. The refusal's trace step names the tool, the offending argument
 *   and the constraint it violated, because "which constraint stopped it" is the
 *   compliance-relevant fact. The constraint set is the AUTHORITATIVE statement of
 *   what the agent may ask for: the arguments derive from object text other agents
 *   wrote, so no prompt, tool description or model rationale can widen it.
 * - **Attribution.** A tool that QUEUES A FLOW RUN (`openregister.runFlow`) is
 *   refused outright when this run has no resolvable owning Nextcloud UID
 *   (`owner_unresolved`), and carries the owner into the call when it does. A
 *   flow's terminal step may command an external system, so an unattributed run of
 *   one is an unattributed command; refusing is deliberately chosen over
 *   defaulting to an empty or system owner, because the situations a default would
 *   rescue are exactly the ones where nobody could be held to the command.
 *
 * Both are placed in this same `__call()` chain on purpose. A second invocation
 * path is what the class docblock above warns against, and a guard that can be
 * routed around is not a guard.
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
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-4
 * @spec openspec/changes/agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
 * @spec openspec/changes/agent-guardrails/tasks.md#task-7-confirm-tool-retry-and-consume-flow-in-facadetoolinvoker
 * @spec openspec/changes/run-replay-and-dry-run/tasks.md#task-2-facadetoolinvoker-dry-run-neutralisation-with-redacted-would-have-called-steps
 * @spec openspec/changes/web-research-tool/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-a-flow-invoked-as-an-agent-tool-is-attributed-to-an-owning-uid
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ToolClassificationService;
use OCA\Hermiq\Service\ToolSearchService;
// 🔴 THESE TWO USED TO NEED NO IMPORT. `ToolGrantResolver` and
// `ToolReachResolver` lived in this very namespace until ADR-099 §5 moved the
// capability grammar to OpenRegister, so PHP resolved them for free. A
// relocation that only rewrote the files carrying an explicit `use` would have
// left this class referencing two classes that no longer exist here — and PHP
// would not have said so until the line ran, at which point it is a fatal in
// the middle of a tool call rather than a failure to load.
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;

/**
 * Dispatches LLPhant tool calls onto the OR tool-registry facade.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity is the sum of several
 *   independent governance short-circuits (searchTools, guardrail deny/confirm,
 *   argument-constraint enforcement, owner attribution, approval-gate, dry-run
 *   neutralisation, agent-memory-tools' agentId injection), each a small,
 *   single-purpose, independently-tested method; the total tracks the number of
 *   governance concerns this one dispatch chokepoint threads through, not
 *   incidental complexity.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Same cause: every collaborator is one
 *   governance concern this single chokepoint must consult before dispatch.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Same cause again, and the length is
 *   overwhelmingly the docblocks: each governance short-circuit documents WHY it refuses
 *   where it does, which is the only place that reasoning is recorded. Splitting the class
 *   to satisfy the metric would create the second dispatch path this class exists to prevent.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
 */
class FacadeToolInvoker {

	/**
	 * The `hermiq.searchTools` meta-tool's registry id and LLPhant-safe name
	 * (dots become underscores — `McpProviderBridge::safeFunctionName()`).
	 *
	 * @var array<int, string>
	 */
	private const SEARCH_TOOLS_NAMES = ['hermiq.searchTools', 'hermiq_searchTools'];

	/**
	 * The three agent-memory-tools registry ids that need to know which agent is
	 * running. `HermiqToolProvider::invokeTool(toolId, arguments)` has no other way
	 * to learn this: the `IMcpToolProvider` ABI threads no acting-agent identity
	 * into `invokeTool()` itself (documented on that class — the same limitation
	 * `agent-tool-governance-and-disclosure`'s oversight surface already works
	 * around by correlating via ownership instead), yet `Memory`/`UserProfile`
	 * objects are keyed by `agentId`. This class already holds `$agentId` for the
	 * approval gate, so injecting it into `$arguments` here — for exactly these
	 * three ids, mirroring this class's existing `SEARCH_TOOLS_NAMES` special-case
	 * — is the minimal, non-ABI-breaking fix; every other tool's arguments are
	 * untouched.
	 *
	 * @var array<int, string>
	 */
	private const MEMORY_TOOL_IDS = ['hermiq.rememberMemory', 'hermiq.recallMemory', 'hermiq.forgetMemory'];

	/**
	 * The `hermiq.delegateAgent` registry id (sub-agent-delegation), which needs the
	 * SAME "which agent is running" identity `MEMORY_TOOL_IDS` already gets injected
	 * for — `DelegationService::delegate()` treats this run-injected `agentId` as the
	 * ONLY trusted "calling agent" identity (never a value the LLM could supply
	 * itself), exactly mirroring `MEMORY_TOOL_IDS`' rationale above.
	 *
	 * @var string
	 */
	private const DELEGATE_AGENT_TOOL_ID = 'hermiq.delegateAgent';

	/**
	 * The two web-research-tool ids whose trace step carries an additional redacted
	 * `target` (see class docblock and `resolveWebResearchTarget()`).
	 *
	 * @var array<int, string>
	 */
	private const WEB_RESEARCH_TOOL_IDS = ['hermiq.webSearch', 'hermiq.webFetch'];

	/**
	 * The NC-native write tools that need the run's agent identity injected, for
	 * the SAME reason `MEMORY_TOOL_IDS` does: the ADR-088 mark records WHICH agent
	 * authored the artefact, and that identity must come from the run — never from
	 * a value the LLM could supply for itself.
	 *
	 * The note tools are deliberately ABSENT. A note is marked with a system tag,
	 * and a tag is a shared label that cannot carry per-agent data — so there is
	 * nothing for an injected agent id to do there, and injecting it anyway would
	 * imply an attribution the artefact does not actually carry. For notes the
	 * agent attribution lives only in the trace step, beside the file id.
	 *
	 * @var array<int, string>
	 */
	private const ARTEFACT_WRITE_TOOL_IDS = [
		'hermiq.createCalendarEvent',
		'hermiq.upsertContact',
	];

	/**
	 * The maximum characters of a `webSearch` query recorded as its trace target —
	 * a defensive bound against trace bloat, not a spec requirement (a search query
	 * is not itself a URL, so there is no host+path to reduce it to).
	 *
	 * @var int
	 */
	private const MAX_SEARCH_QUERY_TARGET_LENGTH = 200;

	/**
	 * Registry ids of tools that QUEUE A FLOW RUN and therefore require a
	 * resolvable owning Nextcloud UID before they may be dispatched
	 * (hydra-console-agent-leaves).
	 *
	 * The list is deliberately explicit rather than derived from a hint: a hint is
	 * an untrusted UX signal (`ToolGrantResolver` class docblock), and "does this
	 * call end up commanding something on somebody's behalf" is not a question a
	 * provider's own annotation may answer for us.
	 *
	 * @var array<int, string>
	 */
	private const FLOW_QUEUEING_TOOL_IDS = ['openregister.runFlow'];

	/**
	 * The argument the owning UID is carried in for a flow-queueing tool.
	 *
	 * OpenRegister's `FlowRunService::queue()` already accepts an optional `$user`
	 * it records as the run's `triggeredBy`; `FlowMcpToolProvider::runFlow()` simply
	 * does not pass one (ConductionNL/openregister#2158). Injecting the resolved
	 * owner under this key means the attribution lands the moment that upstream gap
	 * closes, with no Hermiq change — and until then the REFUSAL half of this rule
	 * is what actually holds the line, which is why refusing (not defaulting) is the
	 * specified behaviour.
	 *
	 * @var string
	 */
	private const FLOW_OWNER_ARGUMENT = 'triggeredBy';

	/**
	 * Constructor.
	 *
	 * @param ToolRegistryFacade $facade The OR public tool read/invoke surface.
	 * @param StreamYieldChannel|null $channel Optional streaming channel for
	 *                                         tool_call/tool_result frames.
	 * @param RunTraceCollector|null $trace Optional run-trace collector; when
	 *                                      supplied, each invocation is timed
	 *                                      as a `tool` step
	 *                                      (run-trace-observability).
	 * @param ToolSearchService|null $toolSearchService Per-run resolved-set + `searchTools`
	 *                                                  ranking
	 *                                                  (agent-tool-governance-and-disclosure);
	 *                                                  null disables both the meta-tool
	 *                                                  short-circuit and the
	 *                                                  approval-gate's grant-membership
	 *                                                  check (agent-less chat).
	 * @param ApprovalService|null $approvalService Human-approval gate; null disables the
	 *                                              destructive-invocation short-circuit
	 *                                              (existing callers, unchanged
	 *                                              behaviour).
	 * @param string|null $agentId The acting agent's UUID; null disables
	 *                             the approval gate (no reviewer/owner
	 *                             to route to).
	 * @param array<string,string> $mcpIdByName Map of LLPhant-safe function name to the
	 *                                          dotted `mcpId` — resolves the id the
	 *                                          approval gate classifies/checks (LLPhant
	 *                                          calls back with the safe name, which may
	 *                                          have dots replaced by underscores).
	 * @param array<string,string> $toolPolicy The effective GuardrailPolicy's
	 *                                         `toolId => classification` map
	 *                                         (agent-guardrails), resolved
	 *                                         ONCE per turn by
	 *                                         `ToolLoop::resolveToolPolicy()`.
	 *                                         A tool absent from this map is
	 *                                         `auto` (zero behavior change);
	 *                                         an empty map (no
	 *                                         `GuardrailPolicyService`, or no
	 *                                         policy configured) disables
	 *                                         this short-circuit entirely.
	 * @param bool $dryRun Whether this turn is a
	 *                     dry-run preview
	 *                     (run-replay-and-dry-run):
	 *                     when true, a
	 *                     side-effecting tool is
	 *                     neutralised at
	 *                     `dispatchToFacade()`
	 *                     instead of actually
	 *                     invoked. False (every
	 *                     pre-existing caller)
	 *                     is byte-for-byte
	 *                     unchanged behavior.
	 * @param ToolClassificationService|null $classifier Resolves whether a tool is
	 *                                                   side-effecting or
	 *                                                   read-only
	 *                                                   (run-replay-and-dry-run);
	 *                                                   only consulted when
	 *                                                   `$dryRun` is true.
	 *                                                   Defaults to a fresh
	 *                                                   instance (stateless, no
	 *                                                   dependencies) so callers
	 *                                                   never need to construct
	 *                                                   one just to leave dry-run
	 *                                                   off.
	 * @param array<string,array<string,mixed>> $descriptorsByName Map of LLPhant-safe function
	 *                                                             name to its full catalog
	 *                                                             descriptor
	 *                                                             (run-replay-and-dry-run), so
	 *                                                             the classifier can consult a
	 *                                                             tool's declared `scope`/
	 *                                                             `destructiveHint`/`readOnlyHint`
	 *                                                             when available. Empty for
	 *                                                             existing callers — the
	 *                                                             classifier then falls back
	 *                                                             to id-only classification.
	 * @param RedactionService|null $redactionService Masks secrets/PII in a
	 *                                                `would-have-called`
	 *                                                step's arguments
	 *                                                before they reach the
	 *                                                trace
	 *                                                (run-replay-and-dry-run,
	 *                                                the ONE exception to
	 *                                                this class's "never
	 *                                                raw arguments" rule).
	 *                                                Null falls back to a
	 *                                                fully-opaque
	 *                                                placeholder rather
	 *                                                than ever risking an
	 *                                                unredacted value
	 *                                                (fail-safe).
	 * @param array<string,array<int,array>> $argumentConstraints Per-tool ALTERNATIVE argument-constraint
	 *                                                            sets from an argument-scoped grant
	 *                                                            (`ToolGrantResolver::argumentConstraints()`,
	 *                                                            hydra-console-agent-leaves). Each inner
	 *                                                            entry is an `argument => {mode, values}`
	 *                                                            map. A tool absent from this map is
	 *                                                            unconstrained; an empty map (every
	 *                                                            pre-existing caller) disables the check
	 *                                                            entirely — zero behaviour change.
	 * @param string|null $ownerUid The owning Nextcloud UID this run acts as
	 *                              (hydra-console-agent-leaves). Required
	 *                              before a flow-queueing tool may be
	 *                              dispatched; null or empty REFUSES that
	 *                              tool rather than defaulting the owner.
	 *                              Every other tool is unaffected, so
	 *                              existing callers that omit it are
	 *                              unchanged.
	 * @param array<string,array<int,array>> $waivedConstraintSets The subset of those alternative sets
	 *                                                             belonging to grant entries that carried
	 *                                                             a `#noapproval` fragment
	 *                                                             (`ToolGrantResolver::waivedConstraintSets()`,
	 *                                                             agent-capability-reach). Shaped
	 *                                                             identically so the same pure checker
	 *                                                             decides conformance. Empty (every
	 *                                                             pre-existing caller) waives nothing.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI + per-run governance
	 *   context; every parameter is independently optional/nullable for backward
	 *   compatibility with existing (pre-governance) call sites.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Dry-run preview (run-replay-and-dry-run)
	 *   is a cross-cutting mode threaded through the engine as a flag by design.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-4
	 * @spec openspec/changes/agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
	 * @spec openspec/changes/run-replay-and-dry-run/tasks.md#task-2-facadetoolinvoker-dry-run-neutralisation-with-redacted-would-have-called-steps
	 */
	public function __construct(
		private readonly ToolRegistryFacade $facade,
		private readonly ?StreamYieldChannel $channel = null,
		private readonly ?RunTraceCollector $trace = null,
		private readonly ?ToolSearchService $toolSearchService = null,
		private readonly ?ApprovalService $approvalService = null,
		private readonly ?string $agentId = null,
		private readonly array $mcpIdByName = [],
		private readonly array $toolPolicy = [],
		private readonly bool $dryRun = false,
		private readonly ?ToolClassificationService $classifier = new ToolClassificationService(),
		private readonly array $descriptorsByName = [],
		private readonly ?RedactionService $redactionService = null,
		private readonly array $argumentConstraints = [],
		private readonly ?string $ownerUid = null,
		private readonly array $waivedConstraintSets = [],
	) {
	}//end __construct()

	/**
	 * Catch LLPhant's `$instance->{$functionName}(...$args)` dispatch and route
	 * it through `ToolRegistryFacade::invokeTool()` — unless it is the
	 * `hermiq.searchTools` meta-tool (handled internally) or an un-granted
	 * destructive tool (routed through the human-approval gate instead).
	 *
	 * PHP collects named arguments into `$arguments` with string keys — exactly
	 * the decoded-arguments object shape `invokeTool()` expects. The facade's
	 * `{result, isError}` envelope is JSON-encoded for the LLM's tool-result
	 * message (LLPhant expects a string return it can feed back as a tool turn).
	 *
	 * @param string $name The tool function name the LLM called.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return string JSON-encoded tool result for the follow-up LLM turn.
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-3-1
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-3
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-4
	 * @spec openspec/changes/agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
	 * @spec openspec/changes/agent-guardrails/tasks.md#task-7-confirm-tool-retry-and-consume-flow-in-facadetoolinvoker
	 */
	public function __call(string $name, array $arguments): string {
		if ($this->toolSearchService !== null && in_array($name, self::SEARCH_TOOLS_NAMES, true) === true) {
			return $this->handleSearchTools(arguments: $arguments);
		}

		// Agent-guardrails: the GuardrailPolicy's per-tool classification is
		// checked BEFORE the pre-existing grant/approval-gate check below — a
		// `deny`/`confirm` classification takes precedence over whatever the
		// tool's write/destructive grant status would otherwise allow.
		$classification = $this->classifyTool(name: $name);
		if ($classification === 'deny') {
			return $this->handleDeniedByPolicy(name: $name, arguments: $arguments);
		}

		// Hydra-console-agent-leaves: an argument-scoped grant's constraints are
		// checked here — after guardrail `deny` (so a denied tool is still denied
		// for the usual reason) and before every remaining gate, so a
		// permitted-but-misparameterised call is refused with its OWN error and
		// never creates a pointless pending approval for a command it may not make.
		$violation = $this->constraintViolationFor(name: $name, arguments: $arguments);
		if ($violation !== null) {
			return $this->handleConstraintViolation(name: $name, arguments: $arguments, violation: $violation);
		}

		if ($this->refusesForUnresolvedOwner(name: $name) === true) {
			return $this->handleOwnerUnresolved(name: $name, arguments: $arguments);
		}

		// Agent-capability-reach: an owner may waive the human confirmation for
		// ONE grant entry with a `#noapproval` fragment. It is consulted HERE
		// and nowhere earlier — after `deny` (a waiver never overrides an
		// organisation's hard refusal), after the constraint check (so the
		// invocation has already been shown to fall inside the exact grant that
		// carries the waiver), and after the owner check. Everything a waiver
		// could weaken has already been decided by the time it is read.
		if ($classification === 'confirm' && $this->isWaived(name: $name, arguments: $arguments) === false) {
			return $this->handleConfirmClassifiedInvocation(name: $name, arguments: $arguments);
		}

		if ($this->requiresApprovalGate(name: $name) === true) {
			return $this->handleGatedInvocation(name: $name, arguments: $arguments);
		}

		return $this->dispatchToFacade(name: $name, arguments: $arguments);
	}//end __call()

	/**
	 * The argument constraint this call violates, if any (hydra-console-agent-leaves).
	 *
	 * Returns null — "nothing to enforce" — for every tool no argument-scoped grant
	 * mentions, which is every tool for every pre-existing caller.
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return array{argument:string, mode:string, values:array<int,string>}|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `ToolGrantResolver::violationFor()` is a PURE
	 *   function over the grant grammar, exactly like the `isWriteOrDestructive()` this class
	 *   already calls statically. Injecting the resolver would add a collaborator that carries
	 *   no state and would let a caller substitute a more permissive grammar into a security
	 *   check — the opposite of what the seam is for.
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-argument-constraints-on-a-grant-are-enforced-at-invocation
	 */
	private function constraintViolationFor(string $name, array $arguments): ?array {
		if ($this->argumentConstraints === []) {
			return null;
		}

		$toolId = $this->resolveToolId(name: $name);
		$sets = ($this->argumentConstraints[$toolId] ?? []);
		if ($sets === []) {
			return null;
		}

		return ToolGrantResolver::violationFor(constraintSets: $sets, arguments: $arguments);
	}//end constraintViolationFor()

	/**
	 * Refuse a call whose arguments fall outside this agent's argument-scoped
	 * grant: the facade is never invoked, a structured `grant_constraint_violated`
	 * result goes back to the model, and a `tool` trace step with outcome
	 * `refused` records the tool, the offending argument and the constraint it
	 * violated.
	 *
	 * The permitted VALUES are recorded alongside the violation on purpose: an
	 * audit line saying only "an argument was wrong" cannot answer whether the
	 * boundary held, which is the whole question a reader of the trail is asking.
	 * They are the grant's own configured vocabulary, never user data, so there is
	 * nothing here to redact.
	 *
	 * @param string $name The LLPhant-side name.
	 * @param array<string, mixed> $arguments Decoded arguments.
	 * @param array{argument:string, mode:string, values:array<int,string>} $violation The violated constraint.
	 *
	 * @return string JSON-encoded refusal for the follow-up LLM turn.
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-a-pinned-argument-that-differs-is-refused-before-dispatch
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-text-the-model-read-cannot-widen-the-constraint
	 */
	private function handleConstraintViolation(string $name, array $arguments, array $violation): string {
		$toolId = $this->resolveToolId(name: $name);
		$argument = $violation['argument'];

		$traceToken = null;
		if ($this->trace !== null) {
			$traceToken = $this->trace->startStep(type: 'tool', name: $toolId);
		}

		$envelope = [
			'ok' => false,
			'isError' => true,
			'error' => 'grant_constraint_violated',
			'toolId' => $toolId,
			'argument' => $argument,
			'message' => "Argument '" . $argument . "' is not permitted by this agent's grant.",
		];

		if ($this->trace !== null && $traceToken !== null) {
			$this->trace->endStep(
				token: $traceToken,
				outcome: 'refused',
				extra: [
					'error' => 'grant_constraint_violated',
					'argument' => $argument,
					'constraint' => ['mode' => $violation['mode'], 'permitted' => $violation['values']],
				]
			);
		}

		$this->channel?->emitToolCall(payload: ['toolId' => $toolId, 'arguments' => $arguments]);
		$this->channel?->emitToolResult(payload: ['toolId' => $toolId, 'result' => $envelope, 'isError' => true]);

		$encoded = json_encode($envelope);
		if (is_string($encoded) === false) {
			return '{"ok":false,"isError":true,"error":"grant_constraint_violated"}';
		}

		return $encoded;
	}//end handleConstraintViolation()

	/**
	 * Whether this call queues a flow run but has no resolvable owning UID, and
	 * must therefore be refused (hydra-console-agent-leaves).
	 *
	 * @param string $name The LLPhant-side function name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-an-unresolvable-owner-refuses-the-invocation
	 */
	private function refusesForUnresolvedOwner(string $name): bool {
		if ($this->queuesFlowRun(name: $name) === false) {
			return false;
		}

		return (trim((string)$this->ownerUid) === '');
	}//end refusesForUnresolvedOwner()

	/**
	 * Whether `$name` resolves to a tool that queues a flow run.
	 *
	 * @param string $name The LLPhant-side function name.
	 *
	 * @return bool
	 */
	private function queuesFlowRun(string $name): bool {
		return in_array($this->resolveToolId(name: $name), self::FLOW_QUEUEING_TOOL_IDS, true);
	}//end queuesFlowRun()

	/**
	 * Refuse a flow-queueing invocation that could not be attributed: nothing is
	 * queued and the condition is recorded, never defaulted to an empty or system
	 * owner.
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return string JSON-encoded refusal for the follow-up LLM turn.
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#requirement-a-flow-invoked-as-an-agent-tool-is-attributed-to-an-owning-uid
	 */
	private function handleOwnerUnresolved(string $name, array $arguments): string {
		$toolId = $this->resolveToolId(name: $name);

		$traceToken = null;
		if ($this->trace !== null) {
			$traceToken = $this->trace->startStep(type: 'tool', name: $toolId);
		}

		$envelope = [
			'ok' => false,
			'isError' => true,
			'error' => 'owner_unresolved',
			'toolId' => $toolId,
			'message' => 'This run has no identified owner, so it cannot queue a flow run on anyone\'s behalf.',
		];

		if ($this->trace !== null && $traceToken !== null) {
			$this->trace->endStep(token: $traceToken, outcome: 'refused', extra: ['error' => 'owner_unresolved']);
		}

		$this->channel?->emitToolCall(payload: ['toolId' => $toolId, 'arguments' => $arguments]);
		$this->channel?->emitToolResult(payload: ['toolId' => $toolId, 'result' => $envelope, 'isError' => true]);

		$encoded = json_encode($envelope);
		if (is_string($encoded) === false) {
			return '{"ok":false,"isError":true,"error":"owner_unresolved"}';
		}

		return $encoded;
	}//end handleOwnerUnresolved()

	/**
	 * Carry this run's owning UID into a flow-queueing call, so the queued run is
	 * attributable to a person (hydra-console-agent-leaves).
	 *
	 * Mirrors `withAgentId()`: the value is server-side run state, never something
	 * the LLM may supply, so any caller-supplied key of the same name is
	 * OVERWRITTEN rather than trusted. `refusesForUnresolvedOwner()` has already
	 * refused the call when no owner exists, so reaching here with an empty owner
	 * is impossible; the guard is kept anyway because a silently empty owner is the
	 * exact failure this requirement exists to prevent.
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return array<string, mixed> Arguments, with the owner set when applicable.
	 *
	 * @spec openspec/changes/hydra-console-agent-leaves/specs/agent-tool-governance/spec.md#scenario-an-agent-queued-flow-run-names-the-acting-owner
	 */
	private function withFlowOwner(string $name, array $arguments): array {
		if ($this->queuesFlowRun(name: $name) === false) {
			return $arguments;
		}

		$owner = trim((string)$this->ownerUid);
		if ($owner === '') {
			return $arguments;
		}

		$arguments[self::FLOW_OWNER_ARGUMENT] = $owner;

		return $arguments;
	}//end withFlowOwner()

	/**
	 * Classify `$name` per the effective GuardrailPolicy's resolved
	 * `toolId => classification` map — `auto` when absent (agent-guardrails).
	 *
	 * @param string $name The LLPhant-side function name.
	 *
	 * @return string One of `auto`, `confirm`, `deny`.
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
	 */
	private function classifyTool(string $name): string {
		if ($this->toolPolicy === []) {
			return 'auto';
		}

		$toolId = $this->resolveToolId(name: $name);

		return ($this->toolPolicy[$toolId] ?? 'auto');
	}//end classifyTool()

	/**
	 * A `deny`-classified tool: never invoked, a refusal tool-result is
	 * returned to the LLM, and a `tool` trace step with outcome `denied` is
	 * recorded (spec: "record every ... tool denial ... as a trace step").
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return string JSON-encoded refusal for the follow-up LLM turn.
	 *
	 * @spec openspec/specs/agent-guardrails/spec.md#requirement-per-tool-risk-classification-enforced-before-invocation
	 */
	private function handleDeniedByPolicy(string $name, array $arguments): string {
		$toolId = $this->resolveToolId(name: $name);

		$traceToken = null;
		if ($this->trace !== null) {
			$traceToken = $this->trace->startStep(type: 'tool', name: $name);
		}

		$envelope = [
			'isError' => true,
			'error' => 'tool_denied_by_policy',
			'toolId' => $toolId,
			'message' => 'This action is denied by the organisation\'s guardrail policy and cannot be run.',
		];

		if ($this->trace !== null && $traceToken !== null) {
			$this->trace->endStep(token: $traceToken, outcome: 'denied');
		}

		$this->channel?->emitToolCall(payload: ['toolId' => $toolId, 'arguments' => $arguments]);
		$this->channel?->emitToolResult(payload: ['toolId' => $toolId, 'result' => $envelope, 'isError' => true]);

		$encoded = json_encode($envelope);
		if (is_string($encoded) === false) {
			return '{"isError":true,"error":"tool_denied_by_policy"}';
		}

		return $encoded;
	}//end handleDeniedByPolicy()

	/**
	 * A `confirm`-classified tool call: refused-then-retried, never
	 * paused-and-resumed (design.md Decision 4).
	 *
	 * 1. An APPROVED, UNCONSUMED `toolcall` Approval matching this exact
	 *    agent/tool/arguments combination, within the validity window, is
	 *    consumed (single-use) and the underlying tool IS invoked — this one
	 *    time.
	 * 2. A PENDING `toolcall` Approval for the same combination already
	 *    exists: no duplicate is created; the call is refused again
	 *    ("still awaiting approval").
	 * 3. Otherwise (first attempt, or a prior approval already consumed/
	 *    expired/denied): a new pending `toolcall` Approval is created and
	 *    the reviewer notified; the call is refused ("awaiting approval").
	 *
	 * No agent context (`agentId`/`approvalService` unavailable — agent-less
	 * chat, or a caller that omitted them) fails SAFE: the call is refused
	 * exactly like a `deny` classification, never silently allowed through,
	 * since there is no owner/reviewer to route an approval to.
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return string JSON-encoded outcome for the follow-up LLM turn.
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-a-confirm-classified-tool-call-reuses-the-existing-human-approval-gate
	 */
	private function handleConfirmClassifiedInvocation(string $name, array $arguments): string {
		$toolId = $this->resolveToolId(name: $name);

		if ($this->approvalService === null || $this->agentId === null) {
			return $this->refuseConfirmTool(
				toolId: $toolId,
				arguments: $arguments,
				status: 'unavailable',
				outcome: 'denied',
				message: 'This action requires human approval, but no agent context is available to route it — refused.'
			);
		}

		$correlationId = $this->toolCallCorrelationId(agentId: $this->agentId, toolId: $toolId, arguments: $arguments);

		$approvedUnconsumed = $this->approvalService->findApprovedUnconsumedToolCallApproval(correlationId: $correlationId);
		if ($approvedUnconsumed !== null) {
			$this->approvalService->markToolCallApprovalConsumed(approval: $approvedUnconsumed);
			return $this->dispatchToFacade(name: $name, arguments: $arguments, outcomeOverride: 'invoked_after_approval');
		}

		$pending = $this->approvalService->findPendingApprovalForToolCall(correlationId: $correlationId);
		if ($pending !== null) {
			return $this->refuseConfirmTool(
				toolId: $toolId,
				arguments: $arguments,
				status: 'pending',
				outcome: 'awaiting_approval',
				message: 'This action is still awaiting human approval.',
				approvalId: (string)$pending->getUuid()
			);
		}

		$approval = $this->approvalService->ensurePendingApprovalForToolCall(
			agentId: $this->agentId,
			toolId: $toolId,
			arguments: $arguments,
			correlationId: $correlationId
		);

		return $this->refuseConfirmTool(
			toolId: $toolId,
			arguments: $arguments,
			status: 'pending',
			outcome: 'awaiting_approval',
			message: 'This action requires human approval before it can run.',
			approvalId: (string)$approval->getUuid()
		);

	}//end handleConfirmClassifiedInvocation()

	/**
	 * Refuse a `confirm`-classified tool call, recording a `tool` trace step
	 * with the given outcome (spec: "record every ... tool confirm-request ...
	 * as a trace step") and emitting the streaming channel's frames.
	 *
	 * @param string $toolId The dotted tool id.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 * @param string $status The envelope's `status` field.
	 * @param string $outcome The trace step outcome.
	 * @param string $message A human-readable refusal message for the LLM.
	 * @param string|null $approvalId The pending Approval's UUID, when one exists.
	 *
	 * @return string JSON-encoded refusal for the follow-up LLM turn.
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
	 */
	private function refuseConfirmTool(
		string $toolId,
		array $arguments,
		string $status,
		string $outcome,
		string $message,
		?string $approvalId = null,
	): string {
		$traceToken = null;
		if ($this->trace !== null) {
			$traceToken = $this->trace->startStep(type: 'tool', name: $toolId);
		}

		$envelope = [
			'isError' => true,
			'error' => 'approval_required',
			'toolId' => $toolId,
			'status' => $status,
			'message' => $message,
		];

		if ($approvalId !== null) {
			$envelope['approvalId'] = $approvalId;
		}

		if ($this->trace !== null && $traceToken !== null) {
			$this->trace->endStep(token: $traceToken, outcome: $outcome);
		}

		$this->channel?->emitToolCall(payload: ['toolId' => $toolId, 'arguments' => $arguments]);
		$this->channel?->emitToolResult(payload: ['toolId' => $toolId, 'result' => $envelope, 'isError' => true]);

		$encoded = json_encode($envelope);
		if (is_string($encoded) === false) {
			return '{"isError":true,"error":"approval_required"}';
		}

		return $encoded;
	}//end refuseConfirmTool()

	/**
	 * Compute the `confirm`-classified tool call's idempotency/authorization
	 * key (design.md Decision 4): a stable hash of the agent, tool, and
	 * (key-sorted) arguments, so an argument-IDENTICAL retry resolves to the
	 * SAME correlationId regardless of PHP's array key order.
	 *
	 * @param string $agentId The acting agent's UUID.
	 * @param string $toolId The dotted tool id.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return string A sha256 hex digest.
	 */
	private function toolCallCorrelationId(string $agentId, string $toolId, array $arguments): string {
		$sorted = $arguments;
		ksort($sorted);

		$encoded = json_encode([$agentId, $toolId, $sorted], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (is_string($encoded) === false) {
			$encoded = $agentId . '|' . $toolId;
		}

		return hash('sha256', $encoded);
	}//end toolCallCorrelationId()

	/**
	 * The `hermiq.searchTools` meta-tool: ranks this run's resolved (already
	 * grant-filtered, default-denied) descriptor set against the query and
	 * returns matches directly — never a facade round-trip.
	 *
	 * @param array<string, mixed> $arguments Decoded arguments (`{"query": "..."}`).
	 *
	 * @return string JSON-encoded `{matches, count}`.
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#scenario-the-model-searches-for-and-then-invokes-a-deferred-tool
	 */
	private function handleSearchTools(array $arguments): string {
		$query = (string)($arguments['query'] ?? '');
		$matches = $this->toolSearchService->search(query: $query);

		$this->channel?->emitToolCall(payload: ['toolId' => 'hermiq.searchTools', 'arguments' => $arguments]);
		$this->channel?->emitToolResult(
			payload: ['toolId' => 'hermiq.searchTools', 'result' => ['matches' => $matches], 'isError' => false]
		);

		$encoded = json_encode(['matches' => $matches, 'count' => count($matches)]);
		if (is_string($encoded) === false) {
			return '{"matches":[],"count":0}';
		}

		return $encoded;
	}//end handleSearchTools()

	/**
	 * Whether `$name` must route through the human-approval gate: it is
	 * write/destructive-classified AND not part of this run's resolved
	 * (grant-filtered, default-denied) set.
	 *
	 * This check ONLY ever matters for a toolId NOT in `$toolSearchService`'s
	 * resolved set — a hallucinated / never-offered call, since a resolved
	 * (granted) toolId short-circuits `isGranted()` to `false`-gate regardless
	 * of classification. Any descriptor this turn HAS is nonetheless passed
	 * through: previously this call was id-only, which meant a declared hint and
	 * (now) a declared reach were visible on the default-deny path and invisible
	 * here, so the two call sites could in principle disagree about the same
	 * tool. They must not — a gate that classifies differently from the resolver
	 * that filled the catalogue is a gate with a seam in it. `descriptorFor()`
	 * returns null for exactly the ids this check actually classifies, and
	 * `requiresGrant()` fails closed on both axes when it does (a 2-segment id
	 * with no descriptor classifies write/destructive AND resolves to `external`
	 * reach), so threading the descriptor cannot loosen this path.
	 *
	 * @param string $name The LLPhant-side function name.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `ToolGrantResolver::requiresGrant()` is a
	 *   PURE classification function over an id and its descriptor. Injecting the resolver
	 *   would add a stateless collaborator and would let a caller substitute a more permissive
	 *   classifier into a security check — the opposite of what the seam is for.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-un-granted-destructive-tool-invocation-routes-through-the-approval-gate
	 * @spec openspec/specs/agent-tool-governance/spec.md#scenario-a-hint-less-curated-tool-fails-closed
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-default-deny-and-the-approval-gate-key-off-reach-in-union-with-the-existing-rule
	 */
	private function requiresApprovalGate(string $name): bool {
		if ($this->approvalService === null || $this->agentId === null || $this->toolSearchService === null) {
			return false;
		}

		$toolId = $this->resolveToolId(name: $name);
		if (ToolGrantResolver::requiresGrant(id: $toolId, descriptor: $this->descriptorFor(name: $name)) === false) {
			return false;
		}

		return $this->toolSearchService->isGranted(id: $toolId) === false;
	}//end requiresApprovalGate()

	/**
	 * This turn's descriptor for an LLPhant-side function name, when one was
	 * offered to the model.
	 *
	 * Null for a hallucinated or never-offered call — which is the case the
	 * approval gate exists for, and the case every classification here fails
	 * closed on.
	 *
	 * @param string $name The LLPhant-side function name.
	 *
	 * @return array<string,mixed>|null
	 */

	/**
	 * Whether this exact invocation is covered by a `#noapproval` grant entry.
	 *
	 * 🔴 What a waiver may and may not do, stated where it is read:
	 *
	 * It suppresses the human CONFIRMATION for a tool the owner has already
	 * granted, argument-constrained, and explicitly marked. It does NOT widen
	 * the grant (resolution ran before this and does not consult waivers), does
	 * NOT relax an argument constraint (`constraintViolationFor()` refused
	 * first), does NOT override an organisation's `deny` classification (that
	 * returned earlier), and does NOT touch OpenRegister RBAC, which authorises
	 * at invoke time and never sees this flag. A waiver on a tool the agent was
	 * never granted is inert, because an ungranted tool is never offered to the
	 * model in the first place.
	 *
	 * The arguments matter: a waiver rides on ONE grant entry, so
	 * `runFlow?flowId=A#noapproval` waives flow A and leaves a sibling grant for
	 * flow B still meeting a human.
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `ToolGrantResolver::waives()` is a PURE
	 *   decision over the grant grammar, exactly like `violationFor()` beside it.
	 *
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-the-waiver-suppresses-the-approval-gate-and-nothing-else
	 */
	private function isWaived(string $name, array $arguments): bool {
		if ($this->waivedConstraintSets === []) {
			return false;
		}

		return ToolGrantResolver::waives(
			$this->waivedConstraintSets,
			$this->resolveToolId(name: $name),
			$arguments
		);

	}//end isWaived()

	/**
	 * This turn's descriptor for an LLPhant-side function name, when one was
	 * offered to the model.
	 *
	 * Null for a hallucinated or never-offered call — which is the case the
	 * approval gate exists for, and the case every classification here fails
	 * closed on.
	 *
	 * @param string $name The LLPhant-side function name.
	 *
	 * @return array<string,mixed>|null
	 */
	private function descriptorFor(string $name): ?array {
		$descriptor = ($this->descriptorsByName[$name] ?? null);
		if (is_array($descriptor) === false) {
			return null;
		}

		return $descriptor;
	}//end descriptorFor()

	/**
	 * Consult (or create) the tool-invocation `Approval` and either dispatch to
	 * the facade (an already-`approved` decision) or block (pending/denied).
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return string JSON-encoded outcome for the follow-up LLM turn.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `ToolReachResolver::resolve()` is a PURE
	 *   classification function — see `requiresApprovalGate()`.
	 *
	 * @spec openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#scenario-an-agent-attempts-an-un-granted-destructive-tool-call
	 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-default-deny-and-the-approval-gate-key-off-reach-in-union-with-the-existing-rule
	 */
	private function handleGatedInvocation(string $name, array $arguments): string {
		$toolId = $this->resolveToolId(name: $name);

		$decided = $this->approvalService->findDecidedApprovalForToolInvocation(
			agentId: (string)$this->agentId,
			toolId: $toolId
		);

		if ($decided !== null && (string)($decided->getObject()['status'] ?? '') === 'approved') {
			return $this->dispatchToFacade(name: $name, arguments: $arguments);
		}

		// Name the reach that triggered the gate. A run trace otherwise cannot
		// tell a reach-triggered refusal from a verb-triggered one, and the two
		// want opposite remedies: a verb gate says "a human should confirm this
		// write", a reach gate says "this leaves the instance — grant it by name
		// or do not". The model reads this too, and "external" is far more
		// actionable to it than a bare `approval_required`.
		$envelope = [
			'isError' => true,
			'error' => 'approval_required',
			'toolId' => $toolId,
			'reach' => ToolReachResolver::resolve(
				toolId: $toolId,
				descriptor: $this->descriptorFor(name: $name)
			),
		];
		if ($decided !== null) {
			$envelope['status'] = 'denied';
			$envelope['approvalId'] = (string)$decided->getUuid();
			$envelope['message'] = 'This action was denied by a reviewer and cannot be run.';
		}

		if ($decided === null) {
			$approval = $this->approvalService->ensurePendingApprovalForToolInvocation(
				agentId: (string)$this->agentId,
				toolId: $toolId,
				arguments: $arguments
			);

			$envelope['status'] = 'pending';
			$envelope['approvalId'] = (string)$approval->getUuid();
			$envelope['message'] = 'This action requires human approval before it can run.';
		}

		$this->channel?->emitToolResult(payload: ['toolId' => $toolId, 'result' => $envelope, 'isError' => true]);

		$encoded = json_encode($envelope);
		if (is_string($encoded) === false) {
			return '{"isError":true,"error":"approval_required"}';
		}

		return $encoded;
	}//end handleGatedInvocation()

	/**
	 * Resolve the LLPhant-side function name back to the dotted `mcpId` the
	 * grant/approval logic classifies against.
	 *
	 * @param string $name The LLPhant-side function name.
	 *
	 * @return string
	 */
	private function resolveToolId(string $name): string {
		return ($this->mcpIdByName[$name] ?? $name);
	}//end resolveToolId()

	/**
	 * Inject this run's own `agentId` into `$arguments` for the three
	 * agent-memory-tools ids (see `MEMORY_TOOL_IDS` docblock) and
	 * `hermiq.delegateAgent` (see `DELEGATE_AGENT_TOOL_ID` docblock) ONLY —
	 * every other tool's arguments pass through byte-for-byte unchanged.
	 * Always overwrites any caller-supplied `agentId` key (the LLM's declared
	 * input schema for these tools never includes one, but defense-in-depth
	 * never trusts a caller-supplied identity field, matching this app's IDOR
	 * posture elsewhere): the run's own agentId is the only authoritative
	 * source — for `delegateAgent`, this is precisely what makes the
	 * "calling agent" identity `DelegationService` gates on trusted server-side
	 * state, never a tool-call argument the LLM could set. With no agent
	 * context (`$this->agentId === null`, agent-less chat), arguments are left
	 * unchanged — `HermiqToolProvider` then returns a structured "no agent
	 * context" error rather than guessing.
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return array<string, mixed> Arguments, with `agentId` set when applicable.
	 *
	 * @spec openspec/changes/agent-memory-tools/tasks.md#task-5
	 * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
	 */
	private function withAgentId(string $name, array $arguments): array {
		if ($this->agentId === null) {
			return $arguments;
		}

		$toolId = $this->resolveToolId(name: $name);
		if (in_array($toolId, self::MEMORY_TOOL_IDS, true) === false
			&& in_array($toolId, self::ARTEFACT_WRITE_TOOL_IDS, true) === false
			&& $toolId !== self::DELEGATE_AGENT_TOOL_ID
		) {
			return $arguments;
		}

		$arguments['agentId'] = $this->agentId;

		return $arguments;
	}//end withAgentId()

	/**
	 * The pre-existing plain dispatch: forward the call to
	 * `ToolRegistryFacade::invokeTool()`, emitting channel frames / trace step.
	 *
	 * @param string $name The tool function name the LLM called.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 * @param string|null $outcomeOverride A fixed trace-step outcome to record
	 *                                     instead of the facade's own ok/error
	 *                                     (agent-guardrails): `invoked_after_approval`
	 *                                     for a `confirm`-classified tool's authorised
	 *                                     retry, so the run history distinguishes it
	 *                                     from an ordinary `auto` invocation. Null
	 *                                     (every pre-existing caller) keeps the
	 *                                     original ok/error derivation unchanged.
	 *
	 * @return string JSON-encoded tool result for the follow-up LLM turn.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The single dispatch chokepoint carries
	 *   every optional per-run concern (channel frames, trace step, dry-run neutralisation,
	 *   outcome override, encode fallback) as one flat nullable-guard each — splitting it
	 *   would re-scatter the dry-run interception across governance branches.
	 *
	 * @spec openspec/changes/agent-guardrails/tasks.md#task-7-confirm-tool-retry-and-consume-flow-in-facadetoolinvoker
	 * @spec openspec/changes/run-replay-and-dry-run/tasks.md#task-2-facadetoolinvoker-dry-run-neutralisation-with-redacted-would-have-called-steps
	 */
	private function dispatchToFacade(string $name, array $arguments, ?string $outcomeOverride = null): string {
		$this->channel?->emitToolCall(
			payload: [
				'toolId' => $name,
				'arguments' => $arguments,
			]
		);

		$traceToken = null;
		if ($this->trace !== null) {
			$traceToken = $this->trace->startStep(type: 'tool', name: $name);
		}

		// Run-replay-and-dry-run: this is the ONLY place `invokeTool()` is ever
		// actually called, so intercepting here (rather than earlier in __call())
		// neutralises a side-effecting tool regardless of WHICH governance branch
		// reached it (plain auto dispatch, a confirm-classified tool's authorised
		// retry, or an already-approved gated invocation) — a single chokepoint,
		// never duplicated per branch.
		if ($this->dryRun === true && $this->isSideEffecting(name: $name) === true) {
			return $this->recordWouldHaveCalled(name: $name, arguments: $arguments, traceToken: $traceToken);
		}

		// The facade's return shape is a documented contract:
		// {result: array, isError: bool} (ai-mcp REQ-006).
		$envelope = $this->facade->invokeTool(
			toolId: $name,
			arguments: $this->withFlowOwner(
				name: $name,
				arguments: $this->withAgentId(name: $name, arguments: $arguments)
			)
		);

		if ($this->trace !== null && $traceToken !== null) {
			$outcome = 'ok';
			if ($envelope['isError'] === true) {
				$outcome = 'error';
			}

			if ($outcomeOverride !== null && $envelope['isError'] !== true) {
				$outcome = $outcomeOverride;
			}

			$this->trace->endStep(
				token: $traceToken,
				outcome: $outcome,
				extra: $this->traceExtraFor(name: $name, arguments: $arguments, result: $envelope['result'])
			);
		}

		$this->channel?->emitToolResult(
			payload: [
				'toolId' => $name,
				'result' => $envelope['result'],
				'isError' => $envelope['isError'],
			]
		);

		$encoded = json_encode($envelope['result']);
		if (is_string($encoded) === false) {
			return '{"error":"Tool result could not be encoded"}';
		}

		return $encoded;
	}//end dispatchToFacade()

	/**
	 * The `endStep()` `$extra` payload for `$name`'s trace step — `['target' =>
	 * ...]` for exactly `hermiq.webSearch`/`hermiq.webFetch` (web-research-tool),
	 * empty for every other tool (zero behavior change).
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 * @param mixed $result The facade's decoded tool result, from which an
	 *                      `artefact` descriptor is lifted when the tool produced
	 *                      one (ADR-088) — read from the RESULT because a newly
	 *                      created artefact has no id until the write returns.
	 *
	 * @return array<string, mixed> `['target' => string]` and/or `['artefact' => ...]`, or `[]`.
	 *
	 * @spec openspec/changes/web-research-tool/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	private function traceExtraFor(string $name, array $arguments, mixed $result = null): array {
		$toolId = $this->resolveToolId(name: $name);

		// ADR-088 §3: a write must be recorded with the identity of the artefact
		// it produced, or the oversight surface can say a tool succeeded without
		// saying on WHAT — a record an overseer cannot follow to the thing that
		// changed. Read from the RESULT, not the arguments, because the id of a
		// newly created artefact does not exist until the write returns.
		//
		// Deliberately app-agnostic: any tool from any app that returns an
		// `artefact` descriptor gets it recorded, so DocuDesk's document tools
		// need no change here. Only `type` and `id` are lifted, and only as
		// scalars — the no-content rule is enforced by the shape, not by trusting
		// each tool to omit content.
		$artefact = $this->resolveArtefactDescriptor(result: $result);

		if (in_array($toolId, self::WEB_RESEARCH_TOOL_IDS, true) === false) {
			return $artefact;
		}

		$target = $this->resolveWebResearchTarget(toolId: $toolId, arguments: $arguments);
		if ($target === null) {
			return $artefact;
		}

		return array_merge($artefact, ['target' => $target]);
	}//end traceExtraFor()

	/**
	 * Lift an `artefact` descriptor out of a tool result for the audit record.
	 *
	 * Returns `['artefact' => ['type' => string, 'id' => string]]` when the result
	 * carries one, `[]` otherwise. Non-scalar members are dropped rather than
	 * stringified, so a tool cannot smuggle a body into the trace by nesting it
	 * under a key this method reads.
	 *
	 * @param mixed $result The facade's decoded tool result.
	 *
	 * @return array<string, mixed> The trace extra fragment.
	 *
	 * @spec openspec/changes/nc-native-write-tools/specs/nc-native-tools/spec.md#requirement-every-write-is-recorded-with-the-objects-identity-and-without-its-content
	 */
	private function resolveArtefactDescriptor(mixed $result): array {
		if (is_array($result) === false || isset($result['artefact']) === false) {
			return [];
		}

		$artefact = $result['artefact'];
		if (is_array($artefact) === false) {
			return [];
		}

		$type = ($artefact['type'] ?? null);
		$id = ($artefact['id'] ?? null);
		if (is_scalar($type) === false || is_scalar($id) === false) {
			return [];
		}

		return ['artefact' => ['type' => (string)$type, 'id' => (string)$id]];
	}//end resolveArtefactDescriptor()

	/**
	 * Reduce a web-research tool call's arguments to its auditable target:
	 * `webFetch`'s URL reduced to host+path (query string dropped ENTIRELY, never
	 * selectively masked — a search/fetch query string is exactly where a
	 * secret-shaped value could accidentally end up); `webSearch`'s raw query text
	 * (not a URL, so there is no host+path to reduce it to), length-capped.
	 *
	 * @param string $toolId The dotted tool id (`hermiq.webSearch` or
	 *                       `hermiq.webFetch`).
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return string|null The target, or null when the relevant argument is absent/empty.
	 *
	 * @spec openspec/changes/web-research-tool/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	private function resolveWebResearchTarget(string $toolId, array $arguments): ?string {
		if ($toolId === 'hermiq.webFetch') {
			return $this->reduceUrlToHostAndPath(url: (string)($arguments['url'] ?? ''));
		}

		$query = trim((string)($arguments['query'] ?? ''));
		if ($query === '') {
			return null;
		}

		if (strlen($query) > self::MAX_SEARCH_QUERY_TARGET_LENGTH) {
			return substr($query, 0, self::MAX_SEARCH_QUERY_TARGET_LENGTH) . '…';
		}

		return $query;
	}//end resolveWebResearchTarget()

	/**
	 * Reduce a URL to `host` + `path` only — the query string is dropped entirely,
	 * never selectively masked (design.md: simpler and safer than
	 * `RedactionService::redactQueryString()`'s per-field masking).
	 *
	 * @param string $url The URL to reduce.
	 *
	 * @return string|null The `host+path`, or null when `$url` has no parseable host.
	 */
	private function reduceUrlToHostAndPath(string $url): ?string {
		$parts = parse_url($url);
		if (is_array($parts) === false || empty($parts['host']) === true) {
			return null;
		}

		return $parts['host'] . (string)($parts['path'] ?? '');
	}//end reduceUrlToHostAndPath()

	/**
	 * Whether `$name` is side-effecting per the resolved `ToolClassificationService`
	 * (run-replay-and-dry-run) — fail-safe closed when no classifier is wired
	 * (should not happen given the constructor default, but never trust that
	 * blindly at the point a real invocation would otherwise fire).
	 *
	 * @param string $name The LLPhant-side function name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
	 */
	private function isSideEffecting(string $name): bool {
		if ($this->classifier === null) {
			return true;
		}

		$toolId = $this->resolveToolId(name: $name);
		$descriptor = ($this->descriptorsByName[$name] ?? null);

		return $this->classifier->isSideEffecting(id: $toolId, descriptor: $descriptor);
	}//end isSideEffecting()

	/**
	 * Neutralise a side-effecting tool call during a dry-run: never invoke the
	 * facade, record a `tool` trace step with `outcome='would-have-called'`
	 * carrying REDACTED arguments (the one narrow exception to this class's
	 * "never raw arguments" rule), and return a synthetic, clearly-labelled
	 * result so the LLM's multi-step plan can keep reasoning realistically.
	 *
	 * @param string $name The LLPhant-side function name.
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 * @param int|null $traceToken The trace step token already started
	 *                             by `dispatchToFacade()`, or null when
	 *                             no collector is attached.
	 *
	 * @return string JSON-encoded preview envelope for the follow-up LLM turn.
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
	 */
	private function recordWouldHaveCalled(string $name, array $arguments, ?int $traceToken): string {
		$toolId = $this->resolveToolId(name: $name);
		$redactedArguments = $this->redactArguments(arguments: $arguments);

		if ($this->trace !== null && $traceToken !== null) {
			$this->trace->endStep(
				token: $traceToken,
				outcome: 'would-have-called',
				extra: ['arguments' => $redactedArguments]
			);
		}

		$envelope = [
			'preview' => true,
			'toolId' => $toolId,
			'message' => "Dry-run preview: '{$toolId}' was NOT actually invoked — this is a simulated result.",
		];

		$this->channel?->emitToolResult(
			payload: [
				'toolId' => $toolId,
				'result' => $envelope,
				'isError' => false,
				'preview' => true,
			]
		);

		$encoded = json_encode($envelope);
		if (is_string($encoded) === false) {
			return '{"preview":true}';
		}

		return $encoded;
	}//end recordWouldHaveCalled()

	/**
	 * Redact a tool call's arguments before they reach the trace/audit record
	 * (ADR-004 redaction-before-persist, extended to the ONE step outcome that
	 * carries arguments at all — see class docblock). JSON-encodes the
	 * argument object, redacts the encoded blob with the same
	 * `RedactionService::redact()` every other free-text audit field already
	 * passes through, then decodes it back so the trace keeps a structured
	 * `arguments` object rather than an opaque string wherever possible.
	 *
	 * @param array<string, mixed> $arguments Decoded arguments object.
	 *
	 * @return array<string, mixed> The redacted arguments (or a single opaque
	 *                              placeholder key when redaction could not be
	 *                              round-tripped as JSON — never the raw values).
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
	 */
	private function redactArguments(array $arguments): array {
		if ($this->redactionService === null) {
			// Fail-safe: no redactor wired — never expose raw values.
			return array_fill_keys(array_keys($arguments), '«redacted»');
		}

		$encoded = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (is_string($encoded) === false) {
			return array_fill_keys(array_keys($arguments), '«redacted»');
		}

		$redactedEncoded = $this->redactionService->redact($encoded);
		$decoded = json_decode($redactedEncoded, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		// The redaction pass altered the JSON shape (rare) — fall back to a
		// single opaque string rather than losing the redaction outcome.
		return ['_redacted' => $redactedEncoded];
	}//end redactArguments()
}//end class
