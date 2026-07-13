# Design: run-replay-and-dry-run

## Architecture Overview

Today a schedule's agent runs exactly one way: `ScheduleService::dispatch()` runs three gates
(kill-switch → budget hard cap → approval), then, on pass, `runDue()` commits the occurrence's
state advance (`nextRun`/`repeat`/`enabled`) BEFORE calling `runAgentAsOwner()`, which impersonates
the owner and calls either OpenRegister's `ChatService` (flag off) or the in-app `Engine` (flag on,
`hermiq.engine.enabled`). Only the `Engine` path threads a `RunTraceCollector` through
`ToolLoop`/`FacadeToolInvoker`, so only that path can see individual tool calls at all — the
`ChatService` path has no comparable hook (confirmed: `run-trace-observability`'s Out of Scope already
established this, and it remains true at HEAD). This change adds a `dryRun` flag that rides the SAME
`Engine` path, intercepted at the SAME single chokepoint every tool call already passes through
(`FacadeToolInvoker::__call()`), and two new entry points (`dryRunNow()`, `replayRun()`) that reuse the
dispatch gates WITHOUT the occurrence commit:

```
                    ┌─────────────────────────────────────────────┐
                    │   ScheduleService::evaluateGates()           │  (NEW — extracted, side-effect-free)
                    │   1. kill-switch (isOrganisationEngaged)     │
                    │   2. budget hard cap (budgetService.isBlocked)│
                    │   3. requiresApproval (READ-ONLY check)      │
                    └───────────────┬───────────────────────────────┘
                                    │ pass                    │ fail
              ┌─────────────────────┼──────────────┐          ▼
              │                     │              writes a lightweight
     dispatch() [existing]   dryRunNow()/replayRun() [NEW]   skip-audit entry,
     commits nextRun/repeat/  no state mutation,             NO schedule mutation,
     enabled BEFORE running   no Approval created            returns gate status
              │                     │
              └──────────┬──────────┘
                         ▼
              runAgentAsOwner(..., dryRun)
                         │
              isEngineEnabled()? ── NO ──▶ dryRun=true here throws
                         │ YES                (RuntimeException — see Decision 3)
                         ▼
              runAgentViaEngine(..., dryRun)
                creates scratch Conversation (title tags dry-run)
                         │
              Engine::processMessage(..., dryRun, $trace)
                ContextRetrievalHandler / MessageHistoryHandler (unchanged)
                ResponseGenerationHandler::generateResponse(..., dryRun)
                         │
              ToolLoop::buildFunctionInfos(..., dryRun)
                         │
              new FacadeToolInvoker(facade, channel, trace, dryRun, classifier)
                         │
              __call($name, $args):
                classifier.isSideEffecting($name)?
                  NO  (readOnly)  → facade->invokeTool() for REAL, trace step outcome=ok/error
                  YES (default)   → NEVER call facade; trace step outcome='would-have-called',
                                     redacted $args attached; synthetic result returned to LLM
                         │
              (dryRun===true) finally: delete scratch Conversation + its Messages
                         │
              writeRunAudit(..., dryRun: true, replayOf: ?, prompt: <exact text used>)
                         │
              AuditTrail action='run' entry — dryRun:true clearly marked, never confused
              with a real run; usage still counted (BudgetService), excluded from
              AnalyticsService's status breakdown
```

`replayRun($schedule, $runId)` is `dryRunNow()` plus one extra read: it calls
`RunHistoryService::getRunTrace()` to fetch the ORIGINAL run's persisted `prompt` and `agentId` (the
`prompt` field is new — see Decision 2), then calls the same `runAgentAsOwner(..., dryRun: true)` with
that exact text, and diffs the NEW trace's `steps` (by `type`+`name`, in order) against the ORIGINAL
trace's `steps`, plus a boolean "final output text differs."

## API Design

### `POST /api/schedules/{scheduleId}/dry-run`
Owner-guarded (identical `loadOwnedSchedule()` pattern as `RunNowController::run()`). Runs the
extracted gate check; on pass, runs the agent as a dry-run and returns its outcome.

**Request:** none (path param only).

**Response (200):**
```json
{
  "scheduleId": "schedule-uuid",
  "dryRun": true,
  "status": "ok",
  "error": null,
  "steps": [
    { "seq": 0, "type": "context", "name": "Context retrieval", "durationMs": 180, "outcome": "ok" },
    { "seq": 1, "type": "tool", "name": "openregister.searchObjects", "durationMs": 340, "outcome": "ok" },
    { "seq": 2, "type": "tool", "name": "talk.sendMessage", "durationMs": 2, "outcome": "would-have-called",
      "arguments": { "conversationId": "***redacted***", "text": "Weekly summary: ..." } },
    { "seq": 3, "type": "llm", "name": "LLM generation", "durationMs": 2940, "outcome": "ok" }
  ],
  "summary": "redacted final output the agent would have delivered"
}
```
**Response (409):** the schedule is gated (kill-switch engaged / budget hard cap reached / requires
approval) — `{"error": "Blocked by governance", "gate": "skipped_killswitch|skipped_budget|awaiting_approval"}`.
**Response (422):** `hermiq.engine.enabled` is off — `{"error": "Dry-run requires the in-app agent engine. Enable the engine.enabled feature flag first."}`.

