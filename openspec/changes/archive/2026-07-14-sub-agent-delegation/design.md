# Design: sub-agent-delegation

## Architecture Overview
Delegation is a new TOOL, not a new engine. It re-enters the exact call chain a top-level run already
uses, through one new choke point (`DelegationService`) and one new piece of shared state
(`DelegationContext`):

```
Parent turn (Engine::processMessage, in-app Engine path only — see Decision 1)
  └─ ToolLoop / FacadeToolInvoker (existing, generic — every tool call is already a timed `tool` step)
       └─ ToolRegistryFacade::invokeTool('hermiq.delegateAgent', {targetAgentId, task})
            └─ HermiqToolProvider::invokeTool()  (existing provider, +1 case)
                 └─ DelegationService::delegate($targetAgentId, $task)
                      ├─ reads DelegationContext::current()   ← trusted state, NOT the LLM's args
                      ├─ GATE 0  self/cycle             (ancestor chain)
                      ├─ GATE 1  allowlist               (Agent.delegationAllowlist)
                      ├─ GATE 2  depth / fan-out         (DelegationContext, IAppConfig caps)
                      ├─ GATE 3  same-organisation-only
                      ├─ GATE 4  tenant-model-policy      (TenantModelPolicyService::isAllowed)
                      ├─ GATE 5  kill-switch              (ScheduleService::isOrganisationEngaged)
                      ├─ GATE 6  budget hard-cap          (BudgetService::isBlocked/checkAndDeliverWarnings)
                      ├─ GATE 7  target requires approval → refuse outright (no sync wait — Decision 4)
                      └─ ScheduleService::runAgentAsOwner(
                             owner: <parent's already-impersonated acting uid>,
                             agentId: $targetAgentId, prompt: $task,
                             forceOwner: true,             ← attribution cannot launder (Decision 2)
                             anchor: DelegationContext::current()->anchor)
                             └─ runAgentViaEngine()
                                  ├─ DelegationContext::push(frame')   ← depth+1, ancestors+[agentId]
                                  ├─ new Conversation (isolated — sub-agent never sees parent history)
                                  ├─ Engine::processMessage(...)        (may itself delegate further,
                                  │                                       up to maxDepth)
                                  └─ DelegationContext::pop()
                      └─ writeDelegationAudit()  (own AuditTrail entry, anchored to the SAME top-level
                                                   anchor → BudgetService's existing aggregation counts
                                                   it; carries runId + parentRunId)
```

Everything left of `DelegationService` is unchanged. Everything right of it (`runAgentAsOwner` onward)
is the SAME code path a scheduled tick, Run-now, or a flow-triggered run already executes — delegation
adds exactly one new caller of that existing method, plus the two new parameters it needs.

## Goals / Non-Goals
**Goals**: bounded, attributable, auditable sequential delegation; zero new enforcement surface (every
gate is a reused existing service); the LLM's tool arguments are never trusted for anything
security-relevant (only `targetAgentId`/`task` — never depth, ancestry, organisation, or acting user).

**Non-Goals**: parallel execution; shared memory; free-form agent chat; a delegation-tree UI; changing
how `BudgetService` computes usage; cross-organisation delegation under any configuration.

## Decisions

### Decision 1: The tool only works on the in-app Engine path (`engine.enabled=true`)
`ToolLoop`/`FacadeToolInvoker` — the components that actually dispatch a tool call the LLM selects —
only exist on Hermiq's in-app `Engine` path. On the default (flag-off) OpenRegister `ChatService` path,
LLM-selected tool-calling is itself blocked upstream (`HermiqToolProvider`'s own docblock: "OR#269 …
Ollama tool-calling returns HTTP 400"). So `hermiq.delegateAgent` is registered in the SAME
app-wide tool catalogue either path reads (`ToolRegistryFacade`), but is only ever actually reachable
by an LLM once `engine.enabled=true` — identical to every other `HermiqToolProvider` tool today.
**Alternative rejected**: threading `DelegationContext` through OpenRegister's `ChatService` (a
different repo) — out of scope and unnecessary, since tool-calling doesn't work there yet regardless.

