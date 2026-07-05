## ADDED Requirements

### Requirement: Declare the Approval OpenRegister schema

The system MUST declare a declarative OpenRegister schema `Approval` in the app's
schema register `lib/Settings/hermiq_register.json` under `components.schemas`, so
that a durable, auditable human-approval record can be persisted and validated by
OpenRegister's `ObjectService`. The schema MUST define a `status` property as a
required enum of `pending`, `approved`, or `denied` (the Article 14 state machine),
and the properties `scheduleId` (uuid of the gated `Schedule`), `agentId` (uuid of
the bound agent), `prompt` (the task text the run would have used), `requestedAt`
(datetime), `decidedAt` (datetime), `decidedBy` (uid of the deciding user), and
`reason` (free text for a denial or note). To support separation of duties (Art. 14),
the schema MUST also carry the resolved reviewer designation as `reviewer` (string —
the NC user id or group id permitted to decide) and `reviewerType` (enum `user` or
`group`), copied from the gated `Schedule` at creation time so the decision is routed
to a party distinct from the owner. Tenant scoping (`owner`/`organisation`)
MUST come from OpenRegister `ObjectEntity` and MUST NOT be declared as schema
properties. No PHP, controller, or service is introduced by this schema declaration.

#### Scenario: Approval schema is importable into the hermiq register

- **WHEN** the register `lib/Settings/hermiq_register.json` is imported via
  `ConfigurationService::importFromApp()` in the repair step
- **THEN** OpenRegister MUST create the `Approval` schema in the `hermiq` register
  without altering the existing schemas (union import, no regression)
- **AND** an `Approval` object with `status=pending`, a `scheduleId`, an `agentId`,
  and a `requestedAt` MUST validate and persist successfully

#### Scenario: Approval status is constrained to the state machine

- **WHEN** an `Approval` object is created with `status=approved`
- **THEN** the object MUST validate and persist
- **AND** an `Approval` with a `status` value outside `pending`/`approved`/`denied`
  MUST be rejected as an invalid enum value

#### Scenario: Approval carries the resolved reviewer designation

- **WHEN** an `Approval` object is created with `reviewer` set to a group id and
  `reviewerType=group`
- **THEN** the object MUST persist with `reviewer` and `reviewerType` retained so the
  downstream endpoint can guard the decision to that reviewer (or group member)
- **AND** a `reviewerType` value outside `user`/`group` MUST be rejected as an invalid
  enum value

### Requirement: Declare the TenantControl (org kill-switch) schema

The system MUST declare a declarative OpenRegister schema `TenantControl` in
`lib/Settings/hermiq_register.json` under `components.schemas`, representing the
per-organisation kill-switch as a durable OpenRegister object state. The schema MUST
define an `engaged` boolean (required, default `false`) and the properties `reason`
(free text describing why the switch was engaged), `engagedAt` (datetime), and
`engagedBy` (uid of the org admin who toggled it). The organisation the control
applies to MUST come from OpenRegister `ObjectEntity.organisation` and MUST NOT be
declared as a schema property, so a control is scoped to exactly one tenant. When a
tenant's `TenantControl` object has `engaged=true`, the downstream dispatcher MUST
halt all runs for that organisation.

#### Scenario: TenantControl schema is importable and defaults to not engaged

- **WHEN** the register is imported via `ConfigurationService::importFromApp()`
- **THEN** OpenRegister MUST create the `TenantControl` schema in the `hermiq`
  register without altering existing schemas
- **AND** a `TenantControl` object created without an explicit `engaged` value MUST
  persist with `engaged` set to `false`

#### Scenario: TenantControl records who engaged the kill-switch

- **WHEN** a `TenantControl` object is created with `engaged=true`, an `engagedBy`
  uid, and an `engagedAt` datetime
- **THEN** the object MUST persist with those fields retained so the engagement is a
  durable, auditable record for the owning organisation

### Requirement: Schedule declares an approval-required flag

The existing `Schedule` schema MUST declare an optional boolean `requiresApproval`
that defaults to `false`. A `Schedule` with `requiresApproval=true` marks the
schedule as gated: the downstream dispatcher MUST NOT run the agent directly for such
a schedule but MUST instead create a pending `Approval`. The flag MUST NOT change the
behavior of a schedule where `requiresApproval` is absent or `false` (backward
compatible — existing schedules keep running ungated).

To enable separation of duties (Art. 14), the `Schedule` schema MUST also declare an
optional `reviewer` (string — an NC user id or group id who must approve a gated run)
and an optional `reviewerType` (enum `user` or `group`, defaulting to `user`). When
`reviewer` is empty, the downstream dispatcher MUST default the reviewer to the
schedule owner (backward compatible); when set, the gated run MUST be approved by the
designated reviewer (or a member of the reviewer group) rather than the owner.

#### Scenario: requiresApproval defaults to false

- **WHEN** a `Schedule` is created without an explicit `requiresApproval` value
- **THEN** the persisted schedule MUST have `requiresApproval` set to `false` and
  behave exactly as an ungated schedule

#### Scenario: A gated schedule persists the flag and reviewer

- **WHEN** a `Schedule` is created with `requiresApproval=true`, `reviewer` set to a
  group id, and `reviewerType=group`
- **THEN** the object MUST persist with `requiresApproval`, `reviewer`, and
  `reviewerType` retained so the downstream dispatcher can gate the run and route the
  pending `Approval` to the designated reviewer

#### Scenario: Empty reviewer defaults to the owner

- **WHEN** a gated `Schedule` is created with `requiresApproval=true` and no `reviewer`
- **THEN** the object MUST persist and the downstream dispatcher MUST treat the
  schedule owner as the reviewer (backward compatible)
