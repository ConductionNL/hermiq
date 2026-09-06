## ADDED Requirements

### Requirement: Listener enqueues a governed run for an async agent-run request

The system MUST register an `IEventListener` for OpenRegister's
`AgentRunRequestedEvent` that, for `mode: "async"`, enqueues a background job
carrying the event's payload rather than running the agent inline. A `mode` other
than `"async"` MUST be logged and dropped without enqueuing.

#### Scenario: An async agent-run request is enqueued

- **GIVEN** OpenRegister dispatches an `AgentRunRequestedEvent` with `mode: "async"`
- **WHEN** `AgentRunRequestedListener::handle()` receives it
- **THEN** it MUST enqueue `AgentRunRequestedJob` via `IJobList::add()` carrying the
  event's flattened payload
- **AND** it MUST NOT invoke the agent runtime synchronously within `handle()`

#### Scenario: An unsupported mode is dropped

- **GIVEN** an `AgentRunRequestedEvent` with `mode` other than `"async"`
- **WHEN** the listener receives it
- **THEN** it MUST log a warning identifying the unsupported mode
- **AND** it MUST NOT enqueue a job

### Requirement: GATE 1 kill-switch halts a flow-triggered run before the agent is invoked

The governed dispatch MUST check the triggering object's organisation against the
same `TenantControl` kill-switch data source a scheduled run checks
(`ScheduleService::isOrganisationEngaged()`), and MUST halt the run — without ever
invoking the agent — when the organisation's kill-switch is engaged. A halted run
MUST still be recorded via a redacted `agent-run` AuditTrail entry with
`status: "skipped_killswitch"`.

#### Scenario: An engaged kill-switch halts the run

- **GIVEN** the triggering object's organisation has an engaged `TenantControl`
- **WHEN** `FlowAgentRunService::run()` processes the request
- **THEN** the agent MUST NOT be invoked
- **AND** an `agent-run` AuditTrail entry with `status: "skipped_killswitch"` MUST
  be recorded

### Requirement: GATE 2 human approval gates a flow-triggered run requiring approval

The governed dispatch MUST NOT invoke the agent when the event's
`requiresApproval` is `true` and the occurrence is not an authorised bypass.
Instead it MUST ensure a single pending `Approval` OpenRegister object
(idempotent by the event's `correlationId`) tagged `sourceType: "flow"` and
carrying the run's resume context (`flowContext`), and MUST record an
`agent-run` AuditTrail entry with `status: "awaiting_approval"`. The reviewer
MUST default to the resolved agent's `owner`, falling back to the `admin` group
when the agent has no owner.

#### Scenario: A gated request creates a pending flow-sourced approval

- **GIVEN** an `AgentRunRequestedEvent` with `requiresApproval: true`
- **WHEN** `FlowAgentRunService::run()` processes it without a bypass
- **THEN** the agent MUST NOT be invoked
- **AND** exactly one pending `Approval` with `sourceType: "flow"` and matching
  `correlationId` MUST exist, carrying the run's `flowContext`
- **AND** an `agent-run` AuditTrail entry with `status: "awaiting_approval"` MUST
  be recorded

#### Scenario: Approving a flow-sourced Approval resumes the run

- **GIVEN** a pending `Approval` with `sourceType: "flow"` and a stored `flowContext`
- **WHEN** a reviewer approves it
- **THEN** `ApprovalService::approve()` MUST resume the run via
  `FlowAgentRunService::run(payload: flowContext, bypassApprovalGate: true)`
- **AND** it MUST NOT touch `ScheduleService`

### Requirement: The agent turn reuses ScheduleService's engine-routed dispatch

The governed dispatch MUST run the agent turn via
`ScheduleService::runAgentAsOwner()` — the same method, including its
feature-flagged OpenRegister-ChatService/in-app-Engine dual path, that a
scheduled run uses — impersonating the resolved agent's `owner` as the acting
user. It MUST NOT invoke `ChatService`/the in-app `Engine` directly nor
re-implement the impersonation or engine-selection logic.

#### Scenario: The agent turn is dispatched through ScheduleService

- **GIVEN** an ungated, resolvable `AgentRunRequestedEvent` request
- **WHEN** `FlowAgentRunService` runs the agent turn
- **THEN** it MUST call `ScheduleService::runAgentAsOwner(owner, agentId, prompt)`
- **AND** the acting user passed MUST be the resolved agent's `owner`

### Requirement: The agent's output is written to the configured resultField

On a successful agent turn, the governed dispatch MUST write the agent's output to
the triggering object's field named by the event's `resultField`, via
`ObjectService`, and MUST record an `agent-run` AuditTrail entry with
`status: "ok"`. On failure, the object MUST be left unmodified and the AuditTrail
entry MUST carry `status: "error"`.

#### Scenario: A successful run writes the result field

- **GIVEN** an ungated, resolvable request with `resultField: "categorySlug"`
- **WHEN** the agent turn succeeds with output `"Kennel"`
- **THEN** the triggering object's `categorySlug` field MUST be saved as `"Kennel"`
- **AND** an `agent-run` AuditTrail entry with `status: "ok"` MUST be recorded

#### Scenario: A failed run is audited and never throws

- **GIVEN** an ungated, resolvable request
- **WHEN** the agent turn throws
- **THEN** the triggering object MUST NOT be modified
- **AND** an `agent-run` AuditTrail entry with `status: "error"` MUST be recorded
- **AND** `FlowAgentRunService::run()` MUST return `false` rather than propagate
  the exception
