## ADDED Requirements

### Requirement: Declare the Schedule OpenRegister schema

The system MUST declare a declarative OpenRegister schema `Schedule` (Schema.org
type: `Schedule`) in the app's schema register `lib/Settings/hermiq_register.json`
under `components.schemas`, so that schedule objects can be persisted and validated
by OpenRegister's `ObjectService`. The schema MUST define the properties `name`,
`agentId`, `kind`, `cronExpr`, `intervalMinutes`, `runAt`, `prompt`, `deliver`,
`enabled`, `repeat`, `nextRun`, `lastStatus`, and `lastError`. Tenant scoping
(`owner`/`organisation`) MUST come from OpenRegister `ObjectEntity` and MUST NOT be
declared as schema properties. No PHP, controller, or service is introduced by this
schema declaration.

#### Scenario: Schedule schema is importable into the hermiq register

- **WHEN** the register `lib/Settings/hermiq_register.json` is imported via
  `ConfigurationService::importFromApp()` in the repair step
- **THEN** OpenRegister MUST create the `Schedule` schema in the `hermiq` register
  without altering the existing schemas (union import, no regression)
- **AND** a `Schedule` object with `name`, `agentId`, `kind`, `deliver`, and
  `enabled` set MUST validate and persist successfully

### Requirement: Schedule binds to an OpenRegister Agent

The `Schedule.agentId` property MUST be a required string holding the UUID of an
existing OpenRegister `Agent`, declared as a reference to the OpenRegister `Agent`
entity so the dispatcher can resolve and fire the bound agent. The `name` property
MUST be a required human-readable label.

#### Scenario: agentId references an agent

- **WHEN** a `Schedule` is created with `name`, a valid `agentId` UUID, `kind`,
  `deliver`, and `enabled`
- **THEN** the object MUST persist with `agentId` retained as the agent reference
- **AND** a save with `agentId` missing MUST be rejected as a required-field
  validation error

### Requirement: Kind selects the trigger fields

The `Schedule.kind` property MUST be a required enum of `once`, `interval`, or
`cron`. When `kind=cron`, the schema MUST require a `cronExpr` string (a 5- or
6-field POSIX cron expression). When `kind=interval`, the schema MUST require an
integer `intervalMinutes` of at least 1. When `kind=once`, the schema MUST require
a `runAt` datetime. The conditional requirements MUST be expressed declaratively in
the schema.

#### Scenario: Cron kind requires a cron expression

- **WHEN** a `Schedule` is created with `kind=cron` and `cronExpr="0 8 * * *"`
- **THEN** the object MUST validate and persist
- **AND** a `Schedule` with `kind=cron` but no `cronExpr` MUST be rejected

#### Scenario: Interval kind requires a positive interval

- **WHEN** a `Schedule` is created with `kind=interval` and `intervalMinutes=60`
- **THEN** the object MUST validate and persist
- **AND** a `Schedule` with `kind=interval` and `intervalMinutes` below 1 MUST be
  rejected

#### Scenario: Once kind requires a run time

- **WHEN** a `Schedule` is created with `kind=once` and a `runAt` datetime
- **THEN** the object MUST validate and persist
- **AND** a `Schedule` with `kind=once` but no `runAt` MUST be rejected

### Requirement: Delivery, enablement, and repeat controls

The `Schedule.deliver` property MUST be a required enum of `talk`, `notification`,
or `none`. The `enabled` property MUST be a required boolean defaulting to `true`;
disabled schedules are skipped by the dispatcher. The `repeat` property MUST be an
optional object with integer members `times` and `completed`; a null `repeat` means
run forever. The `prompt` property MUST be an optional string carrying the task text
passed to the agent run.

#### Scenario: Enabled defaults to true

- **WHEN** a `Schedule` is created without an explicit `enabled` value
- **THEN** the persisted object MUST have `enabled` set to `true`

#### Scenario: Repeat tracks completed runs

- **WHEN** a `Schedule` is created with `repeat` set to `{ "times": 3, "completed": 0 }`
- **THEN** the object MUST persist with the `repeat` object retained for the
  dispatcher to increment

### Requirement: Derived run-state fields

The schema MUST declare the derived run-state properties `nextRun` (datetime, the
next fire time), `lastStatus` (string, last run outcome), and `lastError` (string,
last error text). These fields are written by the dispatcher, not by the creating
user; the schema declares them so schedule objects can carry run state. Timezone for
`nextRun` computation is anchored to the schedule owner's configured timezone, not
server-local — computation is performed by the downstream dispatcher.

#### Scenario: Run-state fields are present on the schema

- **WHEN** the `Schedule` schema is imported
- **THEN** the schema MUST expose `nextRun`, `lastStatus`, and `lastError` so a
  persisted schedule can carry the outcome of its most recent dispatch