### `POST /api/schedules/{scheduleId}/runs/{runId}/replay`
Owner-guarded, identical pattern to `RunHistoryController::trace()` plus the same gate/engine checks
as dry-run above.

**Request:** none.

**Response (200):**
```json
{
  "scheduleId": "schedule-uuid",
  "replayOf": "original-run-uuid",
  "original": { "status": "ok", "steps": [ /* as persisted */ ], "summary": "..." },
  "replay":   { "status": "ok", "steps": [ /* new dry-run steps, would-have-called for side-effecting */ ], "summary": "..." },
  "diff": {
    "toolSequenceMatches": false,
    "toolCalls": [
      { "seq": 0, "original": "openregister.searchObjects", "replay": "openregister.searchObjects", "match": true },
      { "seq": 1, "original": "talk.sendMessage", "replay": null, "match": false }
    ],
    "outputChanged": true
  }
}
```
**Errors:** `404` (schedule/run not found or not owned — anti-probing, matches `trace()`); `409`/`422`
identical to dry-run above; `404` also when the original run's `prompt` was never persisted (a run
written before this change shipped) — replay is only possible for runs recorded after this change.

## Database Changes
None. `dryRun`, `replayOf`, and `prompt` are additive JSON keys inside the existing `AuditTrail.changed`
column `run-audit-log` already populates — no new OpenRegister schema, no NC migration. `Schedule.prompt`
already exists (verified in `hermiq_register.json`); this change does not add a field there either —
it persists the run's *actual* prompt text into the audit entry so replay is unaffected by later edits
to the live `Schedule.prompt`.

## Nextcloud Integration
- **Controllers**: `RunNowController` (+ `dryRun()` method, same owner guard, same route-file pattern),
  `RunHistoryController` (+ `replay()` method, reusing `loadOwnedSchedule()`).
- **Services**: `ScheduleService` (+ `evaluateGates()`, `dryRunNow()`, `replayRun()`, scratch-conversation
  cleanup, `dryRun`/`replayOf`/`prompt` in `writeRunAudit()`'s context), new
  `lib/Service/ToolClassificationService.php` (plain PHP map, no DI beyond a constructor default),
  `lib/Service/Engine/FacadeToolInvoker.php` (+ `dryRun`/classifier constructor args),
  `lib/Service/Engine/ToolLoop.php` (+ thread `dryRun` into the invoker), `lib/Service/Engine/Engine.php`
  (+ `dryRun` param on `processMessage()`), `lib/Service/Engine/ResponseGenerationHandler.php` (+ thread
  `dryRun` to `buildFunctionInfos()`), `RunHistoryService` (+ surface `prompt`/`dryRun`/`replayOf` from
  `getRunTrace()`), `AnalyticsService` (+ skip `dryRun===true` entries in `computeAnalytics()`'s status
  breakdown).
- **Mappers/Entities**: reuses `AuditTrailMapper`/`ObjectService` — no new mapper. The scratch
  `Conversation`/`Message` deletion uses `ObjectService::deleteObject()`, already used elsewhere.
- **Events/Hooks**: none new.

## Security Considerations
- Both new endpoints reuse the EXACT owner-ownership guard (`loadOwnedSchedule()`, 404-not-403,
  RBAC-on load, owner-UID comparison) the existing `run()`/`trace()` methods already use — no new
  authorization surface.
- **Fail-safe tool classification**: `ToolClassificationService` defaults an unrecognised
  `{appId}.{toolName}` to `sideEffecting` — a newly-installed tool, or a tool this map has not been
  updated for, is ALWAYS neutralised in dry-run, never silently invoked for real. Only an explicit
  allow-list entry (e.g. `openregister.searchObjects`, `openregister.getObject`) is treated as
  `readOnly`.
- **Redaction-before-persist (ADR-004) extends to `would-have-called` arguments**: unlike a normal
  `ok`/`error` tool step (name/timing/outcome only, unchanged from `run-trace-observability`), a
  `would-have-called` step additionally carries the tool's arguments — these pass through
  `RedactionService::redact()` (on the JSON-encoded argument blob) before being placed in either the
  live trace or the persisted audit context. This is the ONE narrow, deliberate exception to
  `run-trace-observability`'s "never raw arguments" rule, scoped to exactly the case where the real
  call never happened and the argument values are the only user-visible content of the preview.
- **Governance gates are not bypassable via "it's just a preview"**: `evaluateGates()` is the SAME
  kill-switch/budget/approval logic `dispatch()` uses (extracted, not duplicated) — an org whose
  kill-switch is engaged, or a schedule that would require approval, is equally blocked from a
  dry-run/replay. Only the SCHEDULE'S OWN state mutation (nextRun/repeat/enabled advance, new pending
  `Approval` row) is skipped for a preview, never the gate decision.