### Decision 2: `forceOwner` on `runAgentAsOwner()`, not a second method
`runAgentAsOwner(string $owner, string $agentId, string $prompt, bool $forceOwner = false, ?ObjectEntity $anchor = null)`.
When `$forceOwner` is `true`, the existing `resolveActingUser($agentId, fallbackOwner: $owner)` call is
skipped entirely and `$owner` is used verbatim as the impersonation target — this is the ONLY change
needed to stop a sub-agent's own `Agent.actingUser` from silently overriding the parent's identity
(attribution laundering). `DelegationService` is the only caller that ever passes `forceOwner: true`;
every existing caller (scheduled tick, Run-now, `FlowAgentRunService`) omits it and is byte-for-byte
unaffected. **Alternative rejected**: a parallel `runSubAgentAsOwner()` method — would duplicate the
impersonation try/finally and the engine-flag branch verbatim; a two-parameter addition to the existing
method is the smaller, more reviewable diff.

### Decision 3: `DelegationContext` is a plain, request-scoped stack — not persisted, not a new OR object
A `DelegationContext` frame is `{runId, agentId, organisation, anchor, depth, fanOutCount,
ancestorAgentIds[]}`. Nextcloud's DI container hands out one shared instance of an injected class per
HTTP/cron request by default (no special registration needed) — the SAME pattern `RunTraceCollector`
already relies on being threaded consistently through one call chain. `runAgentAsOwner()` pushes a new
frame immediately before `Engine::processMessage()` and pops it in a `finally`, so the frame that
`DelegationService::delegate()` reads when a NESTED delegate call fires is always the CURRENT run's
frame, never a stale one from an earlier, already-finished run in the same PHP process (there is none —
one Nextcloud request/cron tick runs one top-level agent turn). `depth`/`ancestorAgentIds`/
`fanOutCount` are trusted precisely because they come from this server-side stack, never from the tool
call's arguments. **Alternative rejected**: passing depth/ancestry as tool arguments the LLM must
"honestly" repeat back — trivially bypassable, defeats the entire governance point.

### Decision 4: An approval-gated agent cannot be a delegation target — refused, not queued
Reusing `ApprovalService`'s pending-approval-and-resume flow for a delegate call would require the
PARENT's tool loop to pause mid-turn, return control to a human, and resume the SAME in-flight LLM
conversation an indeterminate time later — a capability neither `ToolLoop` nor LLPhant's synchronous
function-calling loop has, and building it is a materially larger change than this proposal's scope.
Instead, `DelegationService` checks the target agent's `requiresApproval` field (read the same way
`ScheduleService::dispatch()` reads a schedule's) and refuses with a clear `delegation_requires_approval`
error BEFORE any budget/kill-switch work — the target agent remains fully usable via its own schedule
or flow trigger, which DO support the existing async gate. **Alternative rejected**: building a
sub-agent-turn pause/resume primitive — deferred; filed as a follow-up if real usage demands it.

### Decision 5: Budget anchor threading, not a `BudgetService` change
`BudgetService::currentUsageTokens()` windows `action='run'` `AuditTrail` entries by matching
`objectUuid` against the Schedule UUIDs in scope for an organisation/agent
(`loadScheduleUuidsForScope()`). Rather than teaching `BudgetService` a second, delegation-aware way to
find in-scope runs, every top-level entry point already has a natural anchor object
(`ScheduleService::runDue()` has `$schedule`; `FlowAgentRunService::runAgentAndWriteBack()` has the
triggering `$object`) — `runAgentAsOwner()`'s new `anchor` parameter carries that object down, and
`DelegationContext` re-uses the SAME anchor for every nested delegation call in the tree, however deep.
`DelegationService`'s own `AuditTrail` write for the sub-run uses that anchor as its `object`, so it
lands inside the EXACT SAME window `BudgetService` already scans — "the parent's budget is charged for
the whole tree" falls out of the EXISTING aggregation, with zero changes to `BudgetService`.
**Trade-off accepted**: a top-level entry point with no natural anchor object would not roll up
correctly — none exists today (see Risks), but a future one would need this considered explicitly.

