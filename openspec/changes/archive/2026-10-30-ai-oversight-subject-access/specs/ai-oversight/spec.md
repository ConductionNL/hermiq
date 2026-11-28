# ai-oversight

## ADDED Requirements

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
