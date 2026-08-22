# ai-oversight

## ADDED Requirements

### Requirement: Hermiq records advisory human-oversight decisions from consumer apps

Hermiq SHALL accept a record of a human decision on an AI suggestion that was
never gated, and store it as an `Approval` with `sourceType: "advisory"`. The
record SHALL be terminal at creation — an advisory Approval SHALL NOT be
`pending`.

Consumer apps SHALL deliver the record by dispatching
`OCA\Hermiq\Event\AiOversightRecordedEvent`. They SHALL NOT write into hermiq's
register directly (ADR-041).

#### Scenario: An accepted suggestion is recorded

- **GIVEN** a consumer app dispatches an oversight record with `humanAction: "accepted"`
- **THEN** hermiq writes an Approval with `sourceType: "advisory"` and `status: "approved"`
- **AND** `requestedAt` and `decidedAt` both carry the decision time
- **AND** `decidedVia` is `origin-app`

#### Scenario: A corrected suggestion keeps its own outcome

- **GIVEN** a record with `humanAction: "overridden"` and an `actualValue`
- **THEN** the Approval's `status` is `overridden`, not `denied`
- **AND** `advisoryContext.actualValue` holds what the human used
- **AND** `advisoryContext.suggestion` still holds what the model proposed

#### Scenario: The consumer can tell recorded from not-installed

- **GIVEN** a consumer dispatches the event on an instance where hermiq is absent
- **THEN** nothing listens and the event's `isHandled()` is false
- **AND** on an instance where hermiq is present and the record was written, `isHandled()` is true and `getApprovalId()` returns the Approval's id

### Requirement: An incomplete or unrecognised record is refused

Hermiq SHALL refuse a record missing `originApp`, `subjectType`, `subjectId` or
`humanAction`, and SHALL refuse a `humanAction` outside
`accepted` / `rejected` / `overridden`. A refused record SHALL NOT be stored,
SHALL be logged, and SHALL leave the event unhandled.

#### Scenario: A record that cannot name its subject is not stored

- **GIVEN** an oversight record with no `subjectId`
- **THEN** no Approval is written
- **AND** the refusal is logged with the missing key named

#### Scenario: An unknown human action is not guessed at

- **GIVEN** a record with `humanAction: "maybe"`
- **THEN** no Approval is written

### Requirement: An audit failure never fails the origin app's work

A storage failure while recording SHALL be logged and SHALL NOT raise. The human
has already acted by the time the record is made, and the origin app's operation
SHALL complete regardless.

#### Scenario: The register is unavailable

- **GIVEN** the object store raises while writing the record
- **THEN** the dispatching app sees `isHandled() === false`
- **AND** no exception propagates to it
- **AND** the failure is logged at error level

### Requirement: The oversight log is a surface of its own

Hermiq SHALL present advisory records on a surface separate from the approvals
inbox, filtered to `sourceType: "advisory"`. The inbox SHALL continue to show
only decisions that can still be acted on.

#### Scenario: The oversight surface shows only advisory records

- **GIVEN** an administrator opens `/ai-oversight`
- **THEN** the log lists only Approvals with `sourceType: "advisory"`
- **AND** counts of accepted, overridden and rejected decisions are shown
- **AND** no pending gating approval appears

#### Scenario: A recorded decision is immutable

- **GIVEN** an advisory Approval's detail page
- **THEN** it offers no edit, delete or lifecycle action

### Requirement: Hermiq does not resolve the origin app's objects

`advisoryContext.subjectId` SHALL be carried verbatim and SHALL NOT be declared
as a reference into another app's register. The oversight log SHALL remain
readable when the origin app is not installed.

#### Scenario: The log survives the origin app's removal

- **GIVEN** advisory records whose `originApp` is no longer installed
- **THEN** the oversight log still renders them with their subject identifiers
