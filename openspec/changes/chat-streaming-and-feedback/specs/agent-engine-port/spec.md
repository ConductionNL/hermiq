# agent-engine-port

## ADDED Requirements

### Requirement: The chat page streams the answer as the agent writes it (REQ-CHSTRM-001)

The chat page SHALL send a turn to `POST /api/chat/stream` and SHALL show the answer in the
open message bubble as `token` events arrive, before the turn ends. The endpoint SHALL answer
with `text/event-stream` and the events `token`, `tool_call`, `tool_result`, `heartbeat`,
`final` and `error`, and SHALL require the Nextcloud session and the CSRF token like any other
write. When the stream fails in transport, the page SHALL send the same turn once to the
non-streaming send endpoint; a terminal `error` event SHALL NOT be retried, so the person's
message is never stored twice.

Rows: `ch-stream`. Board: `hermiq/HmChat`.

#### Scenario: The answer grows while it is written
- **GIVEN** a person on the chat page with an agent whose model streams
- **WHEN** they send a message
- **THEN** the answer bubble shows the first words before the `final` event arrives
- **AND** after `final` the bubble holds the whole answer as stored

#### Scenario: A broken stream falls back once
- **GIVEN** a proxy that closes the event stream before any event
- **WHEN** a person sends a message
- **THEN** the page sends the turn once to the non-streaming endpoint and shows its answer
- **AND** the conversation holds the person's message once

### Requirement: A person rates an answer up or down, with an optional comment (REQ-CHFB-001)

Under every saved answer on the chat page a person who may take part in the conversation SHALL
see a thumbs up and a thumbs down. Choosing one SHALL store a `Feedback` object with the
message, conversation, agent, person, type (`positive` or `negative`) and an optional comment,
through `POST /api/conversations/{uuid}/messages/{id}/feedback`. A person SHALL have at most one
rating per answer: rating again SHALL update that rating. The endpoint SHALL refuse a type other
than `positive` or `negative` (400), a person who may not take part in the conversation (403)
and a message that does not belong to the conversation (404). The ratings SHALL feed what
`run-analytics` shows the agent owner (REQ-FBSTAT-001/002).

Rows: `ch-feedback`. Board: `hermiq/HmChat`.

#### Scenario: A thumbs down with a comment is kept
- **GIVEN** an answer in a person's conversation
- **WHEN** they choose thumbs down, type "Mist de bron" and send the feedback
- **THEN** one `Feedback` object holds type `negative` and that comment
- **AND** after a reload the thumbs down is still marked on that answer

#### Scenario: Rating again changes the one rating
- **GIVEN** a person rated an answer thumbs down
- **WHEN** they choose thumbs up on the same answer
- **THEN** the same `Feedback` object now holds type `positive` and no second object exists

#### Scenario: Someone outside the conversation cannot rate
- **GIVEN** a conversation a user does not take part in
- **WHEN** that user posts feedback for one of its answers
- **THEN** the request is refused with 403 and no `Feedback` object is stored
