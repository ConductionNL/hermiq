# Design: run-trace-observability

## Architecture Overview

`run-audit-log` already writes exactly one `action='run'` `AuditTrail` entry per occurrence, from
`ScheduleService::writeRunAudit()` (`lib/Service/ScheduleService.php:640-676`), and reads it back
via `RunHistoryService::getRunHistory()` (`lib/Service/RunHistoryService.php:98-136`), which filters
strictly to `action='run'`, newest first. This change does not add a new write path or a new object
type — it widens the `changed` context of that single entry with a `steps` array, and adds one new
read method that also looks at the schedule's *other* adjacent `run` entries (the gate-skip rows
`recordGateSkip()` already writes) to reconstruct an approval-wait step, entirely from data that
already exists.

```
ScheduleService::dispatch()
  GATE 1 kill-switch   ──▶ recordGateSkip('skipped_killswitch')  ─┐  action='run' entries,
  GATE 2 approval      ──▶ recordGateSkip('awaiting_approval')   ─┤  same schedule, newest-first
  runDue()                                                        │
    runAgentAsOwner()                                             │
      ├─ flag OFF: OR ChatService::processMessage()               │
      │     → { message, timings{context,history,llm,total}, usage }
      └─ flag ON:  Engine::processMessage(..., $trace)            │
            ├─ ContextRetrievalHandler   (context step, timed)    │
            ├─ MessageHistoryHandler     (history step, timed)    │
            ├─ ResponseGenerationHandler (llm step, timed)        │
            │     └─ ToolLoop / FacadeToolInvoker::__call()       │
            │           → one `tool` step per invocation          │
            └─ returns { ..., steps: $trace->toArray() }          │
    deliver()  (timed)  → delivery step                           │
    writeRunAudit(steps: $this->lastRunSteps)  ─────────────────▶─┘
                                │
                                ▼
                 AuditTrail action='run' entry, changed.steps = [...]
                                │
                 RunHistoryService::getRunTrace(scheduleUuid, runId)
                   1. load the target entry's changed.steps
                   2. walk backward through the SAME findAll() result set for an
                      unbroken run of awaiting_approval/skipped_killswitch entries
                      immediately preceding it → synthesise a leading gate_wait step
                   3. return the ordered step list
                                │
                 RunHistoryController::trace()  (owner-scoped, same guard as index())
                                │
                 AgentDetail.vue Run history section: timeline render + JSON download
```

## API Design

### `GET /api/schedules/{scheduleId}/runs/{runId}/trace`
Owner-scoped (identical guard to the existing `GET /api/schedules/{scheduleId}/runs`): loads the
schedule via `ObjectService` with RBAC on, refuses (404, not 403) unless the caller owns it, then
returns the target run's full step timeline.

**Request:** none (path params only).

