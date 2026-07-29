# Talk Shared Sessions Specification

**Status**: in-progress
**Standards**: Nextcloud user identity; OpenRegister object RBAC (ADR-023 Rule 1)
**Feature tier**: MVP

**OpenSpec changes:**
- `openspec/changes/talk-chat-bridge-schema/` — `Conversation.participants` and `Message.authorId` / `authorDisplayName` (kind: config)
- `openspec/changes/talk-chat-bridge/` — participant-aware authorization, per-turn authorship, per-speaker file and credential scoping (kind: code, depends_on talk-chat-bridge-schema)

## Purpose

Let more than one person work with the same agent in the same session.

Until now a Hermiq conversation had exactly one owner, enforced both at the controller and inside
the engine, and a message recorded only its role — so a session could not be shared, and even if
it were, the transcript could not say who had spoken. That is the wrong shape for the rooms agent
reports are actually delivered into: team rooms.

This feature adds an explicit participant roster and per-turn authorship, and relaxes those owner
checks to *owner-or-listed-participant* — never to "any authenticated user", and never derived
implicitly from live Talk room membership.

Its most consequential rule is that each turn runs as the **speaker**, not the conversation owner.
Context files resolve from the speaker's own user folder and credentials are scoped to them, so one
participant can never make the agent read another's files. The visible side of that guarantee is
that the same agent in the same room can legitimately answer differently depending on who is
asking — correct security, surprising behaviour, and therefore something to document rather than
leave to be discovered.

## Requirements

### Requirement: A session may be shared with named participants

The system MUST allow a conversation to name participants beyond its owner, and MUST permit a turn
only from the owner or a named participant. The system MUST NOT widen access to any authenticated
user, and MUST NOT infer permission from live Talk room membership.

#### Scenario: A colleague works in a shared session

- GIVEN a conversation whose owner has named a colleague as a participant
- WHEN that colleague takes a turn
- THEN the system MUST accept it

#### Scenario: An outsider is refused

- GIVEN a conversation naming neither a user as owner nor as participant
- WHEN that user attempts a turn by any route
- THEN the system MUST refuse it and persist nothing

### Requirement: A shared transcript records who spoke

The system MUST record the author of every human turn, capturing their display name as it read at
the time, and MUST make that authorship available to the agent so it can distinguish speakers.

#### Scenario: Two people in one session

- GIVEN a conversation with turns from two different participants
- WHEN the agent responds
- THEN the system MUST have attributed each human turn to its author

#### Scenario: History survives a rename

- GIVEN a stored turn recording a participant's display name
- WHEN that participant's display name later changes
- THEN the transcript MUST still show the name as captured

### Requirement: Each turn acts as its speaker

The system MUST resolve context files and credentials as the user who took the turn, not as the
conversation owner, so that sharing a session never shares the participants' own data with each
other.

#### Scenario: A participant cannot reach the owner's files

- GIVEN a shared session whose agent references a file held only in the owner's folder
- WHEN a participant who is not the owner takes a turn
- THEN the system MUST NOT resolve that file into the turn

## User Stories

- As a team member, I want to work with my team's agent in our shared room, so that the answers and context stay with the team instead of living in one person's private session.
- As a participant, I want my own files and credentials used for my own questions, so that joining a shared session does not expose my data to my colleagues or theirs to me.
- As someone reading a transcript later, I want to see who asked what, so that a shared session is auditable rather than an anonymous stream.

## Acceptance Criteria

- [ ] A conversation can name participants beyond its owner, and only owner-or-participant may take a turn.
- [ ] The restriction holds at every entry point, including callers that do not pass through the controller.
- [ ] Every human turn records its author and the display name captured at send time; agent turns record none.
- [ ] The agent can distinguish two humans in one session.
- [ ] Files and credentials resolve as the speaker; a participant cannot reach another participant's files.

## Notes

- **ADR-023** — Rule 1 keeps data authorization in OpenRegister; the participant check is
  defense-in-depth at the action layer, not a re-implementation of object RBAC.
- **ADR-004** — the captured display name keeps a transcript readable as an audit record.
- Composes with `agent-capability-profile`: when an agent declares an `actingUser`, that identity
  governs the run; the speaker remains the turn's author either way.
- The roster is Hermiq's own and is deliberately explicit. Auto-populating it from Talk room
  membership is deferred — it would make "who can use this agent" change silently when someone is
  added to a room.
- Known and intended consequence: the same agent can answer differently depending on who asks,
  because attached context resolves against different user folders. This needs to be documented
  for users, not just specified.

## Related

- `talk-chat-bridge` — the surface that makes shared sessions reachable; same code change.
- `agent-engine-port` — owns the engine turn path and the owner check being relaxed.
