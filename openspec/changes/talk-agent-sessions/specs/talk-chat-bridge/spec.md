# talk-chat-bridge (delta)

The addressing rule changes: in a room Hermiq created for a session, every human message is a turn.
Everywhere else the mention gate stays — that is the case it was written for.

## MODIFIED Requirements

### Requirement: The agent responds only when addressed in a group room

The system MUST take a turn on every inbound human message in a room Hermiq created for the
session, and in a one-to-one conversation with the bot. In any other group conversation the system
MUST take a turn only when the agent is `@`-mentioned by name or when the inbound message is a
reply to one of the agent's own messages, so that the agent does not answer unrelated conversation
in a shared room — which is precisely the case scheduled reports are delivered into.

Whether Hermiq created the room MUST be read from what the session recorded at creation time, and
MUST NOT be inferred from the room's current participants or type, so that inviting somebody into
an agent's own room cannot silently change whether the agent answers.

Because Talk does not offer bots as a source in its collaborator search, `@` does not autocomplete
a bot name and the mention arrives as literal typed text. The mention match MUST therefore be made
against the agent's own registered name on the decoded message text, and MUST tolerate multi-word
names, differences of case, and punctuation immediately following the name. A non-match MUST
result in no turn and MUST NOT raise an error.

#### Scenario: Every message is a turn in the session's own room

- **GIVEN** a room Hermiq created for a session
- **WHEN** a participant sends a message that neither mentions the agent nor replies to it
- **THEN** a turn MUST be taken and the answer posted to that room
@e2e exclude Requires driving Talk's own client to send a room message; covered by the live round-trip task in tasks.md.

#### Scenario: An unaddressed group message is ignored in a room Hermiq did not create

- **GIVEN** a group room the agent was invited into, with the bot enabled
- **WHEN** a participant sends a message that neither mentions the agent nor replies to it
- **THEN** no turn MUST be taken and no answer MUST be posted
@e2e exclude Requires driving Talk's own client in a team room; covered by the live round-trip task in tasks.md.

#### Scenario: A mention by agent name is answered

- **GIVEN** a group room the agent was invited into, with the bot enabled
- **WHEN** a participant types the agent's own name after an `@`
- **THEN** a turn MUST be taken and the answer posted to the room
@e2e exclude Requires driving Talk's own client; covered by the live round-trip task in tasks.md.

#### Scenario: A multi-word name with trailing punctuation still matches

- **GIVEN** an agent whose name is more than one word
- **WHEN** a participant types that name after an `@` in a different case and follows it with a comma
- **THEN** the message MUST be treated as addressing the agent
@e2e exclude Text-matching detail with several variants; asserted by unit tests on the matcher.

#### Scenario: Every message is a turn in a one-to-one room

- **GIVEN** a one-to-one room between a user and the bot
- **WHEN** the user sends a message without a mention
- **THEN** a turn MUST be taken
@e2e exclude Requires a one-to-one Talk room driven through Talk's client; covered by the live round-trip task in tasks.md.

#### Scenario: A reply to the agent is answered wherever the room came from

- **GIVEN** a group room the agent was invited into
- **WHEN** a participant replies to one of the agent's own messages without naming it
- **THEN** a turn MUST be taken
@e2e exclude Reply threading is a Talk client interaction; asserted by unit test on the addressing rule and confirmed in the live round-trip task.
