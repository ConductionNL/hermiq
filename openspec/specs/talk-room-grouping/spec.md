# Talk Room Grouping Specification

**Status**: in-progress
**Standards**: Nextcloud Talk conversation tags (spreed ≥ 24)
**Feature tier**: nice-to-have

**OpenSpec changes:**
- `openspec/changes/talk-room-grouping/` — per-user Hermiq tag creation and additive room assignment at bind time (kind: code, depends_on talk-chat-bridge)

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

### Requirement: Agent rooms are grouped automatically for each participant

The system MUST group a user's agent rooms under a single Hermiq conversation tag in that user's
own conversation list, creating the tag on demand, without requiring any action from the user.

#### Scenario: Agent rooms appear grouped

- GIVEN a user who is a participant in two agent rooms
- WHEN those rooms are bound
- THEN the system MUST show both under one Hermiq tag in that user's conversation list

### Requirement: Grouping never disturbs the user's own tags

The system MUST add its tag alongside whatever the user has already assigned, and MUST NOT remove,
rename, reorder or collapse any tag of theirs.

#### Scenario: A user's own tag survives

- GIVEN a user who has assigned their own tag to an agent room
- WHEN Hermiq groups that room
- THEN the user's tag MUST still be assigned

### Requirement: Grouping is optional per user and never load-bearing

The system MUST let each user turn grouping off, MUST leave already-assigned tags in place when
they do, and MUST NOT allow any grouping failure to affect chat, delivery or a bind.

#### Scenario: A user opts out

- GIVEN a user who has disabled grouping
- WHEN they are bound into an agent room
- THEN the system MUST NOT create or assign a tag for them

#### Scenario: Talk cannot group

- GIVEN an instance without Talk, or with a Talk predating conversation tags
- WHEN an agent room is bound
- THEN the bind MUST succeed and grouping MUST be skipped silently

## User Stories

- As someone who works with several agents, I want their rooms filed together in my sidebar, so that my conversation list stays about the people I talk to.
- As a participant in a team's agent room, I want to be able to turn that filing off, so that Hermiq is not rearranging my conversation list against my wishes.

## Acceptance Criteria

- [ ] A Hermiq tag is created once per user, on demand, and a concurrent bind neither duplicates it nor fails.
- [ ] Each participant of a bound agent room sees it under their own Hermiq tag.
- [ ] Pre-existing user tags on that room survive; no tag is renamed, reordered, collapsed or deleted.
- [ ] Each user can disable grouping; disabling leaves existing assignments intact.
- [ ] With Talk absent or too old, the bind succeeds and grouping is skipped silently.
- [ ] Nothing in Hermiq reads the tag to make a decision.

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
