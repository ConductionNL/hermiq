# talk-agent-sessions (delta)

A Hermiq session becomes a Talk conversation the agent owns: the agent speaks under its own name
from its own bot, the session creates and names its room, room participants become session
participants, and the room is filed under each participant's Hermiq tag.

## ADDED Requirements

### Requirement: Each Talk-enabled agent has its own Talk bot identity

The system MUST register one Talk bot per Talk-enabled agent, whose URL is
`nextcloudapp://hermiq-<agentId>` and whose registered name is the agent's own name, so that a
message the agent posts is signed with that agent's name rather than a single shared bot name.
The system MUST NOT rely on a per-message display name, because the message-send API accepts none
and Talk resolves a bot's display name at render time from the bot record. The system MUST derive
the bot's actor id from that per-agent URL, so that the actor id Talk stores and the actor id
Hermiq posts under are the same value.

#### Scenario: A Talk-enabled agent speaks under its own name

- **GIVEN** two Talk-enabled agents with different names
- **WHEN** each posts a message into a room
- **THEN** each message MUST be attributed to its own agent's name
- **AND** the two MUST NOT be attributed to the same shared bot name
@e2e exclude Message attribution is rendered by Talk's own client, not by any Hermiq surface Playwright drives; covered by the live round-trip task.

#### Scenario: The bot URL identifies the agent

- **WHEN** a bot is registered for an agent
- **THEN** its URL MUST be `nextcloudapp://hermiq-<agentId>` for that agent's id
- **AND** its actor id MUST be derived from that same URL
@e2e exclude Structural property of the registered bot record, asserted by unit test and read back from Talk in the live-verification task.

### Requirement: An agent's bot follows the agent's lifecycle

The system MUST install an agent's bot when the agent becomes Talk-enabled, MUST update the
registered bot name when the agent is renamed, and MUST uninstall the bot when the agent is
Talk-disabled or deleted. Install and rename MUST go through Talk's own bot lifecycle rather than
writing Talk's tables directly, so that an operator can still remove the integration from Talk
alone. The system MUST use a stable per-agent secret, because Talk's install path keys its upsert
on the URL and secret together and a changed secret on an existing URL is rejected.

#### Scenario: Enabling an agent installs its bot

- **GIVEN** an agent that is not Talk-enabled
- **WHEN** it is Talk-enabled
- **THEN** a bot MUST be registered for that agent
@e2e Playwright: enable Talk on a seeded agent through the agent UI and assert the admin Talk-bridge surface reports a bot for it.

#### Scenario: Renaming an agent renames its bot

- **GIVEN** a Talk-enabled agent with a registered bot
- **WHEN** the agent is renamed
- **THEN** the registered bot's name MUST become the agent's new name
- **AND** no second bot MUST be registered for that agent
@e2e Playwright: rename a Talk-enabled agent and assert the admin Talk-bridge surface reports exactly one bot, under the new name.

#### Scenario: Disabling an agent uninstalls its bot

- **GIVEN** a Talk-enabled agent with a registered bot
- **WHEN** the agent is Talk-disabled or deleted
- **THEN** its bot MUST be uninstalled
- **AND** subsequent messages MUST NOT produce a turn for that agent
@e2e Playwright: disable Talk on the agent and assert the admin Talk-bridge surface no longer reports a bot for it.

### Requirement: Hermiq recognises any of its own bot URLs and resolves the agent from it

Both inbound listeners MUST accept an invocation from any Hermiq bot URL rather than testing
equality against one fixed URL, and MUST resolve the acting agent from that URL. The system MUST
reject a URL that does not have Hermiq's own per-agent form, so that an invocation belonging to
another app's bot is ignored and an approval can never be decided by an invocation from a bot that
is not the agent's. Loosening the URL test MUST NOT loosen any downstream check: the approval path
MUST still resolve the approval by its recorded message id and MUST still verify the reacting user
is the approval's resolved reviewer.

#### Scenario: A message invocation resolves its agent

- **GIVEN** a room where a specific agent's bot is enabled
- **WHEN** that bot is invoked with an inbound message
- **THEN** the system MUST resolve the invocation to that agent
@e2e exclude Listener-internal resolution not observable through the UI; asserted by unit test on the URL parser and proved by the live round-trip task.

#### Scenario: Another app's bot invocation is ignored

- **WHEN** a bot invocation arrives whose URL is not one of Hermiq's per-agent bot URLs
- **THEN** neither listener MUST act on it
- **AND** no turn and no approval decision MUST result
@e2e exclude Requires dispatching a foreign bot invocation, which no UI can produce; asserted by unit tests on both listeners' guards.

