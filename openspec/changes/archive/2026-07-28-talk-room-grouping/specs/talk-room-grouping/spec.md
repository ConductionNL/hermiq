# talk-room-grouping (delta)

Agent rooms filed under one automatically-created, per-user Talk tag so they stop competing with
human conversations in the sidebar. Presentation only.

## ADDED Requirements

### Requirement: A Hermiq conversation tag is created per user on demand

The system MUST ensure that a user has a Hermiq conversation tag before assigning any room to it,
creating it the first time that user is bound into an agent room. Because Talk tags are keyed per
user, the system MUST create the tag for each affected user separately and MUST NOT attempt to
create a shared or instance-wide tag. Creation MUST tolerate a concurrent create — a uniqueness
conflict MUST be treated as success and the existing tag re-read — and MUST NOT create a second
tag for a user who already has one.

#### Scenario: First bind creates the tag

- **GIVEN** a user with no Hermiq conversation tag
- **WHEN** that user is bound into an agent room
- **THEN** a Hermiq conversation tag MUST exist for that user afterwards
@e2e Live: bind a user with no Hermiq tag into an agent room and assert the tag appears in their conversation list.

#### Scenario: A second bind reuses the tag

- **GIVEN** a user who already has a Hermiq conversation tag
- **WHEN** that user is bound into a second agent room
- **THEN** no additional tag MUST be created
@e2e Live: bind a second agent room for the same user and assert exactly one Hermiq tag exists.

#### Scenario: A concurrent create does not fail the bind

- **WHEN** two binds for the same user race to create the tag
- **THEN** exactly one tag MUST exist afterwards
- **AND** neither bind MUST fail
@e2e exclude Requires a deliberate race that cannot be driven through the UI; asserted by a unit test on the resolver's conflict handling.

### Requirement: A bound agent room is filed under each participant's Hermiq tag

The system MUST assign a bound agent room to the Hermiq tag of every participant of that room, so
that each participant sees the room grouped in their own conversation list. Assignment MUST be
additive: the system MUST preserve any other tags the user has already assigned to that room, and
MUST NOT reorder, rename, collapse or remove any of the user's tags.

#### Scenario: Every participant sees the room grouped

- **GIVEN** an agent room with two participants
- **WHEN** the room is bound to a conversation
- **THEN** the room MUST appear under the Hermiq tag for both participants
@e2e Live: bind a two-participant agent room and assert both users see it grouped.

#### Scenario: Existing tags on the room are preserved

- **GIVEN** a user who has already assigned their own tag to a room
- **WHEN** Hermiq assigns its tag to that same room
- **THEN** the user's existing tag MUST still be assigned
@e2e Live: pre-assign a user tag to a room, trigger the bind, and assert both tags remain.

### Requirement: Grouping is per-user optional

The system MUST offer each user a preference to disable Hermiq's tag grouping. When disabled, the
system MUST NOT create a tag for that user and MUST NOT assign any further rooms to it. Disabling
MUST NOT remove assignments already made, because those are the user's own tags to manage through
Talk.

#### Scenario: A user opts out and is not filed

- **GIVEN** a user who has disabled Hermiq tag grouping
- **WHEN** that user is bound into an agent room
- **THEN** no tag MUST be created and no assignment MUST be made for that user
@e2e Live: disable the preference, bind the user into an agent room, and assert no Hermiq tag assignment appears.

#### Scenario: Opting out leaves existing assignments alone

- **GIVEN** a user with rooms already filed under their Hermiq tag
- **WHEN** that user disables the preference
- **THEN** the existing assignments MUST remain
@e2e exclude Absence-of-mutation over prior state; asserted by unit test on the preference path.

### Requirement: Grouping never breaks anything it touches

The system MUST treat tag creation and assignment as best-effort. A failure — Talk absent, a Talk
older than the one that introduced conversation tags, or a rejected tag call — MUST NOT fail a
bind, a turn, or a delivery, and MUST NOT surface as a user-facing error. The failure MUST be
logged.

#### Scenario: Talk absent

- **GIVEN** an instance without spreed
- **WHEN** a conversation is created that would otherwise be bound and grouped
- **THEN** no grouping MUST be attempted and nothing else MUST change
@e2e exclude Requires a Talk-less instance the shared e2e environment does not provide; asserted by unit test and a documented manual check.

#### Scenario: Talk too old for conversation tags

- **GIVEN** an instance whose Talk predates conversation-tag support
- **WHEN** an agent room is bound
- **THEN** the bind MUST succeed
- **AND** grouping MUST be skipped without error
@e2e exclude Requires an older Talk than the environment provides; asserted by unit test on the capability probe.

#### Scenario: A tag failure does not fail the bind

- **GIVEN** a tag assignment that fails
- **WHEN** an agent room is bound
- **THEN** the bind MUST still succeed and the room MUST still function for chat
@e2e exclude Requires an injected tag-API failure; asserted by unit test.
