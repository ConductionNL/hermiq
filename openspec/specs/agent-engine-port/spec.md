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

