# instant-talk (delta)

This change makes Talk answers effectively instant (event-driven execution,
immediate acknowledgement, edit-streamed progressive replies) and makes rooms
multi-party (several agents and humans, per-agent addressing, bounded agent-to-agent
turns, moderator-managed per-room agent membership). It builds on the
`talk-chat-bridge` and in-progress `talk-shared-sessions` specs without weakening
their identity rules.

## ADDED Requirements

### Requirement: Turns execute immediately with claim-based exactly-once semantics
The system MUST derive a deterministic turn id from the room token, the Talk message
id, and the addressed agent, and MUST execute each turn at most once: every executor
(immediate path or queued job) MUST atomically claim the turn id before running it
and MUST exit without side effects when the claim is already held. The durable
queued job MUST remain enqueued for every turn as the fallback record, re-executing
only when a claim has expired without the turn completing. The sender's own request
MUST never be held for turn execution.

#### Scenario: The immediate path answers before the cron tick
- **GIVEN** an addressed Talk message on an instance with the immediate path
  available
- **WHEN** the message is handed off
- **THEN** the turn MUST execute without waiting for a background-job tick
- **AND** the queued job, when it later runs, MUST find the claim and do nothing

#### Scenario: A redelivered event does not produce a second answer
- **GIVEN** the same Talk message delivered to the listener twice
- **WHEN** both deliveries are handed off
- **THEN** exactly one turn MUST execute and exactly one answer MUST be posted

#### Scenario: A crashed immediate turn is recovered by the queue
- **GIVEN** an immediate-path turn whose process dies mid-execution
- **WHEN** the turn's claim expires and the queued job runs
- **THEN** the queued job MUST execute the turn
- **AND** the room MUST receive exactly one visible answer

#### Scenario: No immediate path degrades to today's behaviour
- **GIVEN** an instance where neither a triggerable runner nor post-flush execution
  is available
- **WHEN** an addressed message is handed off
- **THEN** the turn MUST execute on the queued path and the answer MUST still arrive

### Requirement: Acknowledgement is immediate and resolves with the answer
The system MUST add the acknowledging ⏳ reaction during the in-process handling of
the bot invocation (before any turn execution), targeting acknowledgement within a
few seconds of the message landing, and MUST resolve the pending state when the
answer (or a failure notice) is posted, so the reaction is never the only and final
signal.

#### Scenario: A message is acknowledged before it is answered
- **WHEN** an addressed message lands
- **THEN** the ⏳ reaction MUST be added within the invocation handling itself
- **AND** the answer or a failure notice MUST follow from the executed turn

### Requirement: Replies stream progressively where Talk supports message editing
When the Talk installation supports bot message editing, the system MUST post an
initial reply early in generation and update it by editing as generation progresses,
throttled to a configurable minimum interval between edits, and MUST finish with an
edit containing the exact complete answer. When editing is unsupported or an edit
fails, the system MUST fall back to delivering the complete answer as a message —
progressive delivery MUST NOT be able to lose or truncate an answer.

#### Scenario: A long answer arrives progressively
- **GIVEN** a Talk installation supporting bot message editing
- **WHEN** an agent generates a long answer
- **THEN** the room MUST receive an initial partial reply followed by throttled
  edits
- **AND** the final state of the message MUST be the complete answer

#### Scenario: No edit support falls back to a whole message
- **GIVEN** a Talk installation without bot message editing
- **WHEN** an agent answers
- **THEN** the answer MUST be delivered as a single complete message

#### Scenario: An edit failure does not eat the answer
- **GIVEN** progressive delivery whose edit call starts failing mid-answer
- **WHEN** generation completes
- **THEN** the complete answer MUST still be delivered to the room

### Requirement: A room may host several agents, managed by Talk moderators
The system MUST register one Talk bot identity per Talk-enabled agent, so that a
Talk moderator enables or disables each agent per room through Talk's own bot
management, and disabling an agent's bot MUST stop that agent's inbound dispatch for
that room immediately. The system MUST NOT maintain a parallel room-membership store
that can contradict Talk's bot state. The single-agent `talk_room_agents` binding
MUST keep working for rooms with exactly one enabled agent.

