# Proposal: run-trace-observability

## Summary
Enriches the single, existing `action='run'` OpenRegister `AuditTrail` entry that `run-audit-log`
already writes per run with an ordered **step timeline** (context retrieval, history build, LLM
generation, individual tool invocations where available, delivery), and adds a per-run trace read
endpoint the `agent-management-ui` Run history section renders as a timeline and offers as a
downloadable, already-redacted JSON file. No new logging store, no new OpenRegister schema, no
parallel telemetry pipeline — the trace is additional structure inside the payload of the audit
write path that already exists.

## Motivation
Spectr research (domain 262, `app_id='hermiq'`) names step-level observability as developers' top
adoption blocker for autonomous-agent platforms: users cannot see *what an agent actually did inside
a run* — which tool fired, how long each step took, where time went — only the coarse `status`/
`durationMs`/`summary` `run-audit-log` records today. Rivals with Langfuse/AgentOps-style tracing are
cited as the bar to clear; Hermiq's own `run-audit-log` spec already *claims* "each tool invocation"
is audited (`openspec/specs/run-audit-log/spec.md` Requirement "Every run and tool call is audited"),
but investigation at HEAD shows this is not actually implemented: `ScheduleService::writeRunAudit()`
(`lib/Service/ScheduleService.php:640-676`) writes exactly one aggregate entry per run with `status`/
`agentId`/`startedAt`/`endedAt`/`durationMs`/`usage`/`runAsUser`/`summary`; no per-tool-call
`AuditTrailMapper::createAuditTrailEntry()` call exists anywhere in `lib/` (`FacadeToolInvoker`'s
`__call()` only fans ephemeral, unpersisted `tool_call`/`tool_result` SSE frames — `lib/Service/
Engine/FacadeToolInvoker.php:79-107`). This change closes that gap honestly: it enriches the one
audit entry with real step data where Hermiq can actually observe it, rather than leaving the spec's
claim unimplemented or inventing a second logging system to paper over it.

## Affected Projects
- [x] Project: `hermiq` — enriches the per-run audit write with a step timeline, adds a per-run
  trace read endpoint, and adds a trace-view + JSON-download UI to the existing Run history section.

## Scope

### In Scope
- A `steps` array added to the `changed` context of the existing per-run `AuditTrail` entry written
  by `ScheduleService::writeRunAudit()` (the scheduled-tick and Run-now path), ordered by occurrence,
  each step carrying `type`, `name`, `startedAt`, `endedAt`, `durationMs`, and `outcome`.
- Two sources of step data, populated where Hermiq actually owns the code that can observe them:
  - **Coarse steps (both engine paths)**: `context` / `history` / `llm` steps derived from the
    `timings` bucket the agent-turn call already returns today (`Engine::processMessage()` and
    OpenRegister's `ChatService::processMessage()` share this return-shape contract per `Engine`'s
    class docblock) but that `ScheduleService` currently discards — this change captures it instead
    of computing anything new.
  - **Fine-grained tool steps (in-app Engine path only, `engine.enabled=true`)**: one step per tool
    call (`name`, `durationMs`, `outcome: ok|error`), newly instrumented in `ToolLoop`/
    `FacadeToolInvoker` via a small in-request `RunTraceCollector`, because Hermiq owns that code
    outright (`agent-engine-port`). The default OpenRegister `ChatService` path does not get
    per-tool-call granularity from this change — see Out of Scope.
  - A **delivery** step timed around the existing `DeliveryService` call in `ScheduleService::runDue()`.
- A best-effort **gate-wait** step reconstructed at *read* time (not written to any new entry) by
  `RunHistoryService`, from the schedule's own adjacent, contiguous `awaiting_approval`/
  `skipped_killswitch` `action='run'` entries that already precede the real run — no change to the
  dispatch gate code itself.
- A new owner-scoped read endpoint, `GET /api/schedules/{scheduleId}/runs/{runId}/trace`, returning
  the full step timeline for one run (redaction already applied at write time), which the frontend
  offers as a "Download trace (JSON)" action.
- A trace-view (ordered timeline with per-step duration/outcome) added to the existing Run history
  section of `AgentDetail.vue`.

### Out of Scope
- **Per-tool-call granularity on the default OpenRegister `ChatService` path.** Hermiq does not own
  OR's `ChatService`/its internal tool loop; giving it the same fine-grained instrumentation would be
  a cross-app OpenRegister change (ADR-001 Option C+ boundary) and is filed as a follow-up, not built
  here. The default path still gets the coarse context/history/llm steps.
- **Flow/webhook-triggered runs** (`FlowAgentRunService`, `action='agent-run'`). These are already
  invisible to `RunHistoryService` today (it filters strictly `action='run'`) — a pre-existing gap
  this change does not widen or fix.
- **Replay / checkpointing** of a past run (re-executing or resuming from a recorded step) — a
  materially larger feature (state capture + re-entrant execution), left on the roadmap.
