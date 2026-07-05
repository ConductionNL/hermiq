## ADDED Requirements

### Requirement: Dispatcher blocks a gated run and creates a pending Approval

The dispatcher MUST NOT invoke the bound agent when a due schedule (or an on-demand
`runNow`) has `requiresApproval=true`. Instead it MUST create an `Approval`
OpenRegister object in `status=pending` carrying the schedule's `scheduleId`,
`agentId`, `prompt`, and `requestedAt`, inheriting the schedule's `owner` and
`organisation` through `ObjectService`, and MUST return without running the gated
action. The dispatcher MUST resolve the reviewer from the schedule's `reviewer` /
`reviewerType` and copy the resolved `reviewer`/`reviewerType` onto the pending
`Approval`; when the schedule's `reviewer` is empty the dispatcher MUST default the
reviewer to the schedule `owner` (`reviewerType=user`), preserving backward
compatibility. The run MUST execute only after the `Approval` reaches `approved`. The
at-most-once run-state advance MUST still be applied so the schedule does not enqueue
a duplicate pending `Approval` on every subsequent tick.

#### Scenario: A due gated schedule creates a pending approval instead of running

- **GIVEN** an enabled `Schedule` with `requiresApproval=true` whose `nextRun` is due
- **WHEN** the dispatcher processes it during a tick
- **THEN** the dispatcher MUST NOT call the OpenRegister agent runtime
- **AND** a new `Approval` object in `status=pending` MUST be created via
  `ObjectService` carrying the schedule's `scheduleId`, `agentId`, `prompt`, and a
  `requestedAt`, with the schedule's `owner`/`organisation` inherited

#### Scenario: The pending approval is routed to the schedule reviewer

- **GIVEN** a due gated `Schedule` whose `reviewer` is a group id and
  `reviewerType=group`
- **WHEN** the dispatcher creates the pending `Approval`
- **THEN** the `Approval` MUST carry that `reviewer`/`reviewerType` so the decision is
  routed to the group (not the owner)
- **AND** when the schedule's `reviewer` is empty, the `Approval` MUST default
  `reviewer` to the schedule owner with `reviewerType=user`

#### Scenario: Run-now on a gated schedule also gates

- **GIVEN** a `Schedule` with `requiresApproval=true`
- **WHEN** the owner presses "Run now"
- **THEN** the agent MUST NOT run immediately
- **AND** a pending `Approval` MUST be created exactly as for a scheduled tick

### Requirement: Reviewer is notified when an approval becomes pending

Whenever the dispatcher creates a pending `Approval`, the system MUST notify the
resolved **reviewer** (the designated user, or every member of the reviewer group,
defaulting to the owner when no reviewer is set) via the existing delivery layer
(Nextcloud Talk with a Notifications fallback, since Talk is not guaranteed
installed), and the notification MUST link to the pending approval. A notification
failure MUST NOT fail the dispatch tick.

#### Scenario: A pending approval notifies the reviewer

- **GIVEN** the dispatcher has created a pending `Approval` whose resolved reviewer is
  user R (or a group G)
- **WHEN** the object is persisted
- **THEN** the system MUST send R (or each member of group G) a Talk message, or a
  Nextcloud notification when Talk is unavailable, linking to the pending approval
- **AND** a delivery failure MUST be recorded as a warning and MUST NOT fail the tick

### Requirement: Approving an approval executes the gated run

The system MUST allow only the designated **reviewer** (the `Approval.reviewer` user,
or any member of the `Approval.reviewer` group when `reviewerType=group`) or an
instance admin to approve a pending `Approval`; the schedule owner MUST NOT be able to
approve unless they are themselves the reviewer (separation of duties, Art. 14). On approval the
system MUST set the `Approval` to `status=approved` with `decidedAt` and `decidedBy`,
record the decision in the hash-chained `AuditTrail` (after redaction), and then
execute the gated run by reusing the existing `runNow` dispatch path for the bound
schedule. The approve endpoint MUST be IDOR-guarded: it loads the `Approval` with RBAC
and refuses with 404 unless the caller is the resolved reviewer (or reviewer-group
member) or an instance admin.

