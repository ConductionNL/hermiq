# agent-exec-tasktype (delta)

Discharges ADR-032 move 5: the `hermiq:agent-exec` TaskProcessing hand-off from
Hermiq (enqueuing) to `hermiq-exec` (executing). The identical wire contract, from
the worker's side, is specified in
`hermiq-exec/openspec/changes/agent-exec-handler/specs/agent-exec-handler/spec.md`.

## ADDED Requirements

### Requirement: Agent execution-mode selection rule

The system MUST support an `Agent` capability-profile field `executionMode` with
values `inProcess` and `sandboxed`, defaulting to `inProcess` when unset. When a
governed agent run's resolved agent has `executionMode: "sandboxed"`, the system
MUST route the assembled turn to an external `hermiq:agent-exec` TaskProcessing
task instead of the in-process dispatch. When `executionMode` is `inProcess` or
unset, the system MUST run the turn through the existing in-process path
(`ScheduleService::runAgentAsOwner()`) with no behavior change.

#### Scenario: A sandboxed agent routes to the external task

- **GIVEN** a governed agent run whose resolved agent has `executionMode: "sandboxed"`
- **WHEN** the turn is dispatched after the kill-switch and approval gates pass
- **THEN** the system MUST enqueue a `hermiq:agent-exec` TaskProcessing task
- **AND** it MUST NOT run the turn through `ScheduleService::runAgentAsOwner()`

#### Scenario: An unset or in-process agent uses the in-process path unchanged

- **GIVEN** a governed agent run whose resolved agent has `executionMode: "inProcess"` or no `executionMode` set
- **WHEN** the turn is dispatched after the gates pass
- **THEN** the system MUST run the turn through `ScheduleService::runAgentAsOwner()`
- **AND** it MUST NOT enqueue a `hermiq:agent-exec` task

### Requirement: Selection happens after the governance gates

The routing decision between in-process and sandboxed execution MUST occur AFTER
GATE 1 (kill-switch, `ScheduleService::isOrganisationEngaged()`) and GATE 2 (human
approval, `ApprovalService`) have passed, so a sandboxed run receives the same
governance as an in-process run. A run halted by either gate MUST NOT be enqueued
as a `hermiq:agent-exec` task.

#### Scenario: A kill-switched sandboxed run is never enqueued

- **GIVEN** a sandboxed agent run whose organisation has an engaged kill-switch
- **WHEN** the request is dispatched
- **THEN** the system MUST NOT enqueue a `hermiq:agent-exec` task
- **AND** it MUST record the halt in the audit trail exactly as an in-process run would

### Requirement: Fail closed when the worker provider is absent

The system MUST fail closed when a run's resolved agent is `sandboxed` but no
provider has registered the `hermiq:agent-exec` task type: it MUST NOT fall back
to the in-process path and MUST NOT silently drop the run. It MUST record an `agent-run` AuditTrail
entry with `status: "error"` identifying the missing worker, and the turn MUST NOT
execute.

#### Scenario: Sandboxed agent with no worker installed

- **GIVEN** a sandboxed agent run and no registered `hermiq:agent-exec` provider
- **WHEN** the turn is dispatched
- **THEN** the system MUST record an `agent-run` AuditTrail entry with `status: "error"`
- **AND** it MUST NOT run the turn in-process
- **AND** the turn MUST NOT execute

### Requirement: The hermiq:agent-exec input payload

When enqueuing a `hermiq:agent-exec` task, the system MUST assemble an input
carrying `correlation_id`, `agent_id`, `acting_user`, `model`, and `prompt` as
required TEXT fields, and MAY include `system_prompt`, `skill_set` (JSON array of
`{slug, instructions}` inlined skill content), `tool_allowlist` (JSON array of
`{appId}.{toolName}` ids), `context_files` (JSON object of filename→content),
`max_turns`, and `timeout_seconds`. `agent_id` and `acting_user` MUST be present
for audit attribution only; the system MUST NOT place any NC session token or
credential for `acting_user` into the payload, and MUST NOT include any field that
directs the worker at a network host or URL.

#### Scenario: A sandboxed turn is enqueued with the contract payload

- **GIVEN** a sandboxed agent run cleared by the gates
- **WHEN** the system enqueues the `hermiq:agent-exec` task
- **THEN** the input MUST include `correlation_id`, `agent_id`, `acting_user`, `model`, and `prompt`
- **AND** `skill_set` MUST carry inlined skill instruction content, not package references
- **AND** the input MUST NOT carry any credential for `acting_user`

### Requirement: Ingesting the hermiq:agent-exec result

The system MUST ingest a completed `hermiq:agent-exec` task by its `correlation_id`
(the task `customId`). On an execution outcome (`output.status` present), it MUST
record a redacted `agent-run` AuditTrail entry capturing `audit_json` metadata and
the terminal status, and for a flow-triggered run MUST write `response_text` to the
triggering object's `resultField`. A transport-level task failure (an
`error_message` with no output) MUST be recorded as an execution failure in the
audit trail. The system MUST redact the envelope before persisting it.

#### Scenario: A successful sandboxed run is audited and written back

- **GIVEN** a completed `hermiq:agent-exec` task for a flow-triggered run with `output.status: "success"`
- **WHEN** the system ingests the result by `correlation_id`
- **THEN** it MUST record a redacted `agent-run` AuditTrail entry with the terminal status
- **AND** it MUST write `response_text` to the triggering object's `resultField`

#### Scenario: A failed sandboxed task is audited

- **GIVEN** a `hermiq:agent-exec` task reported with an `error_message` and no output
- **WHEN** the system ingests the result
- **THEN** it MUST record an `agent-run` AuditTrail entry reflecting the failure
- **AND** it MUST NOT write the triggering object's `resultField`

### Requirement: In-flight kill-switch drops a late sandboxed result

The system MUST bound an in-flight sandboxed run by re-checking the
kill-switch/approval state by `correlation_id` at ingestion time (NC TaskProcessing
offers no push-cancel channel to an in-flight worker), and MUST drop a late result (no `resultField`
write) with an `agent-run` AuditTrail entry `status: "cancelled"` when the run was
cancelled or the organisation's kill-switch engaged while the task was in flight.

#### Scenario: A result returns after the kill-switch engaged

- **GIVEN** a `hermiq:agent-exec` task that completes after its organisation's kill-switch was engaged
- **WHEN** the system ingests the result by `correlation_id`
- **THEN** it MUST NOT write the triggering object's `resultField`
- **AND** it MUST record an `agent-run` AuditTrail entry with `status: "cancelled"`
