# case-assistant-surface (delta)

This change adds a minimal, tool-free conversational endpoint for leaf apps
that need synchronous, single-request grounded chat without the full
agent-engine-port tool/RAG orchestration.

## ADDED Requirements

### Requirement: Synchronous conversational endpoint
The system MUST provide `POST /api/assistant/converse` accepting `{sessionId?,
message, context: {app, objectType?, objectRef?, contextData?}}` and
returning `{sessionId, reply, usage}` within a single HTTP request/response
(no SSE, no polling).

#### Scenario: Caller sends a message with no prior session
- **GIVEN** an authenticated user and no `sessionId`
- **WHEN** they POST a `message` and `context.app`
- **THEN** the system MUST create a new `Conversation`, run one LLM turn
  grounded in `context.contextData`, and return `{sessionId, reply, usage}`
  where `sessionId` is the new conversation's UUID

#### Scenario: Caller continues an existing session
- **GIVEN** an authenticated user and a `sessionId` from a prior response
- **WHEN** they POST a follow-up `message`
- **THEN** the system MUST load the existing `Conversation`'s message
  history and include it in the LLM call

### Requirement: No tool execution capability on this surface
The system MUST NOT allow this endpoint's LLM turn to invoke any registered
tool/function, by construction (no code path on this surface calls
`ToolLoop::buildFunctionInfos()` or attaches tools to the chat driver) —
not by relying on empty-whitelist/empty-selection configuration, which
resolves to "every tool allowed" elsewhere in Hermiq.

#### Scenario: A user asks the assistant to perform a write action
- **GIVEN** a conversation on this surface
- **WHEN** the user's message asks the assistant to create, update, or delete
  something
- **THEN** the system MUST respond conversationally only — no tool is
  invoked, no side effect occurs

### Requirement: Ownership-scoped session reuse
The system MUST verify that a supplied `sessionId` belongs to the requesting
user before reusing it, returning 403 on a foreign session and 404 on an
unknown session — the caller's OpenRegister-derived organisation scoping
applies identically to `chat#sendMessage`.

#### Scenario: A user supplies another user's sessionId
- **GIVEN** conversation C belongs to user A
- **WHEN** user B posts to `/api/assistant/converse` with `sessionId: C`
- **THEN** the system MUST return 403 and MUST NOT process the message
  against C

### Requirement: Input length caps
The system MUST reject (400) a `message` longer than 8000 characters or a
JSON-encoded `context.contextData` longer than 20000 characters, without
silently truncating either.

#### Scenario: Caller sends an oversized message
- **GIVEN** an authenticated user
- **WHEN** they POST a `message` longer than 8000 characters
- **THEN** the system MUST return 400 and MUST NOT call the LLM provider

### Requirement: Guardrail and audit parity with chat#sendMessage
The system MUST apply the same effective `GuardrailPolicy` input/output
filtering `Engine::processMessage()` applies, and MUST persist every turn as
`Conversation`/`Message` OpenRegister objects so it is covered by OR's
existing automatic audit trail and by the existing `chat#getHistory`/
`chat#getChatStats` endpoints.

#### Scenario: A message matches a blocking guardrail rule
- **GIVEN** an organisation's effective `GuardrailPolicy` blocks a pattern
- **WHEN** a user's message matches that pattern
- **THEN** the system MUST return 422 with `errorCode: guardrail_blocked`
- **AND** the system MUST NOT persist an assistant message or call the LLM
