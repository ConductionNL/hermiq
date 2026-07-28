# talk-approval-reactions (delta)

Resolve a pending approval from Nextcloud Talk by reacting to the agent's message — 👍 approves,
👎 denies — so a gated run can be released from a phone.

## ADDED Requirements

### Requirement: An approval request posted to Talk records where it landed

When the system posts an approval request into a Talk room, it MUST record that room's token and
the resulting message id on the `Approval` object, so a later reaction on that message can be
resolved back to the approval it decides. Recording MUST be best-effort: a failure to record MUST
NOT prevent the approval request from being raised, because the inbox remains the authoritative
surface.

#### Scenario: A posted request is bound to its message

- **GIVEN** a pending approval whose reviewer receives requests in a Talk room
- **WHEN** the request is posted into that room
- **THEN** the approval MUST record that room's token and the posted message's id
@e2e Live: raise a gated run, then assert the approval carries the room token and message id of the posted request.

#### Scenario: A recording failure still raises the approval

- **GIVEN** a posted request whose binding cannot be persisted
- **WHEN** the approval is raised
- **THEN** the approval MUST still exist and be decidable from the inbox
@e2e exclude Requires an injected persistence failure; asserted by unit test.

### Requirement: A reviewer's reaction decides the approval

The system MUST treat a 👍 reaction on a bound approval message as an approval and a 👎 reaction as
a denial, and MUST ignore every other emoji rather than guessing at intent. The decision MUST be
applied through the same approval path the inbox uses, so that a Talk-originated decision is
indistinguishable downstream — same audit trail, same decided-by and decided-at — and MUST record
that it arrived by reaction.

#### Scenario: A thumbs-up approves

- **GIVEN** a pending approval bound to a Talk message
- **WHEN** its reviewer reacts 👍 to that message
- **THEN** the approval MUST become approved
- **AND** it MUST record the reviewer as the deciding user and `reaction` as the surface
@e2e Live: react 👍 as the reviewer and assert the approval flips to approved with reaction provenance.

#### Scenario: A thumbs-down denies

- **GIVEN** a pending approval bound to a Talk message
- **WHEN** its reviewer reacts 👎 to that message
- **THEN** the approval MUST become denied
@e2e Live: react 👎 as the reviewer and assert the approval flips to denied.

#### Scenario: Any other emoji is ignored

- **WHEN** the reviewer reacts with an emoji that is neither 👍 nor 👎
- **THEN** the approval MUST remain pending
@e2e exclude Emoji-dispatch detail; asserted by unit test on the reaction listener.

### Requirement: Only the reviewer may decide by reaction

The system MUST verify that the reacting user is the approval's resolved reviewer — the named user,
or a member of the named reviewer group — before applying any decision, and MUST ignore a reaction
from anyone else. A reaction is a public one-click act available to every participant in a room, so
without this check the approval gate would be bypassable by any bystander.

#### Scenario: A bystander's reaction does nothing

- **GIVEN** a pending approval whose reviewer is another user
- **WHEN** a different room participant reacts 👍
- **THEN** the approval MUST remain pending
- **AND** no decision MUST be recorded
@e2e Live: react as a non-reviewer and assert the approval is untouched.

#### Scenario: A reviewer-group member may decide

- **GIVEN** a pending approval whose reviewer is a group
- **WHEN** a member of that group reacts 👍
- **THEN** the approval MUST become approved
@e2e exclude Requires a seeded reviewer group; asserted by unit test on the authorization path.

### Requirement: A decision is confirmed in the room and is not reversible by un-reacting

The system MUST confirm the outcome in the originating room, so the reviewer knows their reaction
landed. Removing a reaction MUST NOT reverse a decision — an approval is a governance record, not a
toggle — and the system MUST say so in the room rather than ignore the removal silently, because
silence reads as success.

#### Scenario: The outcome is confirmed

- **WHEN** a reaction decides an approval
- **THEN** the system MUST post the outcome into the originating room
@e2e Live: react as the reviewer and assert a confirmation message appears in the room.

#### Scenario: Removing a reaction does not undo

- **GIVEN** an approval already decided by reaction
- **WHEN** the reviewer removes that reaction
- **THEN** the approval MUST retain its decision
- **AND** the system MUST state in the room that the decision cannot be undone
@e2e exclude Reaction removal is dispatched by spreed as an Undo invocation; asserted by unit test.

#### Scenario: An already-decided approval is a visible no-op

- **GIVEN** an approval that is no longer pending
- **WHEN** a reviewer reacts to its message
- **THEN** the decision MUST NOT change
- **AND** the system MUST say so rather than appearing to accept the reaction
@e2e exclude Requires a pre-decided approval; asserted by unit test.

### Requirement: The reaction path is inert without Talk

The system MUST register its reaction listener unconditionally and probe Talk availability at
invoke time, exactly as the chat bridge does, so that Hermiq boots and approvals continue to work
through the inbox on an instance with no Talk.

#### Scenario: Talk absent changes nothing about approvals

- **GIVEN** an instance without Talk
- **WHEN** an approval is raised and decided in the inbox
- **THEN** it MUST behave exactly as it did before this change
@e2e exclude Requires a Talk-less instance; asserted by unit test plus a documented manual check.
