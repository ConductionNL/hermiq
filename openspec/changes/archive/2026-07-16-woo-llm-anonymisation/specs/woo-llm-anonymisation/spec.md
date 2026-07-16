# woo-llm-anonymisation (delta)

This change adds a structured, tool-free PII/redaction-span detection
endpoint alongside the existing `case-assistant-surface` conversational
endpoint, for callers (procest's Woo document assessment) that need
position-addressable PII locations rather than free-text chat.

## ADDED Requirements

### Requirement: Structured PII span detection endpoint
The system MUST provide `POST /api/assistant/detect-pii` accepting `{text,
context: {app, objectType?, objectRef?}}` and returning `{spans: [{start,
end, category, confidence}], usage}` within a single HTTP request/response.

#### Scenario: Caller submits document text for PII detection
- **GIVEN** an authenticated user and a `text` payload under the length cap
- **WHEN** they POST `text` and `context.app`
- **THEN** the system MUST run one tool-free LLM turn and return
  `{spans, usage}` where each span has integer `start`/`end` offsets and a
  `category` string

#### Scenario: Oversized text is rejected
- **GIVEN** an authenticated user
- **WHEN** they POST `text` longer than 12000 characters
- **THEN** the system MUST return 400 and MUST NOT call the LLM provider

### Requirement: No tool execution capability on this surface
The system MUST NOT allow this endpoint's LLM turn to invoke any registered
tool/function, by construction — the same `tools: ['__none__']` sentinel
agent pattern `case-assistant-surface` uses, verified directly against
`ToolLoop::listAgentFunctions()`.

#### Scenario: Document text attempts to instruct the model to take an action
- **GIVEN** a `text` payload containing an embedded instruction asking the
  assistant to perform a write action
- **WHEN** the endpoint processes it
- **THEN** the system MUST respond with a span list (or a guardrail block)
  only — no tool is invoked, no side effect occurs

### Requirement: Prompt-injection filtering stays active; PII input-redaction is bypassed
The system MUST still apply the effective `GuardrailPolicy`'s
`promptInjectionAction` check to the submitted text, but MUST NOT apply the
policy's PII input-redaction action to it — detecting PII requires the model
to see it.

#### Scenario: Submitted text matches a blocking prompt-injection pattern
- **GIVEN** an organisation's effective `GuardrailPolicy` blocks a
  prompt-injection pattern
- **WHEN** submitted `text` matches that pattern
- **THEN** the system MUST return 422 with `errorCode: guardrail_blocked`
  and MUST NOT call the LLM provider

#### Scenario: Submitted text contains PII and the org's input PII action is redact
- **GIVEN** an organisation's effective `GuardrailPolicy` has
  `inputFilters.piiAction: 'redact'`
- **WHEN** `text` containing a BSN-shaped number is submitted
- **THEN** the system MUST pass the UNREDACTED text to the LLM provider
  (the policy's PII input action is bypassed for this endpoint only)

### Requirement: No conversation persistence
The system MUST NOT create or update any `Conversation`/`Message`
OpenRegister object as part of processing this endpoint — detection is a
stateless, single-shot call.

#### Scenario: A detection call completes successfully
- **GIVEN** a valid detection request
- **WHEN** the endpoint returns `{spans, usage}`
- **THEN** `MessageHistoryHandler::storeMessage()` MUST NOT have been called

### Requirement: Malformed model output fails loud
The system MUST return 502 when the LLM's reply cannot be parsed as the
expected `{"spans": [...]}` JSON shape, rather than returning a partial or
guessed result.

#### Scenario: The model returns non-JSON or malformed JSON
- **GIVEN** a detection request that reaches the LLM provider
- **WHEN** the provider's reply is not valid `{"spans": [...]}` JSON
- **THEN** the system MUST return 502 and MUST NOT return a `spans` array
