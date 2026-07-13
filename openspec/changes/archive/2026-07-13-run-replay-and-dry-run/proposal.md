# Proposal: run-replay-and-dry-run

## Summary
Adds two related, trust-building ways to run a Hermiq agent without fully committing to it: **dry-run**
(preview) executes an agent's real prompt/model/tools but neutralises every side-effecting tool call
into a recorded `would-have-called` step instead of actually invoking it, and **replay** re-runs a past
run's exact prompt against the same agent, always as a dry-run, so a user can compare the new tool
sequence and LLM output against what actually happened. Both reuse the existing governed dispatch
(kill-switch → budget → approval) and the existing `run-trace-observability` step timeline and
`run-audit-log` per-run entry — no new logging system, no new engine.

## Motivation
Spectr's competitor sweep (`competitor_features` WHERE `app_slug='hermiq'`, `resolved_by LIKE
'%run-replay%'`) names this gap against AgentOps' time-travel debugging and LangGraph's
checkpointing/time-travel, and a deferred wave-1 research user story is even more direct: "I want to
preview what an agent would do without it actually doing it." That is the #1 trust blocker for handing
an autonomously-scheduled agent to a non-technical colleague — today the only way to see what an agent
*would* do is to let it actually do it (Run now) and inspect the aftermath. Investigation at HEAD
confirms Hermiq has all the pieces to build this cheaply but not the capability itself:
`FacadeToolInvoker::__call()` (`lib/Service/Engine/FacadeToolInvoker.php`) is the single chokepoint
every tool call passes through on the in-app Engine path, and `run-trace-observability`'s
`RunTraceCollector` already records one step per tool call — but there is no `dryRun` flag anywhere in
this chain, `ToolRegistryFacade::listTools()` (`../openregister/lib/Service/Mcp/ToolRegistryFacade.php`)
exposes no side-effect classification on a tool descriptor, and `run-trace-observability`'s own Out of
Scope explicitly deferred both "Replay / checkpointing" and "Storing raw tool arguments... in the
trace" — this change closes both, deliberately and narrowly.

## Affected Projects
- [x] Project: `hermiq` — adds dry-run execution (tool neutralisation + synthetic results), a per-tool
  side-effect classification map, replay-from-trace with a step diff, and the corresponding UI actions
  in the existing Schedule/Run-history section of `AgentDetail.vue`.
- [ ] Project: `openregister` — no code change here; see Risk 1 for the upstream issue this change
  recommends filing (`ToolRegistryFacade` carries no side-effect flag today).

## Scope

### In Scope
- A `dryRun` flag threaded end-to-end: `ScheduleService::runAgentAsOwner()` →
  `runAgentViaEngine()` → `Engine::processMessage()` → `ResponseGenerationHandler::generateResponse()`
  → `ToolLoop::buildFunctionInfos()` → `FacadeToolInvoker`.
- A Hermiq-owned per-tool side-effect classification (`ToolClassificationService` or equivalent),
  keyed by the `{appId}.{toolName}` registry id, with a **fail-safe default of `sideEffecting`** for
  any tool not explicitly classified as `readOnly` — because `ToolRegistryFacade::listTools()` returns
  no such flag today (verified: descriptors carry only `name`/`description`/`parameters`/`mcpId`).
- Dry-run behavior in `FacadeToolInvoker::__call()`: a `readOnly` tool is invoked for real (accurate
  preview data, no side effect to neutralise); a `sideEffecting` (or unclassified) tool is NOT invoked —
  instead the call is recorded as one trace step with `outcome='would-have-called'` and its (redacted)
  arguments, and a synthetic, clearly-labelled result is returned to the LLM so a multi-step plan can
  continue reasoning realistically.
- Every dry-run's scratch `Conversation`/`Message` objects (the same throw-away conversation
  `runAgentViaEngine()` already creates per scheduled run) are deleted once the turn completes
  (success or failure) — a preview leaves no persisted transcript/memory behind, only the `dryRun:true`
  run-history entry itself.
- A `dryRun: true` marker (and, for replay, `replayOf: <original runId>`) in the per-run `AuditTrail`
  context `run-audit-log` already writes, so a dry-run/replay run can never be mistaken for a real one
  in run history, and is excluded from `AnalyticsService`'s status/success-rate breakdown (its token
  usage still counts toward `BudgetService` spend — real tokens were spent).
- Persisting the exact `prompt` text used for a run into that run's audit context (a one-line addition
  to `writeRunAudit()`), so replay can re-run the prompt that was ACTUALLY used, not today's possibly
  edited `Schedule.prompt`.
- **Replay**: given a past run's id, re-invoke the same agent with that run's exact recorded prompt, as
  a dry-run, and return a step-by-step diff (same tool names/order? different outcome? different final
  LLM output text?) against the original run's recorded trace.
- Both dry-run and replay pass through the SAME kill-switch/budget-hard-cap/approval-required checks
  `ScheduleService::dispatch()` already applies (extracted into a reusable, non-mutating gate check) —
  a dry-run still calls the real LLM and so still costs tokens and can still be blocked by governance.
  Unlike a real occurrence, neither mutates the schedule's `nextRun`/`repeat`/`enabled` state or creates
  a new pending `Approval` object — a preview must be repeatable and side-effect-free on the schedule
  itself, not just on the agent's tools.
- New owner-guarded endpoints: `POST /api/schedules/{scheduleId}/dry-run` and
  `POST /api/schedules/{scheduleId}/runs/{runId}/replay`.
- "Dry-run" button next to the existing "Run now" action, and a "Replay" action per run row, in
  `AgentDetail.vue`'s existing Schedule/Run-history section, rendering the diff using the existing
  trace-step-timeline markup `run-trace-observability` already built.

### Out of Scope
- **Full deterministic checkpoint/restore of engine state mid-run** (LangGraph's model — pausing and
  resuming a run from an arbitrary intermediate point, with exact conversation/tool-state restored).
  Hermiq's `Engine`/`ToolLoop` has no notion of a resumable execution point; building one would be a
  rearchitecture of the agent loop itself, not an addition to it — filed as a roadmap item, not here.
- **Mocking the LLM.** A dry-run makes the SAME real LLM call an ordinary run would (same prompt,
  model, context) — only tool calls are neutralised. There is no "simulate what the LLM would say"
  mode; if there were, the preview would not actually preview the agent's real behavior.
- **Exact-argument replay of the ORIGINAL run's tool calls.** `run-trace-observability` deliberately
  never persisted tool call arguments/results (its Risk 4: avoid reintroducing a secret-leak surface),
  so a replay cannot literally re-play the original tool invocations byte-for-byte — it re-runs the
  same PROMPT against the same agent and diffs the NEW tool-call sequence/output against the recorded
  ORIGINAL sequence. This is a materially smaller (and safer) claim than "replay this run exactly,"
  and is stated plainly in the UI.
- **Dry-run/replay on the default OpenRegister `ChatService` path** (`hermiq.engine.enabled=false`,
  today's default). That path has no per-tool-call interception point at all
  (`run-trace-observability`'s Out of Scope already established this) — dry-run's entire mechanism
  depends on `FacadeToolInvoker`, which only exists on the in-app Engine path. Attempting either action
  with the flag off returns a clear, actionable error rather than silently running for real.
- Interactive-chat dry-run (the `AgentDetail`/`Engine` chat widget path). This change targets
  scheduled-agent runs only, mirroring `run-trace-observability`'s scope.

## Approach
Extract `ScheduleService::dispatch()`'s three gate checks (kill-switch, budget hard cap,
approval-required) into a small, side-effect-free evaluator reused by both the existing tick/Run-now
path (which still mutates schedule state on pass) and two new entry points,
`ScheduleService::dryRunNow()` and `ScheduleService::replayRun()`, which call
`runAgentAsOwner(..., dryRun: true)` directly on gate-pass without touching `nextRun`/`repeat`/
`enabled` or creating an `Approval`. `FacadeToolInvoker` gains a `dryRun` flag and a
`ToolClassificationService` dependency: when dry-run is on, a `sideEffecting`-classified tool is
recorded as a `would-have-called` step (with redacted arguments) and short-circuited to a synthetic
result instead of calling `ToolRegistryFacade::invokeTool()`; a `readOnly` tool is still invoked for
real. `replayRun()` reads the original run's persisted `prompt` (added to the audit context) via
`RunHistoryService`, re-executes it as a dry-run, and diffs the new trace against the original's. The
frontend adds a "Dry-run" button beside "Run now" and a "Replay" action per run row in the existing
`AgentDetail.vue` Schedule/Run-history section, rendering the diff with the same step-timeline markup
`run-trace-observability` already ships.

## New Dependencies
None.

## Impact
- **Backend**: `lib/Service/Engine/FacadeToolInvoker.php` (+ `dryRun`/classifier, would-have-called
  short-circuit), `lib/Service/Engine/ToolLoop.php` (+ thread `dryRun`), `lib/Service/Engine/Engine.php`
  (+ `dryRun` param), `lib/Service/Engine/ResponseGenerationHandler.php` (+ thread `dryRun`), new
  `lib/Service/ToolClassificationService.php`, `lib/Service/ScheduleService.php` (extracted gate
  evaluator, `dryRunNow()`, `replayRun()`, scratch-conversation cleanup, `prompt`/`dryRun`/`replayOf` in
  audit context), `lib/Service/RunHistoryService.php` (+ surface `prompt`/`dryRun`/`replayOf` from
  trace), `lib/Service/AnalyticsService.php` (exclude `dryRun` entries from status breakdown),
  new `lib/Controller/` methods on `RunNowController` (`dryRun()`) and `RunHistoryController`
  (`replay()`), `appinfo/routes.php` (+ 2 routes).
- **Frontend**: `src/api/agents.js` (+ `dryRunSchedule()`, `replayRun()`), `src/views/AgentDetail.vue`
  (+ "Dry-run" button, "Replay" action, diff render), `l10n/en.json` + `l10n/nl.json`.
- **Specs**: adds `run-replay-and-dry-run` (new capability); modifies `run-audit-log` (prompt
  persistence, dryRun/replayOf context fields) and `agent-management-ui` (new UI actions).

## Cross-Project Dependencies
None required to ship this change. See Risk 1 for a recommended (not blocking) OpenRegister follow-up:
`ToolRegistryFacade`'s tool descriptors carry no side-effect classification, so any OTHER app porting
the same dry-run pattern would have to build its own classification map too, exactly as this change
does for Hermiq — an upstream `sideEffecting`/`readOnly` field on the descriptor (an `ai-mcp` REQ-006
addition) would let every consumer share one source of truth instead of duplicating it.

## Risks

### Risk 1: No upstream side-effect classification exists on OpenRegister's tool descriptors
**Severity:** Medium — **Mitigation:** Hermiq classifies tools itself in a small, explicit map with a
**fail-safe default of `sideEffecting`** — an unclassified or newly-added tool is always neutralised in
dry-run, never silently invoked for real. File a follow-up OpenRegister issue proposing a
`sideEffecting: bool` field on `ToolRegistryFacade::listTools()`'s descriptors (`ai-mcp` REQ-006) so
this classification can eventually move upstream and be shared by every consumer; not a blocker here.

### Risk 2: Recording tool-call arguments in the trace reopens the secret-leak surface `run-trace-observability` deliberately closed
**Severity:** Medium — **Mitigation:** arguments are recorded ONLY on a `would-have-called` step (never
on a real `ok`/`error` tool step, which stays name/timing/outcome-only exactly as before), and are
passed through the existing `RedactionService::redact()` before being placed in either the in-memory
trace or the persisted audit context — the same redaction-before-persist invariant (ADR-004) already
applied to `summary`.

### Risk 3: Dry-run/replay tokens could be double-counted or mislabelled in cost/analytics reporting
**Severity:** Medium — **Mitigation:** the SAME `usage` capture and `writeRunAudit()` path a real run
uses is reused unchanged, so `BudgetService`'s spend total correctly includes dry-run/replay token
cost (real money was spent) — but `dryRun: true` entries are explicitly excluded from
`AnalyticsService::computeAnalytics()`'s status/success-rate breakdown, so a schedule's "succeeded N
times" figure is never inflated by preview runs.

### Risk 4: A replay's re-run prompt can diverge from what the agent would do TODAY if the agent's own configuration (model, tools, prompt template) changed since the original run
**Severity:** Low — **Mitigation:** this is stated plainly as the feature's actual guarantee — replay
re-runs the ORIGINAL run's exact prompt text against the agent's CURRENT configuration, and the diff
view is presented as "what would happen now, given what happened then," never as a byte-for-byte
historical reproduction.

### Risk 5: A preview action that bypasses schedule-state mutation could be confused with a "free" action that skips governance
**Severity:** Low — **Mitigation:** the extracted gate evaluator applies the identical kill-switch/
budget/approval checks in the identical order `dispatch()` already enforces; only the STATE MUTATION
(nextRun/repeat/enabled advance, new pending Approval) is skipped, never the gate decision itself. A
schedule that would be blocked from a real run is equally blocked from a dry-run/replay of it.

## Rollback Strategy
Purely additive: the `dryRun` parameter defaults to `false` everywhere it is threaded, so every
existing call site (scheduled ticks, Run now, the interactive chat widget) is byte-for-byte unchanged
when omitted. Removing the two new controller methods, the two new frontend actions, and
`ToolClassificationService` reverts the app to its pre-change behavior with no data migration — the
`dryRun`/`replayOf`/`prompt` audit-context fields are additive JSON keys inside the existing
`AuditTrail.changed` column, so historical entries written before or after a rollback both read
correctly.

## Open Questions
- Should a tool provider (another Conduction app registering an MCP tool) be able to self-declare its
  own side-effect classification via some existing metadata channel, rather than waiting for the
  Risk 1 upstream flag? Provisional answer: no — until OpenRegister exposes a real flag, a
  self-declared, unverified classification from the tool's OWN app would undermine the fail-safe
  default (a misbehaving or compromised provider could simply claim `readOnly`); Hermiq's own
  allow-listed classification, defaulting closed, is the safer interim posture.