- **Drift detection** (comparing a run's trace against a prior baseline to flag behavioral drift) —
  roadmap, depends on this change's data existing first.
- Real-time/streaming trace updates while a run is in progress — the trace is a **post-run** artifact
  read from the finalised audit entry, exactly like the rest of `run-audit-log`.
- Storing raw tool arguments or raw tool result payloads in the trace — steps carry name/timing/
  outcome only, to avoid reintroducing a secret-leak surface (see Risk 4).

## Approach
Thread a lightweight `RunTraceCollector` (an in-memory, per-run ordered-step recorder — no
persistence of its own) through `Engine::processMessage()` → `ToolLoop`/`FacadeToolInvoker`,
mirroring exactly how `StreamYieldChannel` is already optionally threaded through the same call
chain. `ScheduleService` captures the collector's steps (and the already-returned-but-discarded
`timings` bucket) into a new `$this->lastRunSteps` property — the same "reset per run, read by
`writeRunAudit`" pattern `$this->lastRunUsage`/`$this->lastRunAsUser` already use — and includes them
in the context passed to `AuditTrailMapper::createAuditTrailEntry()`. `RunHistoryService` gains a
`getRunTrace()` method that reads one run's full context plus reconstructs any leading gate-wait step
from adjacent entries; `RunHistoryController` exposes it behind the identical owner-ownership guard
`index()` already uses. The frontend adds a timeline render and a client-side JSON blob download to
the existing Run history table in `AgentDetail.vue` — no new views, no new store pattern.

## New Dependencies
None.

## Impact
- **Backend**: `lib/Service/Engine/Engine.php` (+ optional `RunTraceCollector` param, `steps` in the
  return envelope), `lib/Service/Engine/ToolLoop.php` + `lib/Service/Engine/FacadeToolInvoker.php`
  (+ per-tool-call timing into the collector), new `lib/Service/Engine/RunTraceCollector.php`,
  `lib/Service/ScheduleService.php` (capture `timings`/collector steps + time the delivery call +
  pass `steps` into `writeRunAudit()`), `lib/Service/RunHistoryService.php` (+ `getRunTrace()`),
  `lib/Controller/RunHistoryController.php` (+ `trace()`), `appinfo/routes.php` (+ one route).
- **Frontend**: `src/api/agents.js` (+ `getRunTrace()`), `src/views/AgentDetail.vue` (+ timeline
  render + download action in the existing Run history section).
- **Specs**: modifies `run-audit-log` (step enrichment + trace read requirement) and
  `agent-management-ui` (trace view in the existing Run history requirement).

## Cross-Project Dependencies
None. No OpenRegister change is required or proposed; the coarse-step/fine-step split in Scope exists
specifically so this change stays self-contained inside Hermiq (see Risk 2).

## Risks

### Risk 1: The assumed `timings` return-shape from OpenRegister's `ChatService::processMessage()` may not match `Engine`'s
**Severity:** Medium — **Mitigation:** read defensively (`$result['timings'] ?? []`); when the key is
absent or malformed, omit the coarse steps for that run rather than fabricating values. Verified
against a live OR call during Task 1 before being relied upon elsewhere.

### Risk 2: Trace completeness differs by execution path (engine-flag on vs. off), which could read as a bug
**Severity:** Medium — **Mitigation:** each written entry records which path produced it (already
implicit in whether tool-type steps are present); the UI labels a trace with only coarse steps as
"tool-level detail unavailable on this run's execution path" rather than presenting it as complete.

### Risk 3: Gate-wait reconstruction is a heuristic (adjacency by timestamp, not an explicit foreign key)
**Severity:** Low — **Mitigation:** only synthesise a gate-wait step from an unbroken run of
`awaiting_approval`/`skipped_killswitch` entries immediately preceding the real run for the same
schedule; any gap or unexpected status in between means no gate-wait step is shown, never a guessed one.

### Risk 4: Step detail could reintroduce a secret-leak surface into the immutable trail
**Severity:** Medium — **Mitigation:** steps store name/timestamps/outcome only — never raw tool
arguments or raw tool results. Any free-text step detail passes through the existing
`RedactionService::redact()` before the write, preserving the redaction-before-persist invariant
(ADR-004) `run-audit-log` already established.

### Risk 5: Widening the audit entry's `changed` payload increases per-row size on every run, indefinitely
**Severity:** Low — **Mitigation:** steps are capped to bounded, structured fields (no payload bodies);
size growth is linear in tool-call count per run, the same order of magnitude as the existing
`usage`/`summary` fields already stored.

## Rollback Strategy
Purely additive: removing the code reverts to writing the audit entry without a `steps` key.
`RunHistoryService` already treats `steps` as optional (`$context['steps'] ?? []`), so entries written
before or after a rollback both read correctly — no data migration, no schema to revert, no existing
endpoint or UI element removed (the trace view/download action simply disappears from the Run history
section on a build revert).

## Open Questions
- Should the coarse `context`/`history`/`llm` steps also be backfilled for entries written *before*
  this change ships? Provisional answer: no — `steps` is simply absent on historical entries and the
  trace view/endpoint renders "no step detail recorded for this run" for those, exactly as a missing
  `usage` bucket is already handled today.
- Should flow-triggered runs (`FlowAgentRunService`) be folded into the same run-history/trace surface
  in a follow-up change? Not decided here — flagged as a related, pre-existing gap in Out of Scope.
