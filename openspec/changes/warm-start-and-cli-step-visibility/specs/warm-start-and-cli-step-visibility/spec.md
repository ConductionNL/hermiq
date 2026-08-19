# warm-start-and-cli-step-visibility Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- `warm-start-and-cli-step-visibility`

## Purpose

Two costs that a `cli`-mode turn imposed on the user, both of them avoidable and
neither of them inference:

1. **The first question of a conversation paid for the process start.** Measured
   on this instance, a trivial prompt cost **11.2s cold against 3.2s warm** — the
   difference is a process that nobody had started yet.
2. **A governed `cli` turn's tool calls were invisible.** The chat already
   renders steps, but only for tools the engine invokes **in process**. A `cli`
   turn's tools run inside separate HTTP requests, so a turn that made five tool
   calls appeared to the user as one silent minute.

## ADDED Requirements

### Requirement: The CLI process is warmed before the first question, not by it

Hermiq MUST expose an endpoint that starts an agent's pooled CLI process for a
conversation **without running a turn**, so the process start happens while the
user is still typing rather than inside their first question.

The warm-up MUST cost a process start and nothing else: no prompt is sent, so
there is no inference, no tokens and no vendor request. It is not a "hello" turn.

Only the `cli` transport has a process to warm; every other provider is a plain
HTTP call with nothing to pre-start, and MUST be reported as not warmed rather
than treated as an error.

#### Scenario: opening a chat warms the agent's process

- **GIVEN** an agent whose provider is `anthropic` with `executionMode: cli`
- **WHEN** the chat is opened or that agent is selected
- **THEN** the agent's pooled CLI process is started
- **AND** no prompt is sent and no inference is billed

#### Scenario: a non-cli agent is not an error

- **GIVEN** an agent served over plain HTTP
- **WHEN** the warm-up is requested for it
- **THEN** the response reports that nothing was warmed
- **AND** the status is 200

### Requirement: A failed warm-up is invisible to the chat

The warm-up endpoint MUST answer 200 whatever happens. A warm-up is an
optimisation the following turn does not depend on, so a failure MUST be
invisible rather than something the chat has to handle.

This includes the absent-conversation case: the conversation lookup **throws**
rather than returning null, and that is the live path when a chat opens on a
stale conversation id — not a hypothetical one.

#### Scenario: a warm-up for a conversation that is gone still answers 200

- **GIVEN** a warm-up requested for a conversation id that no longer resolves
- **WHEN** the endpoint runs
- **THEN** the status is 200 and the response reports that nothing was warmed
- **AND** the chat is given no error to display for a request the user never made

### Requirement: A cli turn's tool calls are visible in the chat

A tool call executed through the governed MCP transport MUST reach the same
chat surface as a tool the engine invokes in process, so the two transports are
indistinguishable to the person watching.

The correlation MUST come from the per-run bearer token, which already binds
`(runId, agentId, userId, conversationId)` — so a tool running in a separate
HTTP request knows which conversation to append its step to.

Steps are display material with a one-turn lifetime, read once. They MUST live
in a TTL-native cache rather than the database: they are not the audit trail,
and giving them a schema, a migration and a cleanup job would imply they were.

#### Scenario: a governed tool call appears as a step

- **GIVEN** a `cli`-mode turn whose CLI calls a granted tool over the governed MCP endpoint
- **WHEN** the tool runs in its own HTTP request
- **THEN** the step is recorded against that turn's conversation
- **AND** the chat renders it exactly as it renders an in-process tool call

#### Scenario: steps do not outlive their turn

- **GIVEN** steps recorded for a completed turn
- **WHEN** the turn's records are drained
- **THEN** they are read once and expire on their own
- **AND** no migration or cleanup job is required to remove them
