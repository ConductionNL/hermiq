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

### Requirement: A session may be taken up by its owner or a listed participant

The system MUST permit a turn in a conversation when the acting user is either the `userId`
owner or a uid listed in the conversation's `participants` roster, and MUST refuse it
otherwise. The check MUST be enforced at BOTH the engine layer and the controller layer, so
that the Talk bridge — which reaches the engine without passing through the controller — is
covered by the same rule. The system MUST NOT relax the check to "any authenticated user", and
MUST NOT derive permission implicitly from live Talk room membership at read time.

#### Scenario: A listed participant may take a turn

- **GIVEN** a conversation owned by one user with a second user listed in `participants`
- **WHEN** the second user sends a message to that conversation
- **THEN** the turn MUST be accepted and processed
@e2e Live: with two users, add the second to a conversation's roster and assert their message is answered.

#### Scenario: A non-participant is refused at the controller

- **GIVEN** a conversation whose owner and roster exclude a third user
- **WHEN** that user calls the chat API against the conversation
- **THEN** the request MUST be refused
- **AND** no message MUST be persisted to the conversation
@e2e Live: authenticate as an unrelated user and assert the chat endpoint refuses the conversation.

#### Scenario: A non-participant is refused at the engine

- **GIVEN** a conversation whose owner and roster exclude a user
- **WHEN** a turn for that user reaches the engine directly, bypassing the controller
- **THEN** the engine MUST refuse the turn
@e2e exclude The bypass path is reachable only from server-side callers (the bridge job); asserted by a direct unit test on the engine guard.

#### Scenario: An empty roster means owner-only

- **GIVEN** a conversation with no `participants` entries
- **WHEN** any user other than the owner attempts a turn
- **THEN** the turn MUST be refused
@e2e exclude Negative authorization case on the default shape; asserted by unit tests at both layers.

### Requirement: Each human turn records its author

The system MUST persist `authorId` and `authorDisplayName` on every message it writes with
`role = user`, capturing the speaker's uid and their display name as it read at the time the
message was sent. The system MUST NOT set author fields on `system`, `assistant` or `tool`
messages. The system MUST NOT re-resolve a stored `authorDisplayName` on read, so that a
transcript continues to show the name as it was when the message was sent.

#### Scenario: A turn from a participant is attributed

- **WHEN** a listed participant sends a message in a shared conversation
- **THEN** the persisted message MUST carry that user's uid in `authorId`
- **AND** MUST carry their display name at send time in `authorDisplayName`
@e2e Live: send a message as a participant and assert the stored turn carries their uid and display name.

#### Scenario: The agent's own turn is unattributed

- **WHEN** the agent produces an answer
- **THEN** the persisted `assistant` message MUST carry no author fields
@e2e exclude Shape assertion on a persisted object; covered by unit test alongside the attributed-turn case.

#### Scenario: A renamed user's history is unchanged

- **GIVEN** a stored message carrying a captured `authorDisplayName`
- **WHEN** that user's display name changes
- **THEN** the stored message MUST still show the originally captured name
@e2e exclude Requires a display-name change outside the app under test; asserted by unit test on the read path.

### Requirement: The model can tell speakers apart

When a conversation has more than one human participant, the system MUST label human turns with
their author in the history it supplies to the model, so that the agent can distinguish speakers
rather than receiving one undifferentiated user voice.

#### Scenario: A multi-speaker history is labelled

- **GIVEN** a conversation containing user turns from two different participants
- **WHEN** history is assembled for the model
- **THEN** each human turn MUST be attributed to its author in the assembled history
@e2e exclude Prompt-assembly detail not observable through the UI; asserted by a unit test on the history handler.

### Requirement: Files and credentials resolve as the speaker, not the owner

The system MUST process each turn with the speaking user as the acting identity, so that
attached context files are resolved from the speaker's own user folder and credentials are
scoped to the speaker. A participant MUST NOT be able to cause the agent to read another
participant's files by taking a turn in a shared conversation.

#### Scenario: A participant's turn reads their own files

- **GIVEN** a shared conversation whose agent has an attached context file reference
- **WHEN** a participant who is not the owner takes a turn
- **THEN** context files MUST be resolved from that participant's user folder
@e2e exclude Server-side resolution path not observable through the UI; asserted by unit tests on the context assembler with distinct acting users.

#### Scenario: A participant cannot reach the owner's files

- **GIVEN** a context file that exists only in the owner's user folder
- **WHEN** a non-owner participant takes a turn
- **THEN** that file MUST NOT be resolved into the turn's context
@e2e exclude Negative security case on a server-side path; asserted by unit test. Flagged for security review.

#### Scenario: Credentials are scoped to the speaker

- **WHEN** a participant takes a turn requiring a scoped credential
- **THEN** the credential MUST be resolved for that participant, not for the conversation owner
@e2e exclude Credential resolution is server-side and secret-bearing; asserted by unit test on the scope resolver.

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