#### Scenario: A moderator adds a second agent to a room
- **GIVEN** a room with one enabled agent bot
- **WHEN** a moderator enables a second agent's bot in that room
- **THEN** both agents MUST be individually addressable in the room

#### Scenario: Disabling a bot silences that agent only
- **GIVEN** a room with two enabled agent bots
- **WHEN** a moderator disables one agent's bot
- **THEN** that agent MUST take no further turns in the room
- **AND** the other agent MUST be unaffected

### Requirement: In a group room, only an addressed agent takes a turn
The system MUST trigger an agent's turn in a group room only when that specific
agent is @-mentioned by its own name or when the message is a reply to one of that
agent's own messages. A group message addressing no agent MUST trigger no turn; a
message mentioning several agents MUST trigger one turn per mentioned agent, each
under its own turn id. One-to-one conversations with a single agent bot MUST keep
treating every message as a turn.

#### Scenario: Two agents, one is addressed
- **GIVEN** a group room with agents A and B enabled
- **WHEN** a participant mentions only A
- **THEN** A MUST take a turn and B MUST NOT

#### Scenario: Two agents mentioned in one message
- **WHEN** a participant mentions both A and B in one message
- **THEN** A and B MUST each take exactly one turn

#### Scenario: An unaddressed message triggers nobody
- **GIVEN** a group room with several agents enabled
- **WHEN** a participant posts without mentioning or replying to any agent
- **THEN** no agent MUST take a turn

### Requirement: Sessions are per room per agent and ordered
The system MUST maintain one session per (room, agent) pair, and MUST execute the
turns of one such session serially in Talk message order, so replies cannot land out
of order within an agent's thread of the room. Turns of different agents in the same
room MAY execute concurrently. Every human turn MUST follow the shared-session
rules: the speaker must be the session's owner or a listed participant, and the turn
runs as the speaker with speaker-scoped files and credentials.

#### Scenario: Two quick messages to one agent answer in order
- **GIVEN** two addressed messages to the same agent in quick succession
- **WHEN** both turns execute
- **THEN** the answers MUST be posted in the order of the originating messages

#### Scenario: Each agent keeps its own session in a shared room
- **GIVEN** a room with agents A and B and turns addressed to each
- **WHEN** histories are assembled for their next turns
- **THEN** A's session history MUST NOT contain B's session turns, and vice versa

### Requirement: Agent-to-agent turns are mention-only, bounded, and human-rooted
The system MUST allow an agent's reply to trigger another enabled agent's turn only
by explicit @-mention; an agent MUST never react to an agent message that does not
mention it and MUST never trigger itself. Every agent-triggered turn MUST carry a
chain rooted at the originating human turn, and the system MUST enforce a
configurable maximum hop count per chain and a per-room rate cap on agent-triggered
turns; a chain ending on an exhausted budget MUST end with a brief visible notice,
not silence. Agent-triggered turns MUST execute as the originating human speaker —
subject to that speaker's session authorization, files, and credentials — never as
an agent identity and never as any other participant.

#### Scenario: An agent asks another agent for input
- **GIVEN** a room with agents A and B, and a human turn to A whose reply mentions B
- **WHEN** A's reply is posted
- **THEN** B MUST take one turn, attributed in chain terms to the originating human

#### Scenario: A mention ping-pong is stopped by the hop budget
- **GIVEN** agents A and B whose replies keep mentioning each other
- **WHEN** the chain reaches the configured maximum hop count
- **THEN** the system MUST take no further agent-triggered turn on that chain
- **AND** MUST post a brief notice that the chain ended

#### Scenario: A chain cannot exceed its rooting human's access
- **GIVEN** an agent-triggered turn in a chain rooted by a given participant
- **WHEN** that turn resolves context files and credentials
- **THEN** they MUST resolve as that rooting participant
- **AND** a file readable only by another room participant MUST NOT be resolved

#### Scenario: An agent message without a mention is inert
- **WHEN** an agent posts a reply mentioning no agent
- **THEN** no agent-triggered turn MUST follow from that message
