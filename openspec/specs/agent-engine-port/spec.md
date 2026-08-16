# agent-engine-port Specification

## Purpose
TBD - created by archiving change llm-keys-via-broker. Update Purpose after archive.
## Requirements
### Requirement: Hermiq holds no LLM API key

Hermiq SHALL NOT accept, store, or transmit an OpenAI or Fireworks API key. Every call to
those providers SHALL go through OpenRegister's `CredentialBrokerService`, carrying
`{method, path, body}` plus a credential UUID and the credential owner's UID.

The transport SHALL reduce the request URI to a **path**: the host is the broker's
host-lock, and a client that can name the host can name a different one. It SHALL **strip**
every broker-owned header (`Authorization`, `X-API-Key`, `apikey`) the LLM library set.

When the broker is unavailable, or a provider has no `credentialId`, the driver SHALL fail
closed with `ProviderUnavailableException` (503). There SHALL be no direct,
app-authenticated fallback path.

The stored config SHALL carry `credentialId`, never `apiKey`. A submitted `apiKey` SHALL be
refused rather than persisted, and SHALL NOT be echoed back by the read endpoint even when a
legacy blob still contains one.

@e2e exclude A live LLM call needs a real, billed provider key, so the happy path cannot run
in CI or against the dev instance. The security-relevant halves ARE mechanically verified:
`BrokerHttpClientTest` pins the header-strip and the fail-closed guards;
`LlmSettingsHandlerTest` pins that a submitted key is never persisted;
`LlmSettingsControllerTest` pins that a legacy cleartext key is never echoed. The
credential-picker UI is covered by the settings Playwright run.

#### Scenario: A provider without a credential cannot serve a request

- **WHEN** the chat provider is `openai` and no `credentialId` is configured
- **THEN** the driver throws `ProviderUnavailableException` with status 503
- **AND** no outbound call to the provider is made

#### Scenario: The library's auth header never reaches the provider

- **WHEN** openai-php sets a Bearer header and the request passes through the broker client
- **THEN** that header is stripped before the broker call
- **AND** the broker injects the real secret server-side

#### Scenario: A submitted API key is not persisted

- **WHEN** a client PATCHes an `apiKey` into `openaiConfig`
- **THEN** the key is not written into the `hermiq.llm` blob
- **AND** the submission is logged as ignored

#### Scenario: A legacy cleartext key is never echoed back

- **WHEN** the stored blob still contains a cleartext `apiKey` (written before this release)
- **THEN** the read endpoint strips it from the response
- **AND** the raw value appears nowhere in the serialised payload

### Requirement: The legacy cleartext keys are deleted

A repair step SHALL rewrite the `hermiq.llm` blob without `openaiConfig.apiKey` and
`fireworksConfig.apiKey`, preserving every other field (models, provider selection, the
Ollama URL, vector config).

These keys were stored **unencrypted**. Leaving them after the migration would be dead
config that is still live secret material, waiting for the next database dump — so deleting
them is part of the migration, not a tidy-up afterwards.

@e2e exclude A repair-step / storage concern with no user-visible surface. Verified live by
planting a cleartext key, running the step, and confirming its removal.

#### Scenario: A stored cleartext key is removed and the rest survives

- **WHEN** the repair step runs against a blob containing a cleartext `apiKey`
- **THEN** the key is removed from the stored blob
- **AND** the provider selection, models and Ollama URL are unchanged


<!--
  RESTORED 2026-08-16. These requirements describe shipped behaviour
  (ConversationTitleJob, ConversationTitleWriter) but had no home: the
  session-context-performance change was DELETED rather than archived in
  6cc6f176, taking its spec delta with it, so every @spec tag in that code
  pointed at a file that no longer existed and gate-46 reported them as
  dangling. Recovered from 6cc6f176^ and synced here, which is where the
  delta should have landed when the change closed.
-->

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
