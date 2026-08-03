# talk-room-grouping (delta)

Grouping already files a bound room under each participant's Hermiq tag at bind time. Two moments
were not covered: a room Hermiq creates for a session, and a participant who joins after that.

## ADDED Requirements

### Requirement: A room Hermiq creates for a session is filed at creation

The system MUST file a room it creates for a session under the Hermiq conversation tag of each of
that room's participants at the moment the room is created, rather than waiting for a first
inbound message to bind it. Filing MUST reuse the existing per-user tag rules unchanged: the tag
is created on demand per user, assignment is additive and preserves the user's other tags, the
per-user opt-out is honoured, and any failure is logged and never fails the room creation or the
session.

#### Scenario: A newly created session room is grouped immediately

- **GIVEN** a user with tag grouping enabled
- **WHEN** that user creates a chat session whose room Hermiq creates
- **THEN** the room MUST appear under that user's Hermiq tag without any message being sent
@e2e Playwright: create a session with a Talk-enabled agent and assert the admin Talk-bridge surface reports the room as grouped for its owner.

#### Scenario: A grouping failure does not fail the session

- **GIVEN** a tag assignment that fails
- **WHEN** a session's room is created
- **THEN** the session and its room MUST still exist and function
@e2e exclude Requires an injected tag-API failure; asserted by unit test.

### Requirement: A participant who joins later is filed too

The system MUST file the room under the Hermiq tag of a participant added to an agent room after
the room was created, so that grouping does not depend on having been present at creation. The
per-user opt-out MUST be honoured for the joining user, and the existing tags of every other
participant MUST be left untouched.

#### Scenario: A late joiner sees the room grouped

- **GIVEN** an agent room already filed for its owner
- **WHEN** a second user is added to that room
- **THEN** the room MUST appear under the second user's Hermiq tag
- **AND** the owner's tag assignments MUST be unchanged
@e2e Playwright: add a second uid to a session's roster and assert the admin Talk-bridge surface reports the room grouped for both users.

#### Scenario: A late joiner who opted out is not filed

- **GIVEN** a user who has disabled Hermiq tag grouping
- **WHEN** that user is added to an agent room
- **THEN** no tag MUST be created and no assignment MUST be made for that user
@e2e exclude Per-user preference path already covered by the opt-out requirement; asserted by unit test on the late-joiner path.
