# ai-oversight Specification

## Purpose
Who may read the advisory AI oversight records and how a suggestion nobody acted on is kept. Created by the change `ai-oversight-subject-access` (archived 2026-10-30): an advisory record is readable only through its subject, by people in the `ai-oversight` group or administrators, and a shown suggestion nobody acted on is recorded as unused. The change `ai-oversight-advisory-approvals` (archived 2026-11-20) added how a consumer app records an advisory decision, what is refused, that an audit failure never fails the origin app, the oversight log as a surface of its own, and that hermiq does not resolve the origin app's objects.

## Requirements

### Requirement: An advisory record is readable only through its subject

An advisory Approval SHALL be readable by a user who may open the record it is about (`advisoryContext.originApp` +
`advisoryContext.subjectId`), by members of the `ai-oversight` group and by administrators, and by nobody else. The
access decision SHALL be taken on the server before the records are read.

#### Scenario: A handler who may open the case reads its records

- **GIVEN** a user OpenRegister lets read case `C`
- **WHEN** the user asks `GET /api/ai-oversight/subject?originApp=dossiq&subjectId=C`
- **THEN** hermiq answers the advisory records about `C`, newest first

#### Scenario: A user who may not open the case reads nothing

- **GIVEN** a user OpenRegister refuses case `C`, without the oversight role
- **WHEN** the user asks for the records about `C`
- **THEN** hermiq answers 403 and reads no record

#### Scenario: The object list does not leak advisory rows

- **GIVEN** a signed-in user without the oversight role
- **WHEN** the user lists hermiq's `approval` schema through OpenRegister
- **THEN** no row with `sourceType: advisory` is returned

#### Scenario: The oversight role reads every record

- **GIVEN** a member of `ai-oversight`, or an administrator
- **THEN** every advisory record is readable, through the subject route and through OpenRegister

#### Scenario: Records under an app's former id

- **GIVEN** records about case `C` stored with `originApp: procest`, dossiq's former id
- **WHEN** a reader allowed on `C` asks for the records with `originApp=dossiq`
- **THEN** those records are included, newest first with the ones stored under `dossiq`
- **AND** no stored record is rewritten

#### Scenario: The oversight group exists after install or upgrade

- **GIVEN** no `ai-oversight` group
- **WHEN** hermiq is installed or upgraded
- **THEN** the group exists, without members
- **AND** an existing group is left untouched

### Requirement: A shown suggestion nobody acted on is recorded as unused

Hermiq SHALL accept `humanAction: ignored` and store it as `status: unused`, terminal at creation, with
`advisoryContext.unusedBecause` one of `closed`, `expired` or `superseded`. The statuses `approved`, `denied` and
`overridden` SHALL keep their meaning.

#### Scenario: A superseded suggestion

- **GIVEN** a record with `humanAction: ignored` and `unusedBecause: superseded`
- **THEN** hermiq writes an advisory Approval with `status: unused` and that cause

#### Scenario: An unknown cause is refused

- **GIVEN** a record with `unusedBecause: bored`
- **THEN** hermiq writes nothing and logs why

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

### Requirement: An oversight holder downloads a subject's records

The system SHALL offer a subject's advisory Approvals as a CSV download only to an administrator or a member of the `ai-oversight` group, and SHALL refuse anyone else before reading a record. A reader who may open the subject but holds no oversight role SHALL NOT download it.

#### Scenario: an oversight holder downloads
- **GIVEN** Olga is in the ai-oversight group and may open case-1
- **WHEN** she requests the export for dossiq case-1
- **THEN** she SHALL receive a CSV with a header row and one row per record, newest first

#### Scenario: a handler without the role
- **GIVEN** Alice may open case-1 but is not in the ai-oversight group
- **WHEN** she requests the export
- **THEN** the system SHALL answer 403 and read no record

#### Scenario: a formula in a prompt
- **GIVEN** a record whose prompt starts with "="
- **WHEN** it is exported
- **THEN** the cell SHALL start with a quote
