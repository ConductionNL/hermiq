# Tasks: agent-exec-tasktype (Hermiq / PHP side)

Spec-first change. These tasks are the future implementation checklist for
Hermiq's side of the `hermiq:agent-exec` hand-off. No code is written in this
change.

## 1. Capability-profile field: executionMode

- [ ] 1.1 Add `executionMode` (enum `inProcess` | `sandboxed`, default
  `inProcess`) to the OR `Agent` schema chunk, alongside `actingUser` /
  `skillInstalls` / `tools` (agent-capability-profile). Absent field resolves to
  `inProcess`.
- [ ] 1.2 Surface `executionMode` in the agent-management UI as an opt-in toggle,
  with copy explaining it routes runs to the egress-jailed worker.

## 2. Routing seam (post-gate, both entry paths)

- [ ] 2.1 At the single agent-turn dispatch point reached by both scheduled runs
  and `FlowAgentRunService::runAgentAndWriteBack()` — AFTER GATE 1 (kill-switch)
  and GATE 2 (approval) — branch on the resolved agent's `executionMode`.
- [ ] 2.2 `inProcess`: unchanged — call `ScheduleService::runAgentAsOwner()`.
- [ ] 2.3 `sandboxed`: assemble the turn (reuse `ContextAssembler`) and enqueue a
  `hermiq:agent-exec` task instead of running in-process.
- [ ] 2.4 Fail closed: when `executionMode == sandboxed` but no `hermiq:agent-exec`
  provider is registered (`IManager` has no provider for the type), write an
  `agent-run` AuditTrail entry `status: "error"` and do not execute — no silent
  in-process fallback, no silent drop.

## 3. Enqueue (payload assembly + transport)

- [ ] 3.1 Assemble the INPUT payload exactly per `design.md`'s field table
  (`correlation_id`, `agent_id`, `acting_user`, `model`, `system_prompt`,
  `prompt`, `skill_set`, `tool_allowlist`, `context_files`, `max_turns`,
  `timeout_seconds`).
- [ ] 3.2 Materialize `skillInstalls` into `skill_set` as inlined
  `{slug, instructions}` content (resolve each installed Skill object), NOT as
  package references.
- [ ] 3.3 Schedule via `OCP\TaskProcessing\IManager::scheduleTask()` (async), task
  type `hermiq:agent-exec`, `appId` `hermiq`, `customId` = `correlation_id`.

## 4. Result ingestion (audit + write-back)

- [ ] 4.1 Register a listener for `OCP\TaskProcessing\Events\TaskSuccessfulEvent`
  and `TaskFailedEvent`, correlating by `customId` == `correlation_id`.
- [ ] 4.2 Parse the OUTPUT envelope per `design.md` (`status`, `response_text`,
  `artifacts`, `audit_json`, `error_detail`); treat a failed task
  (`error_message` set) as an execution failure too.
- [ ] 4.3 Write a redacted `agent-run` AuditTrail entry (reuse
  `FlowAgentRunService`'s redaction-before-persist), recording `audit_json`
  metadata and the terminal status.
- [ ] 4.4 For a flow-triggered run, write `response_text` to the triggering
  object's `resultField` via `ObjectService` — the same write-path
  `FlowAgentRunService::writeResultField()` uses.
- [ ] 4.5 In-flight kill-switch honour: on ingest, re-check kill-switch/approval by
  `correlation_id`; drop a late result (no `resultField` write) with audit
  `status: "cancelled"` if cancelled while in flight.

## 5. Security boundary enforcement (Hermiq side)

- [ ] 5.1 Never place any NC session/token/credential for `acting_user` into the
  payload — attribution fields only.
- [ ] 5.2 No egress-widening field in the payload (no URL/host/target the worker
  acts on).
- [ ] 5.3 Redact the ingested envelope (`RedactionService`) before the audit write.

## 6. Tests

- [ ] 6.1 Unit: `inProcess` (default + explicit) routes to `runAgentAsOwner()`;
  `sandboxed` enqueues a `hermiq:agent-exec` task with the exact payload shape.
- [ ] 6.2 Unit: fail-closed when the provider is absent (audited error, no
  in-process fallback).
- [ ] 6.3 Unit: ingestion writes the audit entry + `resultField` for success;
  drops a late/cancelled result; audits `failure`/`timeout`/`refused`.
- [ ] 6.4 Contract test: the assembled payload keys/types match the field table in
  BOTH this change's and `hermiq-exec/.../agent-exec-handler`'s `design.md`.