**Response (200):**
```json
{
  "id": "run-audit-trail-uuid",
  "scheduleId": "schedule-uuid",
  "status": "ok",
  "agentId": "agent-uuid",
  "startedAt": "2026-07-12T09:00:00+00:00",
  "endedAt": "2026-07-12T09:00:04+00:00",
  "durationMs": 4120,
  "toolStepsAvailable": true,
  "steps": [
    { "seq": 0, "type": "gate_wait", "name": "Awaiting approval", "startedAt": "2026-07-12T08:55:00+00:00", "endedAt": "2026-07-12T09:00:00+00:00", "durationMs": 300000, "outcome": "approved" },
    { "seq": 1, "type": "context", "name": "Context retrieval", "startedAt": "2026-07-12T09:00:00+00:00", "endedAt": "2026-07-12T09:00:00+00:00", "durationMs": 180, "outcome": "ok" },
    { "seq": 2, "type": "history", "name": "History build", "startedAt": "2026-07-12T09:00:00+00:00", "endedAt": "2026-07-12T09:00:00+00:00", "durationMs": 12, "outcome": "ok" },
    { "seq": 3, "type": "tool", "name": "openregister.searchObjects", "startedAt": "2026-07-12T09:00:01+00:00", "endedAt": "2026-07-12T09:00:02+00:00", "durationMs": 890, "outcome": "ok" },
    { "seq": 4, "type": "llm", "name": "LLM generation", "startedAt": "2026-07-12T09:00:02+00:00", "endedAt": "2026-07-12T09:00:03+00:00", "durationMs": 2940, "outcome": "ok" },
    { "seq": 5, "type": "delivery", "name": "Talk delivery", "startedAt": "2026-07-12T09:00:04+00:00", "endedAt": "2026-07-12T09:00:04+00:00", "durationMs": 95, "outcome": "ok" }
  ],
  "summary": "redacted output summary",
  "user": "alice",
  "created": "2026-07-12T09:00:04+00:00"
}
```
**Errors:** `401` unauthenticated; `404` schedule/run not found or not owned (never `403` — anti-probing,
matching `index()`'s existing convention).

`toolStepsAvailable` is a derived boolean (`true` only if any `steps[].type === 'tool'` is present) so
the frontend can distinguish "no tools were called this run" from "tool-level detail unavailable on
this run's execution path" (Risk 2) without guessing from an empty array alone.

## Database Changes
None. No new OpenRegister schema, no NC DB migration — `steps` is additional JSON structure inside the
existing `AuditTrail.changed` column the `action='run'` write already populates (same column
`run-audit-log` already uses for `status`/`agentId`/`usage`/`summary`).

## Nextcloud Integration
- **Controllers**: `RunHistoryController` (+ `trace()` method, `#[NoAdminRequired]` `#[NoCSRFRequired]`,
  reusing `loadOwnedSchedule()` unchanged).
- **Services**: `RunHistoryService` (+ `getRunTrace()`), `ScheduleService` (+ `$lastRunSteps` state,
  reset-per-run like `$lastRunUsage`; + timed `deliver()` wrapper), new
  `lib/Service/Engine/RunTraceCollector.php` (plain PHP value/recorder object — no DI needed beyond
  being `new`'d per run, exactly like `StreamYieldChannel`).
- **Mappers/Entities**: reuses `AuditTrailMapper::findAll()`/`createAuditTrailEntry()` — no new mapper.
- **Events/Hooks**: none new.

## Security Considerations
- The new `trace()` endpoint reuses `RunHistoryController::loadOwnedSchedule()` verbatim — identical
  IDOR guard (404-not-403, RBAC-on load, explicit owner-UID comparison) as the existing `index()`
  method; no new authorization surface is introduced.
- **Redaction-before-persist (ADR-004) is preserved**: any free-text step field (e.g. a tool outcome
  detail string) passes through the existing `RedactionService::redact()` before
  `createAuditTrailEntry()` is called — the same call already redacting `summary`. Step `name` values
  are tool/registry ids (`{appId}.{toolName}`) and fixed labels ("Context retrieval", "LLM
  generation", "Talk delivery"), never free user/tool-supplied text, so they need no redaction pass
  themselves.
- Tool arguments and tool results are **never** written into a step — only `name`/timestamps/
  `outcome` (`ok`/`error`). This is a deliberate size and leak-surface bound (proposal Risk 4): the
  full argument/result payloads already exist only ephemerally in the SSE `tool_call`/`tool_result`
  frames (`FacadeToolInvoker`) and are not persisted by this change either.
- The trace/download endpoint returns exactly the same redacted data the run-history list endpoint's
  underlying entry already contains — no new sensitive surface, just more of the same already-redacted
  entry exposed in one additional read shape.

## NL Design System
The Run history section in `AgentDetail.vue` already uses a plain `agent-detail__table` (no bespoke
CSS). The trace view adds an expandable row (or inline detail panel) using existing `NcButton`/
`NcNoteCard` components and the same `agent-detail__badge--ok`/`--error` outcome-badge classes already
used for run status — no new color tokens, no new component family. The "Download trace (JSON)"
action is a plain `NcButton` triggering a client-side `Blob`/`URL.createObjectURL()` download (no new
backend content-disposition handling needed).

## File Structure
```
lib/
  Service/
    Engine/
      RunTraceCollector.php     (new — ordered step recorder, no persistence)
      Engine.php                 (+ optional RunTraceCollector param; + `steps` in return envelope)
      ToolLoop.php                (+ thread RunTraceCollector into FacadeToolInvoker)
      FacadeToolInvoker.php        (+ time each __call() dispatch into the collector)
    ScheduleService.php           (+ $lastRunSteps; capture timings/collector steps; time deliver();
                                     + steps param on writeRunAudit())
    RunHistoryService.php         (+ getRunTrace(scheduleUuid, runId))
  Controller/
    RunHistoryController.php      (+ trace())
appinfo/
  routes.php                      (+ GET .../runs/{runId}/trace)
src/
  api/
    agents.js                      (+ getRunTrace(scheduleId, runId))
  views/
    AgentDetail.vue                 (+ timeline render + download action in Run history section)
```

## Seed Data
Not applicable — this change introduces no new OpenRegister schema/object type. Existing seeded
agents/schedules already produce `run` audit entries on Run-now/scheduled ticks; those runs will
carry a populated `steps` array once this change ships, with no seed-data changes required.

## Trade-offs
- **Enrich the existing entry vs. a new `run-trace` capability/spec**: a separate capability would
  imply an independently versioned data model or lifecycle, but the trace is structurally just richer
  content inside the same audit write `run-audit-log` already owns, read through the same ownership
  guard `agent-management-ui`'s Run history view already uses. Splitting it into a third, empty-shell
  capability spec would fragment two tightly coupled halves (backend enrichment, UI rendering) of one
  feature across three files for no reader benefit — rejected in favor of `MODIFIED run-audit-log` +
  `MODIFIED agent-management-ui`.
- **In-memory `RunTraceCollector` threaded like `StreamYieldChannel` vs. a new persisted per-step audit
  write**: a per-step `AuditTrailMapper::createAuditTrailEntry()` call for every tool invocation would
  multiply audit-write volume (and hash-chain length) by the average tool-call count per run, for data
  that is only ever read back as part of one run's trace — never independently. Collecting steps
  in-memory during the run and writing them once, batched into the existing single entry, keeps the
  hash chain's growth rate unchanged and matches the proposal's "no parallel logging system"
  constraint.
- **Read-time gate-wait reconstruction vs. writing an explicit correlation id**: adding a shared
  `runId`/`correlationId` field to every gate-skip and final-run entry (so they could be joined
  directly) would touch `dispatch()`/`recordGateSkip()`, which today has zero involvement in this
  feature. Reconstructing the wait window from adjacent, already-ordered entries at read time achieves
  the same visible result without touching the gate code at all — smaller diff, lower regression risk
  on `human-approval-gate-enforcement`.
- **Coarse-only steps on the OR `ChatService` path vs. deferring the whole feature until OR is
  instrumented**: shipping the coarse context/history/llm/delivery steps immediately (available today,
  on both paths, for free) delivers most of the "step timeline" value now; gating the entire change on
  an upstream OpenRegister change would delay real user value for a fully-additive, honestly-labelled
  gap (Risk 2) instead.

## Open Questions
(carried from proposal.md — resolved provisionally there; no new ones identified during design)
