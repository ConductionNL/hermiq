## ADDED Requirements

### Requirement: A repair step copies conversations onto the session schema

The system MUST provide a repair step that copies every object in the `hermiq` register's
`conversation` schema onto the `agentsession` schema, and every `message` object onto
`agentsessionturn`, preserving each object's `_uuid`. The source objects MUST NOT be deleted or
modified: the step copies, so the rollback is a delete of the copies and nothing else.

A copied session that carries no trigger origin MUST be recorded as `human`. Every object that
predates the property was started by a person, and a session in neither group is a session that
has disappeared from the list.

#### Scenario: A conversation is copied with its uuid preserved

- **WHEN** the step runs against a `conversation` object with uuid `U` and no `agentsession`
  object with uuid `U` exists
- **THEN** an `agentsession` object with uuid `U` MUST be created, and the source `conversation`
  object MUST still exist unchanged

#### Scenario: Messages stay attached to their session

- **WHEN** a `message` object references its conversation by uuid `U`
- **THEN** the copied turn MUST reference the same uuid `U`, so the count of turns per session
  equals the count of messages per conversation

### Requirement: The step carries ownership, tenancy and timestamps across

The step MUST copy `_owner`, `_organisation`, `_created` and `_updated` from each source object
onto its copy. These are entity-level columns rather than object properties, so a write through
the object API alone leaves the copy owned by whoever ran the upgrade and dated to the moment it
ran, which silently re-tenants every object it touches.

#### Scenario: A copied session belongs to the same user and organisation

- **WHEN** a conversation owned by user `alice` in organisation `O` is copied
- **THEN** the resulting session MUST report `_owner` `alice` and `_organisation` `O`, and its
  `_created` MUST equal the source's

### Requirement: Archived conversations are copied, and stay archived

The step MUST include soft-deleted (archived) conversations, and the copy MUST carry the same
archive marker. OpenRegister's default reads exclude soft-deleted rows, so a step written against
the default read copies only the live objects and empties the Archive tab without failing.

#### Scenario: An archived conversation produces an archived session

- **WHEN** the step runs against a conversation whose archive marker is set
- **THEN** the copied session MUST carry the same marker, and MUST NOT appear in the active list

### Requirement: Re-running the step changes nothing already copied

The step MUST be idempotent. An object that already exists at the target uuid MUST be left
untouched, including one that is archived: a re-run that rewrote it would undo any edit made
through the interface after the first run.

#### Scenario: A second run copies nothing

- **WHEN** the step runs a second time against the same data
- **THEN** no target object MUST be created or modified, and the step MUST report how many it
  found already present

#### Scenario: An archived target is recognised as present

- **WHEN** the step re-runs and the target session exists but is archived
- **THEN** it MUST be counted as already present rather than copied again, because a read that
  excludes soft-deleted rows reports an archived target as absent

### Requirement: The step establishes OpenRegister's availability before reaching for it

The step MUST check that `openregister` is installed before resolving any OpenRegister class, and
MUST record the skip rather than throwing when it is not. Every class it uses is resolved from the
container at the point of use, so without the check the optionality of the whole step is stated
only by the shape of each individual catch.

#### Scenario: OpenRegister is not installed

- **WHEN** the step runs on an instance without `openregister`
- **THEN** it MUST report that it skipped, record the outcome, and MUST NOT fail the upgrade

### Requirement: Verification compares field by field, not by count

The change MUST ship a verification pass that compares each source object with its copy property
by property. A count comparison passes while every copied object is empty, so a count is not
evidence that the copy carries the data.

#### Scenario: A corrupted copy is reported

- **WHEN** one migrated object is deliberately altered and the verification runs
- **THEN** it MUST name that object, so that a run reporting no findings is evidence rather than
  an absence of checking