#### Scenario: A reaction still requires the resolved reviewer

- **GIVEN** a pending approval bound to a message posted by one agent's bot
- **WHEN** a user who is not the approval's resolved reviewer reacts to it
- **THEN** the approval MUST remain pending
- **AND** no decision MUST be recorded
@e2e exclude Reactions are sent through Talk's client, outside the Hermiq surface Playwright drives; this is the mandatory live regression check in tasks.md.

### Requirement: Creating a chat session creates and owns its Talk room

The system MUST create a Talk room when a Hermiq chat session is created for a Talk-enabled agent,
MUST name that room after the session, MUST add the session owner as a participant, MUST enable
that agent's bot in it, and MUST store the room's token on the session. The system MUST record
that Hermiq created the room, as stored data rather than as a property inferred from the room's
current shape, so that the rule governing when the agent answers cannot change silently when
somebody is invited. Room creation MUST be best-effort: a failure MUST NOT prevent the session
from being created or used from Hermiq's own UI.

#### Scenario: A new session gets a room

- **GIVEN** a Talk-enabled agent
- **WHEN** a user creates a chat session with it
- **THEN** a Talk room MUST exist for that session, named after it
- **AND** the session MUST carry that room's token
- **AND** the session MUST record that Hermiq created the room
@e2e Playwright: create a chat session with a Talk-enabled agent and assert the session carries a room token and a created-room origin.

#### Scenario: The owner and the agent's bot are in the room

- **WHEN** a session's room is created
- **THEN** the session owner MUST be a participant of it
- **AND** that agent's bot MUST be enabled in it
@e2e Playwright: create a session and assert the admin Talk-bridge surface reports the room with the agent's bot enabled.

#### Scenario: A room-creation failure does not fail the session

- **GIVEN** an instance where the room cannot be created
- **WHEN** a user creates a chat session
- **THEN** the session MUST still be created and usable from Hermiq's own UI
- **AND** the session MUST NOT be recorded as owning a room
@e2e exclude Requires an injected Talk failure; asserted by unit test on the room service.

#### Scenario: A session for a Talk-disabled agent gets no room

- **GIVEN** an agent that is not Talk-enabled
- **WHEN** a user creates a chat session with it
- **THEN** no Talk room MUST be created
@e2e Playwright: create a session with a Talk-disabled agent and assert it carries no room token.

### Requirement: Renaming a session renames its room

The system MUST rename the Talk room when the session that owns it is renamed, so that the room's
name keeps matching the session it belongs to. The system MUST NOT rename a room the session does
not own. A rename failure MUST NOT fail the session rename.

#### Scenario: A renamed session renames its room

- **GIVEN** a session that owns a Talk room
- **WHEN** the session's title is changed
- **THEN** the room's name MUST become the new title
@e2e Playwright: rename a session that owns a room and assert the bound room's name follows.

#### Scenario: A bound room is not renamed

- **GIVEN** a session bound to a room Hermiq did not create
- **WHEN** the session's title is changed
- **THEN** the room's name MUST NOT change
@e2e exclude Absence-of-mutation on a spreed-owned room; asserted by unit test on the rename path.

### Requirement: Room participants become session participants

The system MUST copy the Talk room's participants into the session's participant roster when the
room is created and whenever a participant is added afterwards, so that a person invited to the
room can take a turn in the session. Authorization MUST continue to read the stored roster and
MUST NOT be derived from live Talk room membership at read time. Bots and the session owner MUST
NOT be added to the roster, because the owner is implicitly a participant and a bot is not a user.

#### Scenario: An invited user can take a turn

- **GIVEN** a session that owns a room
- **WHEN** a second user is added to that room
- **THEN** that user MUST appear in the session's participant roster
- **AND** MUST be permitted to take a turn in that session
@e2e Playwright: seed a session with a second uid in its roster and assert that user's turn is accepted while an unlisted user's is refused.

#### Scenario: The roster excludes bots and the owner

- **WHEN** a session's roster is synced from its room
- **THEN** no bot actor MUST appear in the roster
- **AND** the owner MUST NOT be duplicated into it
@e2e exclude Shape assertion on a persisted roster; asserted by unit test on the sync.

#### Scenario: Authorization reads the stored roster

- **GIVEN** a user who is in the Talk room but not in the session's stored roster
- **WHEN** that user's turn reaches the engine
- **THEN** the turn MUST be refused
@e2e exclude Server-side guard reached without passing through the UI; asserted by unit test at the engine layer.
