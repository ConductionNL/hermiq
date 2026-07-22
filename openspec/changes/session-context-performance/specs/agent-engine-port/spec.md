# Agent Engine Port Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `session-context-performance` — conversation-title generation moves off the synchronous reply
  path (kind: code)

## Purpose

Delta for `openspec/specs/agent-engine-port/spec.md`. One user message currently spawns **two**
`claude` CLI processes: one for the reply, one for the conversation title. The title spawn runs
synchronously inside the turn (`Engine::maybeGenerateTitle()`, line 525, called at line 424),
adding roughly 20 seconds to the user's wait for a sidebar label they did not ask for. The reply
does not depend on the title.

## ADDED Requirements

### Requirement: Conversation-title generation does not block the reply
The system MUST deliver an agent's reply without waiting for conversation-title generation. Title
generation MUST NOT run synchronously on the reply path. A conversation without a generated title
MUST NOT be a failure state — the system already writes a `New conversation` placeholder title
when a conversation is created.

#### Scenario: A user sends the first message in a conversation
- GIVEN a new conversation whose title is the placeholder `New conversation`
- WHEN the user sends their first message
- THEN the system MUST deliver the reply without waiting for the title to be generated
- AND the reply's wall time MUST NOT include the title's LLM round-trip
- AND the generated title MUST arrive afterwards

#### Scenario: Only one CLI process is spawned on the reply path
- GIVEN one user message
- WHEN the turn runs
- THEN exactly one `claude` process MUST be spawned on the reply's critical path
- AND the title's process MUST NOT be spawned on that path

#### Scenario: Title generation fails
- GIVEN title generation fails or its provider is unconfigured
- WHEN the turn completes
- THEN the reply MUST still have been delivered
- AND the conversation MUST retain a usable title

### Requirement: The deferred title write preserves the whole conversation object
The system MUST carry every `Conversation` field forward when writing a generated title, because
`ObjectService::saveObject()` is PUT-semantic and silently nulls omitted schema properties. A
title-only write MUST NOT null `userId`, `agentId`, or `metadata`.

#### Scenario: A generated title is written back
- GIVEN a conversation with a `userId`, an `agentId` and `metadata`
- WHEN the generated title is written
- THEN the conversation's `userId`, `agentId` and `metadata` MUST be unchanged
- AND only the `title` MUST differ

### Requirement: Deferring title generation preserves tenant-model-policy enforcement
The system MUST pass the conversation's organisation to title generation when it is deferred.
Passing a null organisation skips tenant-model-policy enforcement — its documented
backward-compatible default — so a deferred call that drops the organisation MUST NOT occur.

#### Scenario: A title is generated for a conversation in an organisation
- GIVEN a conversation belonging to an organisation with a model policy
- WHEN title generation runs off the reply path
- THEN the system MUST pass that organisation to title generation
- AND the organisation's model policy MUST be enforced for the title's LLM call

#### Scenario: A model policy would reject the title's model
- GIVEN an organisation whose model policy rejects the configured background model
- WHEN deferred title generation runs
- THEN the policy MUST be enforced exactly as it is on the synchronous path today
- AND the system MUST NOT silently bypass the policy by passing a null organisation

## Non-Functional Requirements

- **Performance:** removing the title spawn from the reply path MUST reduce a first-turn reply's
  wall time by approximately the title's round-trip (~20s against a measured 65–106s wall). The
  `llm` phase floor (9s for a two-character answer, up to 17s for a normal reply) is unaffected by
  this change — reducing it is `claude-cli-session-reuse`'s scope.
- **Accessibility:** not applicable — no frontend surface changes. Users see a placeholder title
  briefly before the generated one arrives.
- **Internationalization:** not applicable — no new user-facing strings. The existing
  `New conversation` placeholder is unchanged. (Dutch and English remain supported per ADR-005.)

## Acceptance Criteria

- A first-turn reply is delivered without the title's LLM round-trip in its wall time.
- Exactly one `claude` process is spawned on the reply's critical path (down from two).
- The generated title arrives after the reply and is visible in the conversation list.
- A title write leaves `userId`, `agentId` and `metadata` unchanged — asserted by a test on a non-changed field.
- Deferred title generation still receives the conversation's organisation; policy enforcement is unchanged.
- A title-generation failure never prevents the reply.

## Notes

- `resolveConversation()` writes `'title' => 'New conversation'` at creation
  (`ChatStreamController.php:689`), so a placeholder always exists and an untitled conversation is
  never a failure state.
- The mechanism (NC background job vs post-stream dispatch) is left to apply — both remove the
  block. The two constraints above are binding on whichever is chosen.
- **`saveObject()` is PUT-semantic** — omitted schema properties are silently nulled. This is a
  known, repeated failure mode in this codebase; the test must assert that a non-changed field
  survives the write.
- **Do not drop the `$organisation` argument.** `generateConversationTitle(string $firstMessage,
  ?string $organisation = null)` — null skips policy enforcement as a backward-compatible default.
  A background job that forgets it turns a latency fix into a governance hole.
- `generateFallbackTitle()` already exists for the error path (a truncated first message). It stays
  the fallback it is; it is not the primary fix.
