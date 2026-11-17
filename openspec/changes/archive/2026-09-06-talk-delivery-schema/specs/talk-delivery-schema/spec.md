## ADDED Requirements

### Requirement: Schedule carries an optional Talk delivery target

The `Schedule` schema in `lib/Settings/hermiq_register.json` MUST declare an OPTIONAL
`deliverTarget` property (`type: string`) holding the Talk room token to post to when
`deliver = talk`. It MUST NOT be added to the schema's `required` list, and it MUST NOT be
bound to `deliver = talk` by any conditional (`if`/`then`/`allOf`) requirement — the
OpenRegister importer rejects conditional blocks. Its `description` MUST state that an
empty or unset value means delivery falls back to the owner's Note-to-self conversation.

#### Scenario: A schedule stores a target room token

- **WHEN** a `Schedule` object is created with `deliver = talk` and `deliverTarget` set to
  a room token
- **THEN** the register MUST accept and persist the `deliverTarget` value
- **AND** the schema MUST validate the object without requiring any conditional block

#### Scenario: deliverTarget is optional

- **WHEN** a `Schedule` object is created with `deliver = talk` and no `deliverTarget`
- **THEN** the object MUST validate (the field is optional)

### Requirement: Schedule persists a derived last-delivery-error

The `Schedule` schema MUST declare an OPTIONAL, derived `lastDeliveryError` property
(`type: string`), written by the delivery layer on a delivery failure and left unset by
users — mirroring the existing derived `lastError` / `lastStatus` / `nextRun` fields. It
MUST NOT be added to `required`.

#### Scenario: A delivery error is storable on the schedule

- **WHEN** the delivery layer records a failure message into `lastDeliveryError`
- **THEN** the register MUST accept and persist the string value on the `Schedule` object

#### Scenario: lastDeliveryError defaults unset

- **WHEN** a `Schedule` object is created without `lastDeliveryError`
- **THEN** the object MUST validate with the field unset (optional, derived)

### Requirement: Additions are union-import-safe

The two new properties MUST be added to `components.schemas.Schedule.properties` without
modifying any existing `Schedule` property, the schema's `required` list, or the separate
`example` schema, so that OpenRegister's union-based register import remains idempotent and
non-destructive.

#### Scenario: Re-importing the register does not corrupt the schema

- **WHEN** the Hermiq register is imported (or re-imported) after this change
- **THEN** the existing `Schedule` properties and `required` list MUST be unchanged
- **AND** the two new optional properties MUST be present on `Schedule`
- **AND** the `example` schema MUST be untouched
