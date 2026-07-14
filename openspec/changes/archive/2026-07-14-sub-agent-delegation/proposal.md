# Proposal: sub-agent-delegation

## Summary
Adds a governed `agent.delegate` tool (registry id `hermiq.delegateAgent`) so one Hermiq agent can
invoke another agent — in the same organisation — as a bounded sub-task, with its own prompt/model/
tools/capability profile, in an isolated conversation the parent never sees, returning a text result
into the parent's tool loop. Delegation is sequential only (no parallel workers), bounded by a
per-agent allowlist (default: may delegate to none), a low-default max depth and max fan-out, and
inherits the SAME kill-switch, budget hard-cap, and acting-user attribution the rest of Hermiq's
governed dispatch already enforces — reusing those gates rather than inventing a second enforcement
path a sub-run could slip through.

## Motivation
Sub-agent delegation — isolated parallel sub-agent contexts for concurrent workstreams — is the
single most common "advanced" capability across the competitive field: Claude Agent SDK (subagents +
hooks), OpenAI Agents SDK (handoffs/sub-agents), CrewAI (role-based crews), AutoGen (multi-agent group
chat), LangGraph (supervisor/subgraph patterns), SuperAGI (concurrent multi-agent runs), Letta
(multi-agent shared memory), Lindy (agent societies), and OpenHands (micro-agent orchestration) all
ship some form of it. Hermiq has none: one agent is one linear `ToolLoop` with no way to compose
agents into a larger task. This is a genuine gap (Spectr DB: `competitor_features` WHERE
`app_slug='hermiq' AND provided_by='gap' AND resolved_by LIKE '%sub-agent-delegation%'`), not one an
existing abstraction (nextcloud/openregister/openconnector/n8n-nextcloud) already resolves — none of
those own "one Hermiq agent calling another."

The same research that surfaces this gap also flags its risk: uncontrolled delegation is exactly
where runaway cost and unattributable actions come from (an agent quietly spawning other agents, each
spending budget and acting under an identity nobody chose). Hermiq already owns the governance rails
this needs — `ScheduleService`'s kill-switch/budget/approval gates, `BudgetService`'s hard cap,
`TenantModelPolicyService`'s per-org model allowlist, and the redacted `AuditTrail` — so this change is
scoped to plug a new, bounded entry point into those EXISTING rails, not to build a second one.

## Affected Projects
- [x] Project: `hermiq` — new `agent.delegate` tool (`hermiq.delegateAgent`), a new `DelegationService`
  governed dispatcher, a new `DelegationContext` call-stack tracker, an `Agent.delegationAllowlist`
  schema field, two new attribution/anchor parameters on `ScheduleService::runAgentAsOwner()`, and a
  small Agent-detail UI control to edit the allowlist.

## Scope

### In Scope
- A **`hermiq.delegateAgent` tool**, added to the existing `HermiqToolProvider` catalogue (OpenRegister
  discovers exactly one MCP provider per app — see design.md — so this is a sixth capability on that
  provider, not a second provider class). Arguments: `targetAgentId` (uuid), `task` (string prompt).
  Returns the sub-agent's text result, or a structured `{error:{code,message}}` envelope on refusal —
  never throws, matching the provider's existing contract.
- **Isolated sub-agent execution**: the sub-agent runs with its OWN `prompt`/`provider`/`model`/`tools`
  in a FRESH `Conversation` object it cannot see the parent's history through — reusing
  `ScheduleService::runAgentViaEngine()`'s existing "new conversation, run `Engine::processMessage()`"
  path (the same one a scheduled/flow-triggered run already uses), not a new execution mechanism.
- **Delegation allowlist** — a new `Agent.delegationAllowlist` field (array of Agent UUIDs). Empty
  (the default) means the agent may delegate to no one. An agent may never appear in its own
  allowlist's effective resolution path: direct self-delegation and any cyclic chain (target already
  present in the current call's ancestor chain) are refused before anything else is checked.
- **Bounded recursion**: a configurable, low-default max delegation depth (default 2) and max fan-out
  per agent turn (default 3), tracked by a new request-scoped `DelegationContext` and enforced BEFORE
  the sub-agent is invoked — exceeding either is refused with a structured error and an audit entry,
  never a silent drop and never an infinite loop.
- **Same-organisation only**: the target agent MUST belong to the same organisation as the calling
  agent; cross-organisation delegation is refused unconditionally (no allowlist can override this).
