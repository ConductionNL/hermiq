---
kind: code
depends_on: []
---

# Proposal: flow-agent-listener

## Why

SPECTR-NEXTCLOUD-PLAN.md §5.2 designs the `type: "agent"` OpenRegister flow action
to reach Hermiq's agent runtime through a typed cross-app event
(`AgentRunRequestedEvent`, ADR-041) rather than a direct call — OR must never call
Hermiq (or any agent-runtime app) directly, and gate-27 (`no-phantom-cross-app-rpc`)
forbids it. OpenRegister's companion change (`flow-agent-action`, a different repo)
dispatches that event; today nothing in Hermiq listens for it, so a flow author who
configures `type: "agent"` gets a silent no-op — the event reaches no code.

This change is the Hermiq-side listener: it turns a dispatched
`AgentRunRequestedEvent` into a run through Hermiq's EXISTING governed dispatch
path — the same kill-switch, human-approval gate, redacted audit trail, and
agent-engine routing (`ScheduleService::runAgentAsOwner`, including its
feature-flagged OR-ChatService/in-app-Engine dual path) a scheduled run already
gets. Flow-triggered LLM work becomes as governed as scheduled work — the concrete
payoff of Hermiq owning the agent-run engine (plan §5.2 point 2).

## Cross-repo dependency

**`depends_on: openregister feat/flow-agent-action`** (cross-repo — openspec's
`depends_on` frontmatter only resolves within a single repo, so this is recorded in
prose per the two-branch task brief). This change references OpenRegister's
`OCA\OpenRegister\Event\AgentRunRequestedEvent` class directly (Hermiq already
hard-depends on OpenRegister for `ChatService`/`ObjectService`/`AgentMapper`, so no
`class_exists()` guard is needed — mirrors the existing
`DeepLinkRegistrationEvent`/`DeepLinkRegistrationListener` precedent). The exact
payload shape (field names + types) MUST match verbatim between the two branches;
see `design.md`'s event contract table, copied from the OpenRegister change's
`design.md`.

## What Changes

- **`lib/Listener/AgentRunRequestedListener.php`** (new) — a fast `IEventListener`
  for `AgentRunRequestedEvent`: validates `mode === "async"` (the only supported
  value in v1; anything else is logged and dropped) and enqueues a background job
  with the event's flattened payload. The listener itself never runs the agent —
  keeping it fast means the triggering OpenRegister save/request never blocks on an
  LLM call.
- **`lib/BackgroundJob/AgentRunRequestedJob.php`** (new) — a one-shot `QueuedJob` (ADR-002
  thin wrapper) that delegates the entire governed dispatch to
  `FlowAgentRunService::run()`.
- **`lib/Service/FlowAgentRunService.php`** (new) — the governed dispatch:
  1. Resolves the triggering object (uuid/register/schema from the payload).
  2. GATE 1 — kill-switch: reuses `ScheduleService::isOrganisationEngaged()` (new
     public method, extracted from the existing private kill-switch query) so a
     flow-triggered run is halted by the exact same `TenantControl` data source a
     scheduled tick already reads.
  3. GATE 2 — human approval (Art. 14): when `requiresApproval` is true, reuses
     `ApprovalService`, generalised with a new `sourceType: "flow"` Approval shape
     carrying the run's resume context (`flowContext`), keyed by the event's
     `correlationId` for idempotency (mirrors the existing `scheduleId`-keyed
     schedule-approval idempotency).
  4. Resolves the configured agent (UUID only in v1) and runs the agent turn via
     `ScheduleService::runAgentAsOwner()` — the SAME method (and its
     `engine.enabled`-flagged OR-ChatService/in-app-Engine dual path) a scheduled
     run calls, as the agent's own `owner` (the closest existing analogue to the
     planned per-agent `actingUser` profile field, plan §6.3, which does not exist
     yet).
  5. Writes the result to the triggering object's configured `resultField` via
     `ObjectService`, and writes a redacted `agent-run` AuditTrail entry
     (ok/error/skipped_killswitch/awaiting_approval).
- **`ApprovalService` generalised** for a second approval source: `ensurePendingApproval()`
  now tags `sourceType: "schedule"` explicitly; a new
  `ensurePendingApprovalForFlowRun()` creates the `sourceType: "flow"` counterpart;
  `approve()` branches on `sourceType` to resume via `ScheduleService::runNow()` or
  `FlowAgentRunService::run()` (both bypassing the gate for the authorised
  occurrence).
- **`DeliveryService` gains `deliverApprovalRequestForFlowRun()`** — the
  flow-run counterpart to `deliverApprovalRequest()`, notifying the resolved
  reviewer using the approval's own `flowContext` (there is no Schedule object to
  read a display name from).
- **`hermiq_register.json` Approval schema** (v0.2.0, additive/back-compatible):
  `scheduleId` is no longer required (only `status`/`agentId`/`requestedAt` are);
  new optional `sourceType`, `correlationId`, `flowContext` properties.
- **`lib/AppInfo/Application.php`** registers the new listener for
  `AgentRunRequestedEvent`, alongside the existing `DeepLinkRegistrationEvent`
  registration.
- Pre-existing title/description debt across the rest of `hermiq_register.json`
  (10 schemas untouched by any prior cleanup pass) fixed opportunistically —
  touching the register file puts the WHOLE file in gate-28
  (`schema-property-titles`) scope; see `tasks.md` for the full list.

## Capabilities

### New Capabilities

- `flow-agent-listener`: Hermiq's consumer side of the ADR-041 `AgentRunRequestedEvent`
  contract — the listener, background job, and governed-dispatch service that turn
  a flow-triggered agent-run request into an actual, governed run.

### Modified Capabilities

- `human-approval-gate-enforcement`: the `Approval` object model and `ApprovalService`
  are generalised to gate a second kind of run (`sourceType: "flow"`) alongside the
  original scheduled-run shape, without changing any existing schedule-approval
  behaviour (verified: `approve()`/`deny()`/`isReviewer()` on a `sourceType`-less or
  `sourceType: "schedule"` Approval are unaffected — existing tests pass unmodified).
- `agent-schedule-dispatcher`: `ScheduleService::isOrganisationEngaged()` and
  `ScheduleService::runAgentAsOwner()` are widened from private to public so
  `FlowAgentRunService` can reuse the identical kill-switch check and agent-turn
  dispatch a scheduled run uses — no behavioural change to `ScheduleService` itself.

## Impact

- **No new endpoints, no route changes.** The listener/job/service triad is purely
  event-driven + background-job-driven.
- **`hermiq_register.json` Approval schema v0.1.0 → v0.2.0**, additive and
  back-compatible (existing scheduled-run Approvals keep working unchanged — the
  `sourceType` field defaults to `"schedule"` when absent, matching legacy data).
- **Downstream**: none yet — this closes the loop OpenRegister's `flow-agent-action`
  change opened. Once both branches merge, a flow author can configure
  `type: "agent"` end-to-end.