### Decision 6: Depth/fan-out caps are instance-wide `IAppConfig`, not per-agent schema fields
`delegation.maxDepth` (default `'2'`) and `delegation.maxFanOut` (default `'3'`), read via
`IAppConfig::getValueString(Application::APP_ID, ...)` exactly like `engine.enabled` and
`budget.eurPer1kTokens`. **Alternative considered**: per-agent fields on the `Agent` schema — rejected
for v1 because these are safety-valve limits an instance admin sets once, not per-agent tuning knobs;
`Agent.delegationAllowlist` (who) is per-agent because it is inherently a per-agent authorization
decision, but depth/fan-out (how much) is not.

## API Design
No new REST endpoint. The only new "interface" is the MCP tool descriptor consumed via the existing
`ToolRegistryFacade`/`FacadeToolInvoker` mechanism:

### Tool: `hermiq.delegateAgent`
**Input:**
```json
{ "targetAgentId": "agent-uuid", "task": "Summarise the last 5 support tickets for account X." }
```
**Success result (JSON-encoded, fed back to the LLM as the tool turn):**
```json
{ "targetAgentId": "agent-uuid", "result": "<the sub-agent's text output>" }
```
**Refusal result (never throws):**
```json
{ "error": { "code": "delegation_depth_exceeded", "message": "Delegation refused: maximum delegation depth (2) reached." } }
```
Error codes: `delegation_target_not_found`, `delegation_self`, `delegation_cycle`,
`delegation_not_allowed` (not on the caller's allowlist), `delegation_depth_exceeded`,
`delegation_fanout_exceeded`, `delegation_cross_organisation`, `delegation_model_policy`,
`delegation_killswitch`, `delegation_budget_exhausted`, `delegation_requires_approval`.

## Database Changes
No Nextcloud DB migration. `Agent.delegationAllowlist` is an additive OpenAPI schema property in
`lib/Settings/hermiq_register.json` (Agent bumps `0.2.0` → `0.3.0`), imported via the existing Repair
step — the same mechanism every prior schema addition in this app uses.

### Schema: `Agent` (additive field)
| Field | Type | Notes |
|---|---|---|
| `delegationAllowlist` | array of uuid (`$ref Agent`), default `[]` | Agent UUIDs this agent may delegate a sub-task to via `hermiq.delegateAgent`. Empty (default) = may delegate to no one. |

### New `IAppConfig` keys (app `hermiq`)
| Key | Default | Notes |
|---|---|---|
| `delegation.maxDepth` | `'2'` | Max delegation chain length (1 = top-level run only, no further reads this key). |
| `delegation.maxFanOut` | `'3'` | Max delegate calls a single agent turn (one frame) may make. |

## Nextcloud Integration
- **Controllers**: none new — no REST surface.
- **Services**: `DelegationService` (new) — the governed dispatcher described above. `DelegationContext`
  (new, `lib/Service/Engine/`) — plain in-memory call-stack, no I/O, mirrors `RunTraceCollector`'s
  "pure-PHP value object" shape. `ScheduleService` gains `forceOwner`/`anchor` params on
  `runAgentAsOwner()` and pushes/pops `DelegationContext` in `runAgentViaEngine()`.
  `FlowAgentRunService` passes its triggering `$object` as `anchor`.
- **Mappers/Entities**: reuses `ObjectService`, `AgentMapper`, `AuditTrailMapper`,
  `RedactionService` — no new mapper.
- **MCP**: `HermiqToolProvider` (existing, one-provider-per-app per OpenRegister's
  `McpToolsService::getProviders()` discovery — see codebase note in `Application.php`) gains one new
  `TOOL_DESCRIPTORS` entry and one new `invokeTool()` case, plus a new constructor dependency
  (`DelegationService`).
- **Events/Hooks**: none new.

## Security Considerations
- The LLM's tool-call arguments (`targetAgentId`, `task`) are the ONLY untrusted input. Every
  governance decision (depth, ancestry, organisation, acting user) is read from server-side state
  (`DelegationContext`, the resolved `Agent`/`Schedule` OR objects, `IUserSession`) — never from the
  tool call itself.
- Same-organisation-only is enforced unconditionally, with no configuration path to relax it — a
  cross-tenant delegation would be a direct tenant-isolation breach (`multi-tenant-ops`).
- `forceOwner: true` is the ONLY new attribution behavior; it is exercised solely by
  `DelegationService`, never exposed as a caller-configurable flag anywhere in the UI or API.
- Refusals never throw and never abort the parent's run — a governance block degrades to a clear,
  LLM-visible error message, exactly like every other `HermiqToolProvider` tool failure today.
- The kill-switch, budget hard-cap, and (via refusal) the approval gate all apply to a delegated
  sub-run before it starts; none of them can be bypassed by nesting the call inside a tool turn.

## NL Design System
The one new UI surface — the `delegationAllowlist` multi-select in `AgentFormModal.vue` — reuses the
EXACT existing `NcSelect` + `inputLabel` + hint-paragraph pattern already shipped for the `tools` field
(see `AgentFormModal.vue`'s "Enabled tools" field); no new component, no hardcoded colors.

## File Structure
```
lib/
  Service/
    DelegationService.php            (new)
    ScheduleService.php               (+ forceOwner/anchor params, + DelegationContext push/pop)
    FlowAgentRunService.php           (+ anchor pass-through)
    Engine/
      DelegationContext.php          (new)
  Mcp/
    HermiqToolProvider.php           (+ 1 tool descriptor/case, + DelegationService dependency)
  Settings/
    hermiq_register.json             (+ Agent.delegationAllowlist, version bump)
appinfo/
  info.xml                           (patch version bump — register re-import is version-gated)
src/
  modals/
    AgentFormModal.vue               (+ delegationAllowlist multi-select)
l10n/
  en.json / nl.json                  (+ new UI strings)
tests/
  Unit/
    Service/
      DelegationServiceTest.php      (new)
```

## Seed Data
No new OR object type is introduced, so no new seed objects are required. The 3 existing seeded
agents (per prior changes' seed data) are left with `delegationAllowlist: []` (default) — a fresh
install ships with delegation available but inert until an admin explicitly configures an allowlist,
matching the "default empty = may delegate to none" invariant.

## Trade-offs
- **Anchor-reuse vs. widening `BudgetService`'s aggregation window**: reusing the top-level anchor
  (Decision 5) is a smaller, safer diff than teaching `BudgetService` a new "delegation-run" concept,
  but it means a hypothetical FUTURE trigger with no natural anchor object would under-count delegated
  spend against an org-scoped budget. No such trigger exists today (every current entry point has one);
  flagged as a design constraint any future trigger integration must satisfy, not fixed pre-emptively.
- **Refuse-outright vs. queue for approval (Decision 4)**: rejects a real (if narrow) use case — "let a
  human approve this specific sub-delegation" — in favor of shipping a correct, simple synchronous
  model now. Revisit only if usage data shows this refusal is a frequent, real blocker.
- **Instance-wide depth/fan-out caps (Decision 6) vs. per-org**: simpler to reason about and matches
  the existing `engine.enabled`/`budget.eurPer1kTokens` precedent, at the cost of one admin not being
  able to grant a higher cap to one specific organisation without raising it instance-wide. Acceptable
  for a first version of a deliberately bounded capability.

## Open Questions
(carried from proposal.md — resolved provisionally there; no new ones identified during design)
