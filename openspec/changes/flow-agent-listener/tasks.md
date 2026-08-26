## 1. Listener and queued job

- [x] 1.1 Create `lib/Listener/AgentRunRequestedListener.php` — validates
      `mode === "async"` (unsupported modes logged + dropped) and enqueues
      `AgentRunRequestedJob` via `IJobList::add()` with the event's flattened
      payload. Registered in `Application.php` alongside the existing
      `DeepLinkRegistrationEvent` listener (no `class_exists()` guard needed —
      OpenRegister is already a hard Hermiq dependency).
- [x] 1.2 Mode validation + enqueue failure handling — both logged, never thrown,
      so a broken listener can never fail OpenRegister's `dispatchTyped()` call.
- [x] 1.3 Create `lib/BackgroundJob/AgentRunRequestedJob.php` — a one-shot `QueuedJob`
      (ADR-002 thin wrapper) delegating entirely to `FlowAgentRunService::run()`.

## 2. FlowAgentRunService (governed dispatch)

- [x] 2.1 Resolve the triggering object; GATE 1 (kill-switch, via
      `ScheduleService::isOrganisationEngaged()` — new public method extracted
      from the existing private `loadEngagedOrganisations()` query); GATE 2
      (human approval, via `ApprovalService::ensurePendingApprovalForFlowRun()`
      when `requiresApproval` and not bypassed).
- [x] 2.2 Resolve the configured agent (UUID only in v1) and its `owner`
      (acting-user impersonation target); run the agent turn via
      `ScheduleService::runAgentAsOwner()` — widened to `public`, reused
      verbatim (same feature-flagged OR-ChatService/in-app-Engine dual path a
      scheduled run uses).
- [x] 2.3 Write the agent's output to the triggering object's configured
      `resultField` via `ObjectService` (read-modify-write on the whole object).
- [x] 2.4 Write a redacted, explicit `agent-run` AuditTrail entry
      (ok/error/skipped_killswitch/awaiting_approval) — non-fatal by design.

## 3. ApprovalService generalisation (sourceType: flow)

- [x] 3.1 `ensurePendingApprovalForFlowRun()` — idempotent by `correlationId`
      (the flow-run counterpart to the existing `scheduleId`-keyed idempotency),
      reviewer defaults to the agent's `owner`, falling back to the `admin`
      group when absent.
- [x] 3.2 `DeliveryService::deliverApprovalRequestForFlowRun()` — notifies the
      resolved reviewer(s) using the approval's own `flowContext` (no Schedule
      object to read a display name from).
- [x] 3.3 `approve()` branches on `sourceType`: `"schedule"` resumes via
      `ScheduleService::runNow(bypassApprovalGate: true)` (unchanged path);
      `"flow"` resumes via `FlowAgentRunService::run(payload: $flowContext,
      bypassApprovalGate: true)`.

## 4. Approval schema (v0.1.0 → v0.2.0, additive)

- [x] 4.1 `hermiq_register.json`: relax `required` (drop `scheduleId`); add
      optional `sourceType` (enum, default `schedule`), `correlationId`,
      `flowContext` — back-compatible, existing Approval objects keep validating.

## 5. Gate-28 cleanup (touched-file scope)

