# Talk Room Grouping Specification

**Status**: done
**Standards**: Nextcloud Talk conversation tags (spreed ≥ 24)
**Feature tier**: nice-to-have

**OpenSpec changes:**
- `openspec/changes/archive/2026-07-28-talk-room-grouping/` — per-user Hermiq tag creation and additive room assignment at bind time (kind: code, depends_on talk-chat-bridge) — **done**, live-verified with two real users

## Purpose

Keep agent rooms from crowding out human conversations in Talk's sidebar.

Once agents are reachable in Talk, an agent room looks exactly like a person's room, and a team
running a nightly triage agent, a release agent and a docs agent gains three more entries competing
with the conversations they actually talk in. Talk 24 solved this generically with conversation
tags — a personal, ordered, collapsible grouping of the conversation list — and Hermiq knows
precisely which rooms are agent rooms, because it bound them.

So Hermiq files them: a "Hermiq" tag, created on demand for each participant, assigned to each
agent room as it is bound. The user does nothing.

This is presentation only. Nothing reads the tag to make a decision — authorization is the
participant roster and routing is the room binding — and that boundary is deliberate.
## Requirements

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

## User Stories

- As someone who works with several agents, I want their rooms filed together in my sidebar, so that my conversation list stays about the people I talk to.
- As a participant in a team's agent room, I want to be able to turn that filing off, so that Hermiq is not rearranging my conversation list against my wishes.

## Acceptance Criteria

- [x] A Hermiq tag is created once per user, on demand, and a concurrent bind neither duplicates it nor fails.
- [x] Each participant of a bound agent room sees it under their own Hermiq tag.
- [x] Pre-existing user tags on that room survive; no tag is renamed, reordered, collapsed or deleted.
- [x] Each user can disable grouping; disabling leaves existing assignments intact.
- [x] With Talk absent or too old, the bind succeeds and grouping is skipped silently.
- [x] Nothing in Hermiq reads the tag to make a decision.

## Notes

- Talk tags are stored per user (`oc_talk_conversation_tags`, keyed by `user_id`) with assignment on
  the attendee row — so there is no shared tag, and grouping a group room means writing into every
  participant's own conversation list. That is a small uninvited UI change made on their behalf,
  which is exactly why the per-user opt-out exists.
- The assignment API replaces the full tag list for an attendee-room pair, so a blind write would
  destroy the user's own tags. Read-modify-write is mandatory.
- **ADR-031** — tag creation and assignment are an external-integration exception (calls into
  spreed's services, writes spreed's tables); the opt-out preference is declarative data.
- Deferred: a tag per agent, instance-wide grouping, retro-filing of rooms bound before this
  feature, and a user-configurable tag name.
- Boundary to defend: if anything ever reads this tag to decide something, authorization becomes
  editable from Talk's UI and the presentation-only guarantee is gone.

## Related

- `talk-chat-bridge` — provides the bind moment this feature hooks.
