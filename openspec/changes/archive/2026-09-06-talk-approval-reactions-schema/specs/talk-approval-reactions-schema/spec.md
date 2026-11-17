## ADDED Requirements

### Requirement: Approval records the Talk message that carries it

The `Approval` schema in `lib/Settings/hermiq_register.json` MUST declare OPTIONAL
`talkRoomToken` (`type: string`) and `talkMessageId` (`type: string`) properties recording where
an approval request was posted. `talkMessageId` MUST be declared as a **top-level property** of
`components.schemas.Approval.properties` — NOT nested inside another object — because the
reaction handler resolves an approval BY this value, and OpenRegister's dot-path filters on
nested JSON match nothing. Neither MUST be added to `required`, and neither MUST carry a
conditional (`if`/`then`/`allOf`) block.

#### Scenario: An approval records where it was posted

- **WHEN** an `Approval` object is saved with `talkRoomToken` and `talkMessageId` set
- **THEN** the register MUST accept and persist both values

#### Scenario: The message binding is filterable

- **WHEN** approvals are queried with a filter on `talkMessageId` equal to a stored value
- **THEN** the approval carrying that message MUST be returned

#### Scenario: The binding is optional

- **WHEN** an `Approval` object is created with neither field
- **THEN** the object MUST validate, and the approval is simply not reachable from Talk

### Requirement: Approval records how it was decided

The `Approval` schema MUST declare an OPTIONAL `decidedVia` property (`type: string`) recording
the surface a decision arrived from — `inbox` or `reaction`. Its `description` MUST state that it
is written by the decision path and never by a user, so that an audit reader can distinguish a
one-click reaction from a decision made in the approvals inbox. It MUST NOT be added to
`required`.

#### Scenario: A reaction-borne decision is marked as such

- **WHEN** an approval is decided and `decidedVia` is set to `reaction`
- **THEN** the register MUST accept and persist the value alongside the existing `decidedBy` and
  `decidedAt` fields

#### Scenario: Existing approvals carry no provenance

- **WHEN** an `Approval` decided before this change is read
- **THEN** it MUST validate with `decidedVia` unset

### Requirement: The addition is union-import-safe and actually applied

The three properties MUST be added to `components.schemas.Approval.properties` without modifying
any existing property, the schema's `required` list, or any other schema. Each MUST carry a
non-empty `title`. The register's `info.version` MUST be bumped, because an import that does not
raise the version advances state without applying the change to the already-existing schema.

#### Scenario: Re-importing does not corrupt the schema

- **WHEN** the Hermiq register is imported after this change
- **THEN** the existing `Approval` properties and `required` list MUST be unchanged
- **AND** the three new optional properties MUST be present

#### Scenario: Every added property is titled

- **WHEN** the three added properties are inspected in the register JSON
- **THEN** each MUST declare a non-empty `title`