- **Budget charged to the whole tree**: a delegated sub-run's `AuditTrail` entry is anchored to the
  SAME top-level trigger object (the originating `Schedule` or flow-triggering object) the top-level
  run is already anchored to, so `BudgetService`'s existing organisation-/agent-scoped usage
  aggregation counts the sub-run's tokens under the SAME budget the parent run counts against — no
  changes to `BudgetService` itself. `BudgetService::isBlocked()`/`checkAndDeliverWarnings()` are
  called, and `ScheduleService::isOrganisationEngaged()` (kill-switch), before every delegated
  invocation, exactly as they already gate a scheduled/flow-triggered run.
- **Attribution cannot be laundered**: the sub-agent runs as the PARENT's already-resolved acting
  user, never its own `actingUser` override — a new `forceOwner` parameter on
  `ScheduleService::runAgentAsOwner()` skips that resolution specifically for the delegation path.
- **Tenant-model-policy applies to the sub-agent**: its resolved provider/model must satisfy the SAME
  organisation's effective `ModelPolicy` `ProviderFactory` already enforces on every LLM call; the
  delegation gate also checks this up front for a clean refusal instead of a mid-run failure.
- **Traceable as one tree**: the delegate call is a `tool`-type step on the parent's existing
  `RunTraceCollector` timeline (the generic `FacadeToolInvoker` wrapper already times every tool call —
  no new step type needed), and the sub-run's own `AuditTrail` entry carries a fresh `runId` plus a
  `parentRunId` referencing the calling run's own `runId`, so the whole delegation tree is
  reconstructable from the audit trail.
- **Kill-switch and approval apply to sub-runs**: an engaged kill-switch refuses the delegation exactly
  like a scheduled tick; a target agent configured with `requiresApproval` is refused as a delegation
  target outright (see design.md Decision on why a synchronous mid-turn approval wait is not
  attempted).
- A minimal Agent-detail UI control (multi-select, mirroring the existing `tools` whitelist editor) to
  set `delegationAllowlist`.