#### Scenario: The reviewer approves a pending approval

- **GIVEN** a pending `Approval` for schedule S whose reviewer is user R (or group G)
- **WHEN** R (or a member of group G) calls the approve endpoint for that `Approval`
- **THEN** the `Approval` MUST become `status=approved` with `decidedAt`/`decidedBy`
  set and an `AuditTrail` entry recorded
- **AND** schedule S's bound agent MUST then run via the shared `runNow` path

#### Scenario: A non-reviewer cannot approve

- **GIVEN** a pending `Approval` whose reviewer is user R
- **WHEN** a different user who is neither R, a member of the reviewer group, nor an
  instance admin (including the schedule owner when owner ≠ reviewer) calls the approve
  endpoint
- **THEN** the system MUST refuse with 404 and MUST NOT run the gated action

### Requirement: Denying an approval prevents the gated run

The designated reviewer (or reviewer-group member) or an instance admin MUST be able
to deny a pending `Approval`, under the same separation-of-duties guard as approve. On
denial the system MUST set the `Approval` to `status=denied` with `decidedAt`,
`decidedBy`, and an optional `reason`, record the decision in the `AuditTrail`, and
MUST NOT execute the gated run now or later. The deny endpoint MUST be IDOR-guarded
identically to approve.

#### Scenario: The reviewer denies a pending approval

- **GIVEN** a pending `Approval` for schedule S whose reviewer is user R
- **WHEN** R calls the deny endpoint with a reason
- **THEN** the `Approval` MUST become `status=denied` with `decidedAt`/`decidedBy`/
  `reason` set and an `AuditTrail` entry recorded
- **AND** schedule S's gated agent MUST NOT run as a result of this approval

### Requirement: Kill-switch halts all runs for the engaged organisation

Before firing any due schedule, the dispatcher MUST check whether the schedule's
organisation has a `TenantControl` object with `engaged=true`. When engaged, the
dispatcher MUST NOT run the agent for any schedule in that organisation — it MUST skip
the run, record it (`lastStatus='skipped_killswitch'`), and write a redacted
`AuditTrail` entry. The check MUST be synchronous within the tick, and runs for
organisations without an engaged control MUST proceed unaffected.

#### Scenario: Engaged kill-switch skips all of an org's runs

- **GIVEN** organisation A has a `TenantControl` with `engaged=true` and one or more
  due schedules
- **WHEN** the dispatcher runs a tick
- **THEN** every due schedule belonging to organisation A MUST be skipped without
  invoking the agent, each recording `lastStatus='skipped_killswitch'` and an
  `AuditTrail` entry
- **AND** due schedules in organisations without an engaged control MUST run normally

### Requirement: Org subadmin or instance admin can engage and disengage the kill-switch

The system MUST allow only a Nextcloud **sub-admin of the organisation's group** or a
**Nextcloud instance admin** to engage and disengage that organisation's kill-switch
through a guarded endpoint that writes the `TenantControl` object via `ObjectService`
(so the toggle is auditable). A plain owner or any other authenticated user MUST NOT be able
to toggle it. The guard MUST use `IGroupManager` (instance-admin via `isAdmin`, and
group sub-admin via the group sub-admin check) against the NC group that maps to the
organisation, and MUST be scoped so a caller can only toggle a `TenantControl` for an
organisation they administer; a cross-tenant toggle MUST be refused.

#### Scenario: Org subadmin engages the kill-switch

- **GIVEN** a caller who is a sub-admin of the organisation's NC group (or an instance
  admin)
- **WHEN** they call the toggle endpoint to engage that organisation's `TenantControl`
- **THEN** the `TenantControl` MUST persist with `engaged=true`, `engagedBy`, and
  `engagedAt` via `ObjectService`

#### Scenario: A non-admin cannot toggle the kill-switch

- **WHEN** an authenticated user who is neither an instance admin nor a sub-admin of
  the target organisation's group calls the toggle endpoint (including a schedule
  owner with no admin rights, or an admin of a different organisation)
- **THEN** the system MUST refuse the toggle and MUST NOT write the `TenantControl`
