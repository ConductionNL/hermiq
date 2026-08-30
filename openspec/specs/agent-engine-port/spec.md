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

### Requirement: Conversation-title generation does not block the reply

The system MUST deliver an agent's reply without waiting for conversation-title generation.
Title generation MUST NOT run synchronously on the reply path. A conversation without a
generated title MUST NOT be a failure state — `ChatStreamController::resolveConversation()`
writes a `New conversation` placeholder at creation, so a readable title always exists and the
placeholder is the invariant that means "not yet named".

Naming a conversation is a second LLM round trip, which on the `cli` transport is a second
`claude` process — roughly 20s of a 65–106s wall, paid on the FIRST message of every
conversation, for a string nothing downstream of the reply depends on.

@e2e exclude Both halves need a real LLM round trip: the reply itself, and the title's own
generation. That needs a billed provider key, so neither can run in CI nor against the dev
instance — the same constraint recorded on "Hermiq holds no LLM API key" above. The
deferral is mechanically pinned instead: `ConversationTitleJob` is a `QueuedJob` whose `run()`
only forwards to `ConversationTitleWriter`, and `ConversationTitleWriterTest` exercises every
decision in it directly (placeholder recognition, failure swallowing, missing conversation).

#### Scenario: A user sends the first message in a conversation

- **GIVEN** a new conversation whose title is the placeholder `New conversation`
- **WHEN** the user sends their first message
- **THEN** the system MUST deliver the reply without waiting for the title to be generated
- **AND** the reply's wall time MUST NOT include the title's LLM round-trip
- **AND** the generated title MUST arrive afterwards

#### Scenario: Only one CLI process is spawned on the reply path

- **GIVEN** one user message
- **WHEN** the turn runs
- **THEN** exactly one `claude` process MUST be spawned on the reply's critical path
- **AND** the title's process MUST NOT be spawned on that path

#### Scenario: Title generation fails

- **GIVEN** title generation fails or its provider is unconfigured
- **WHEN** the deferred job completes
- **THEN** the reply MUST still have been delivered
- **AND** the conversation MUST retain its placeholder title rather than being left unreadable

#### Scenario: A user-set title is never overwritten

- **GIVEN** a conversation the user has titled themselves
- **WHEN** a deferred, duplicated or replayed title job runs against it
- **THEN** the conversation's title MUST be left alone
- **AND** the placeholder test MUST be re-read at write time rather than trusted from the
  job payload

### Requirement: The deferred title write preserves the whole conversation object

The system MUST carry every `Conversation` field forward when writing a generated title,
because `ObjectService::saveObject()` is PUT-semantic and silently nulls omitted schema
properties. A title-only patch MUST NOT null `userId`, `agentId`, or `metadata`.

The deferred write MUST also run as the conversation's OWNER. A background job carries no
session, and both things the write needs are identity-bound — OpenRegister RBAC refuses an
`update` from `Anonymous`, and the credential broker refuses to resolve a provider credential
for an unauthenticated principal. The owner is impersonated rather than RBAC being elevated,
because naming a conversation from a user's own message is that user's write. The impersonated
session MUST be released unconditionally, including when the write throws, because the job runs
in a shared worker process.

The job payload MUST NOT be the only thing that decides whose identity is assumed: the
conversation object's own `userId` MUST confirm it, so a stale or malformed payload cannot name
someone else's conversation under their credential.

@e2e exclude A PUT-semantics and session-lifetime concern with no user-visible surface — the
only rendered difference is the sidebar label, which cannot distinguish a whole-object write
from a patch. `ConversationTitleWriterTest` pins every clause of this requirement directly:
`testTitleWriteCarriesTheWholeObjectForward`, `testTheOwnerIsImpersonated…`,
`testTheSessionIsReleasedEvenWhenTheWriteThrows`, `testAConversationOwnedBySomeoneElseIsNotTitled`.

#### Scenario: A generated title is written back

- **GIVEN** a conversation with a `userId`, an `agentId` and `metadata`
- **WHEN** the generated title is written
- **THEN** the conversation's `userId`, `agentId` and `metadata` MUST be unchanged
- **AND** only the `title` MUST differ

#### Scenario: The impersonated session is released when the write throws

- **GIVEN** a deferred title write that raises
- **WHEN** the job returns
- **THEN** the prior session user MUST be restored
- **AND** the next job in the same worker MUST NOT inherit the conversation owner's identity

#### Scenario: A payload naming the wrong owner is refused

- **GIVEN** a job payload whose `userId` differs from the conversation's own `userId`
- **WHEN** the writer runs
- **THEN** no title MUST be written
- **AND** the mismatch MUST be logged

### Requirement: Deferring title generation preserves tenant-model-policy enforcement

The system MUST pass the conversation's organisation to title generation when it is deferred.
`generateConversationTitle(string $firstMessage, ?string $organisation = null)` treats a null
organisation as "skip tenant-model-policy enforcement" — its documented backward-compatible
default — so a deferred call that drops the organisation would turn a latency fix into a
governance hole.

@e2e exclude Enforcing a model policy requires the LLM call the policy governs, which needs a
billed provider key and cannot run in CI. `ConversationTitleWriterTest`'s
`testTheConversationsOrganisationIsPassedToGeneration` pins the argument that carries the
policy, which is the part deferral could have broken.

#### Scenario: A title is generated for a conversation in an organisation

- **GIVEN** a conversation belonging to an organisation with a model policy
- **WHEN** title generation runs off the reply path
- **THEN** the system MUST pass that organisation to title generation
- **AND** the organisation's model policy MUST be enforced for the title's LLM call

#### Scenario: A model policy would reject the title's model

- **GIVEN** an organisation whose model policy rejects the configured background model
- **WHEN** deferred title generation runs
- **THEN** the policy MUST be enforced exactly as it is on the synchronous path it replaced
- **AND** the system MUST NOT bypass the policy by passing a null organisation