### Out of Scope
- **True parallel/concurrent sub-agent execution.** Hermiq's cron dispatch is single-threaded
  (`ScheduleService::run()` iterates due schedules in a `foreach`) and delegation reuses that same
  single-threaded call chain — this change specs SEQUENTIAL delegation only (the parent's tool loop
  blocks on the sub-agent's result, exactly like any other tool call). A parallel-worker variant needs
  the blocked `hermiq-exec` ExApp and is a follow-up gated on that, not attempted here.
- **Shared mutable memory between agents** (Letta's model). A delegated sub-agent gets its own
  `Memory` object exactly like any other agent; nothing new is shared between parent and sub-agent
  beyond the one task string and its one text result.
- **Free-form agent-to-agent chat** (AutoGen group chat / Lindy societies). There is no back-and-forth
  conversation between parent and sub-agent, no multi-turn negotiation, and no sub-agent-initiated
  contact with the parent — one bounded task in, one text result out, once, per delegate call.
- **A visual delegation-tree/audit UI.** The `runId`/`parentRunId` correlation fields are written so a
  future view CAN reconstruct the tree; rendering that tree in `RunHistoryService`/Agent detail is not
  part of this change.
- **Cross-organisation delegation**, under any configuration — see In Scope.
- **Changing `BudgetService`'s usage-aggregation mechanism.** It already windows `action='run'` entries
  by the schedule UUIDs in scope; this change anchors delegated runs' audit entries so they fall inside
  that existing window rather than widening the window itself (see design.md Trade-offs for the one
  known residual gap this does NOT close).

## Approach
`HermiqToolProvider` gains a sixth tool descriptor, `hermiq.delegateAgent`, whose `invokeTool()` case
delegates to a new `DelegationService::delegate()`. That service runs a fixed, ordered set of
synchronous refusal checks (self/cycle → allowlist → depth → fan-out → same-organisation →
model-policy → kill-switch → budget → target-requires-approval), reading the current call's position
in a new `DelegationContext` (a plain, request-scoped call-stack: agent id, organisation, ancestor
chain, depth, fan-out count, the top-level audit anchor, and the current run's own `runId`). On
success it calls the EXISTING `ScheduleService::runAgentAsOwner()` — the same method a scheduled tick,
Run-now, and a flow-triggered run already call — with two new optional parameters: `forceOwner: true`
(skip the sub-agent's own `actingUser` resolution) and `anchor` (the object the sub-run's `AuditTrail`
entry, and hence its budget accounting, rolls up to). `runAgentAsOwner()` pushes/pops the
`DelegationContext` frame around its existing `Engine::processMessage()` call, so nested delegation
(up to `maxDepth`) sees a consistent, tamper-proof view of the call stack — the LLM's tool-call
arguments are never trusted for depth/ancestry, only for `targetAgentId`/`task`.

## New Dependencies
None. `runId` generation uses `symfony/uid` (`Symfony\Component\Uid\Uuid`), already a transitive
dependency via OpenRegister's `composer.json`.

## Impact
- **Backend**: new `lib/Service/DelegationService.php`, new `lib/Service/Engine/DelegationContext.php`,
  `HermiqToolProvider` (+1 tool descriptor/case, +1 constructor dependency), `ScheduleService` (+2
  optional params on `runAgentAsOwner()`, +`DelegationContext` push/pop in `runAgentViaEngine()`,
  +anchor pass-through from `runDue()`), `FlowAgentRunService` (+anchor pass-through), a new
  `Agent.delegationAllowlist` schema field (`lib/Settings/hermiq_register.json`, version bump), two new
  `IAppConfig` keys (`delegation.maxDepth`, `delegation.maxFanOut`).
- **Frontend**: `src/modals/AgentFormModal.vue` gains a delegation-allowlist multi-select (mirrors the
  existing `tools` field), sourced from the agent catalog.
- **Specs**: modifies `multi-tenant-ops` (budget/isolation now explicitly cover delegated runs) and
  `human-approval-gate` (kill-switch/approval semantics now explicitly cover delegated runs); adds an
  `Agent detail manages delegation in place` requirement to `agent-management-ui`.

## Cross-Project Dependencies
None. Delegation is entirely internal to Hermiq's existing `hermiq` OpenRegister register and its
existing OR tool-registry facade (`ToolRegistryFacade`) — no other apps-extra project produces or
consumes a new endpoint, and no cross-app RPC is introduced (gate-27).

## Risks

### Risk 1: A misconfigured allowlist could still create a long delegation chain across many agents
**Severity:** Medium — **Mitigation:** `maxDepth` is a hard, instance-wide cap independent of the
allowlist's shape — even a large mutual-allowlist graph cannot recurse past `maxDepth` calls deep, and
`maxFanOut` bounds how many sub-agents a single turn can spawn regardless of chain length. Both default
low (2/3) and are refused-with-audit, never silently truncated.

### Risk 2: Budget accounting for a delegated sub-run only rolls up correctly when the top-level run has a Schedule/flow-trigger anchor
**Severity:** Medium — **Mitigation:** every top-level entry point Hermiq has today (scheduled tick,
Run-now, flow/webhook trigger) already has such an anchor, so this covers all current triggers. A
future trigger that has no natural anchor object would need one added at that call site — flagged
explicitly in design.md rather than silently under-counted.

### Risk 3: Attribution laundering if `forceOwner` is forgotten at a future new call site
**Severity:** Medium — **Mitigation:** `forceOwner` defaults to `false` (today's existing behavior,
unchanged for every non-delegation caller); `DelegationService` is the ONLY call site that passes
`true`, and this is asserted by a dedicated unit test (`DelegationServiceTest`) that fails if a
sub-agent with its own `actingUser` set is ever impersonated as that instead of the parent's identity.

### Risk 4: A synchronous mid-turn approval wait is architecturally awkward
**Severity:** Low — **Mitigation:** rather than attempting it, a target agent with `requiresApproval`
is refused as a delegation target outright (structured error, not a pending Approval) — it can still
run via its own schedule/flow trigger, which DO support the existing async pause-and-resume gate.

## Rollback Strategy
Fully additive. Removing the `hermiq.delegateAgent` tool descriptor/case from `HermiqToolProvider`
restores byte-for-byte today's tool catalogue. `runAgentAsOwner()`'s two new parameters default to
today's exact behavior when omitted, so every existing caller (scheduled tick, Run-now,
`FlowAgentRunService`) is unaffected even mid-rollout. `Agent.delegationAllowlist` is additive
(default `[]`); removing it from the schema is a non-breaking field removal since no other feature
reads it. `DelegationService`/`DelegationContext` can be deleted wholesale with no residual state (no
new OR object type is introduced — delegation leaves only `AuditTrail` entries, which are append-only
and inert once nothing reads their `parentRunId`/`runId` fields).

## Open Questions
- Should `maxDepth`/`maxFanOut` become per-organisation (like `ModelPolicy`) rather than instance-wide?
  Provisional choice (see design.md): instance-wide `IAppConfig`, matching the `budget.eurPer1kTokens`/
  `engine.enabled` pattern — simplest, and a per-org override can be added later without a breaking
  change.
- Should a refused delegation (any reason) itself count toward `maxFanOut`? Provisional choice: no —
  only an actually-attempted (gate-passed) sub-run increments the fan-out counter, so a misconfigured
  agent that immediately gets refused on its first call can still retry with a different, allowed
  target within the same turn.