- [x] 5.1 Add `title` + `description` to every property (including nested
      object/array-item properties) across ALL schemas in `hermiq_register.json`
      that lacked them: `example`, `Schedule`, `Approval` (this change's own),
      `AiFeature`, `TenantControl`, `Memory`, `UserProfile`, `Session`,
      `SessionTurn`, `Skill`, `SkillSource`. `Agent`/`Conversation`/`Message`/
      `Feedback` already had full coverage from a prior pass. Verified zero
      findings via `check_schema_property_meta.py`.

## 6. Tests

- [x] 6.1 `ScheduleServiceTest`: `isOrganisationEngaged()` reflects TenantControl
      state for a given organisation (engaged/not-engaged/empty).
- [x] 6.2 `ApprovalServiceTest`: `ensurePendingApprovalForFlowRun` creates +
      notifies (sourceType=flow, flowContext stored verbatim, agent-owner
      reviewer default); idempotent by correlationId; defaults to `admin` group
      reviewer with no agent owner; `approve()` on a `sourceType: "flow"`
      Approval resumes via `FlowAgentRunService::run(…, bypassApprovalGate: true)`
      without touching `ScheduleService`.
- [x] 6.3 `DeliveryServiceTest`: `deliverApprovalRequestForFlowRun` notifies each
      resolved reviewer using the approval's `flowContext`.
- [x] 6.4 `FlowAgentRunServiceTest` (new, 11 cases): happy path (write-back +
      audit); skill-prefix directive; kill-switch halts before the agent runs;
      approval gate creates a pending approval and skips the run; approval
      bypass runs without gating; kill-switch overrides an approval bypass; a
      run failure is audited as `error` and never throws; malformed payloads
      (missing subject identity / unresolvable object / unresolvable agent /
      agent with no owner) are skipped without ever calling `runAgentAsOwner`.
- [x] 6.5 `AgentRunRequestedListenerTest` (new, 4 cases): an unrelated event is
      ignored; `mode: "async"` enqueues the job with the flattened payload; an
      unsupported mode is skipped + logged; a job-list failure is caught +
      logged, never thrown.
- [x] 6.6 `AgentRunRequestedJob` — no dedicated unit test, consistent with the
      existing codebase convention that thin `QueuedJob`/`TimedJob` wrappers
      (`ScheduleTask`, `SkillCuratorTask`) are untested directly; all branching
      logic lives in, and is tested via, `FlowAgentRunService`.
- [x] 6.7 Full suite green: 296 tests (275 pre-existing baseline + 21 new),
      8 pre-existing failures unrelated to this change (missing `LLPhant` vendor
      classes in this sandbox's vendor snapshot — `Engine`/`Llm` subsystem tests,
      untouched by this change; confirmed by file scope and by the exact
      296 − 21 = 275 baseline-count match).

## Acceptance criteria

- A dispatched `AgentRunRequestedEvent` (mode=async) is enqueued and, on the next
  background-job tick, produces a governed agent run: kill-switch checked first,
  approval gate second, agent turn third, result written to `resultField`, run
  audited.
- The kill-switch and approval gate use the IDENTICAL data sources/services a
  scheduled run uses (`ScheduleService::isOrganisationEngaged`,
  `ApprovalService`) — no second kill-switch/approval implementation.
- The agent turn runs via `ScheduleService::runAgentAsOwner()` — identical
  engine routing (OR ChatService / in-app Engine feature flag) to a scheduled run.
- Existing scheduled-run Approval behaviour is byte-for-byte unchanged (verified:
  all pre-existing `ScheduleServiceTest`/`ApprovalServiceTest` cases pass
  unmodified).
- `hermiq_register.json`'s Approval schema change is additive/back-compatible.
- No new HTTP routes; no direct call into OpenRegister beyond its existing public
  services (`ObjectService`, `AgentMapper`, `AuditTrailMapper`).

## Quality checklist

- ADR-002: `AgentRunRequestedListener`/`AgentRunRequestedJob` are thin wrappers;
  all logic lives in `FlowAgentRunService`.
- ADR-004: EU AI Act Art. 14 human oversight — the approval gate is a hard,
  synchronous block before any agent invocation, exactly like the scheduled path.
- ADR-020: diff-scoped — pre-existing `hermiq_register.json` title/description
  debt is fixed because the file is touched (gate-28 scans the whole file), not
  because those nine schemas are otherwise in scope for this change.
- ADR-031: `FlowAgentRunService`/`ApprovalService`/`ScheduleService` are the
  recognised imperative-governance exception (side-effecting orchestrators, not
  derived values or declarative lifecycles) — unchanged classification from the
  existing `agent-schedule-dispatcher`/`human-approval-gate-enforcement` changes.
- ADR-041: consumes OpenRegister's event by class reference (hard dependency,
  no guard needed) — mirrors the existing `DeepLinkRegistrationEvent` precedent.
- SPDX + `@spec` tags present on all new/changed public surface.