- Dry-run/replay still make a real LLM call with the real system prompt/context — no new prompt-
  injection surface beyond what a normal run already has.

## NL Design System
Reuses `AgentDetail.vue`'s existing `agent-detail__badge`/`agent-detail__trace-step*` classes for the
new `would-have-called` outcome (a new badge modifier, e.g. `agent-detail__badge--dryrun`, styled with
an existing NL Design System neutral/info token rather than a new color) and the existing
`NcButton`/`NcNoteCard` components — no new component family, no new CSS variables.

## File Structure
```
lib/
  Service/
    ToolClassificationService.php   (new — {appId}.{toolName} → readOnly|sideEffecting map, fail-safe closed)
    ScheduleService.php              (+ evaluateGates(), dryRunNow(), replayRun(), scratch-conversation
                                        cleanup, dryRun/replayOf/prompt in writeRunAudit())
    RunHistoryService.php            (+ surface prompt/dryRun/replayOf from getRunTrace())
    AnalyticsService.php             (+ skip dryRun entries from status breakdown)
    Engine/
      FacadeToolInvoker.php          (+ dryRun flag + classifier; would-have-called short-circuit)
      ToolLoop.php                   (+ thread dryRun into FacadeToolInvoker)
      Engine.php                    (+ dryRun param on processMessage())
      ResponseGenerationHandler.php  (+ thread dryRun to buildFunctionInfos())
  Controller/
    RunNowController.php             (+ dryRun())
    RunHistoryController.php         (+ replay())
appinfo/
  routes.php                        (+ POST .../dry-run, POST .../runs/{runId}/replay)
src/
  api/
    agents.js                       (+ dryRunSchedule(scheduleId), replayRun(scheduleId, runId))
  views/
    AgentDetail.vue                  (+ "Dry-run" button, "Replay" row action, diff render)
l10n/
  en.json, nl.json                  (+ new user-facing strings)
```

## Seed Data
Not applicable — this change introduces no new OpenRegister schema/object type. Existing seeded
agents/schedules can already exercise dry-run/replay once `hermiq.engine.enabled` is on; no seed-data
changes required.

## Trade-offs

### Extracted, non-mutating gate check vs. reusing `dispatch()` directly
Calling `dispatch()` as-is would advance `nextRun`/`repeat`/`enabled` and create a real pending
`Approval` on every preview — making the schedule's real cadence and approval queue an unwanted side
effect of clicking "Dry-run" repeatedly. Extracting the three checks into `evaluateGates()` (read-only,
reused by both paths) keeps the governance decision identical while making the preview itself
side-effect-free on the schedule object, matching the feature's core promise.

### Recording redacted arguments on `would-have-called` steps vs. keeping the trace argument-free
`run-trace-observability` chose name/timing/outcome-only specifically to avoid a secret-leak surface
(its Risk 4). But a dry-run's entire value is showing WHAT the agent would have sent/deleted/created —
a `would-have-called` step with no arguments ("a tool would have been called") is nearly useless as a
preview. Recording the arguments ONLY on this new outcome type, and ONLY after the same redaction pass
already applied to `summary`, keeps the blast radius of the exception as narrow as possible rather than
reopening argument-logging for every tool step.

### Fail-safe-closed Hermiq-owned classification vs. waiting for an upstream OpenRegister flag
Blocking this whole change on an `ai-mcp` spec change to `ToolRegistryFacade` would delay a
high-value, low-risk feature for a cross-app dependency Hermiq does not control the timeline of. A
small, explicit, closed-by-default map ships the feature now and is a strict subset of what an
eventual upstream flag would provide — nothing here needs reverting if OpenRegister adds one later
(Hermiq would simply prefer the upstream flag when present, falling back to its own map).

### Replay re-runs the recorded PROMPT, not a full state snapshot
A true LangGraph-style checkpoint/restore (resume mid-run from exact engine state) would require
persisting the entire conversation/tool-call state graph and a re-entrant execution model — the
proposal's Out of Scope. Replaying just the ORIGINAL prompt against the CURRENT agent configuration,
as a fresh dry-run, delivers the debugging value ("did the same tools fire? did the output change?")
Spectr's evidence cluster actually asks for, without the rearchitecture.

## Open Questions
(carried from proposal.md — resolved provisionally there; no new ones identified during design)
