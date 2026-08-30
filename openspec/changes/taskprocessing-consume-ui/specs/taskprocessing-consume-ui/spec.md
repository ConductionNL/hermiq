## ADDED Requirements

### Requirement: Admins can read the current LLM provider configuration without exposing secrets

The system MUST expose an admin-only `GET /api/settings/llm` endpoint that returns
the current `hermiq.llm` configuration. Every stored credential field MUST be
replaced by a boolean "is set" flag in the response — a stored API key MUST NOT be
returned to the browser. The endpoint MUST be reachable only by a user authorized
for the Hermiq admin settings panel.

#### Scenario: Reading the config masks stored credentials

- **GIVEN** `hermiq.llm` has a non-empty `openaiConfig.apiKey`
- **WHEN** an authorized admin calls `GET /api/settings/llm`
- **THEN** the response MUST report the OpenAI key as a boolean set-flag of `true`
- **AND** the response MUST NOT contain the literal stored key value

#### Scenario: Reading the config reports which provider is selected

- **GIVEN** `hermiq.llm.chatProvider` is `nextcloud`
- **WHEN** an authorized admin calls `GET /api/settings/llm`
- **THEN** the response MUST report `chatProvider` as `nextcloud`

### Requirement: Admins can select any supported chat provider, including nextcloud

The system MUST expose an admin-only `PATCH /api/settings/llm` endpoint that updates
the `hermiq.llm` configuration with PATCH (merge) semantics. It MUST reject a
`chatProvider` value that is not one of `LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS`
(`openai`, `ollama`, `fireworks`, `nextcloud`) with an unprocessable-entity status,
and MUST NOT overwrite a previously-stored credential with an empty-string value
submitted in the patch.

#### Scenario: Selecting the nextcloud provider persists it

- **GIVEN** an authorized admin
- **WHEN** they `PATCH /api/settings/llm` with `chatProvider: "nextcloud"`
- **THEN** the merged, persisted configuration MUST have `chatProvider` = `nextcloud`

#### Scenario: An unknown provider is rejected

- **WHEN** an authorized admin `PATCH`es `chatProvider: "bogus"`
- **THEN** the endpoint MUST respond with HTTP 422
- **AND** it MUST NOT persist the change

#### Scenario: A blank credential does not wipe a stored key

- **GIVEN** `hermiq.llm.openaiConfig.apiKey` is already set
- **WHEN** an authorized admin `PATCH`es an `openaiConfig.apiKey` of `""`
- **THEN** the previously-stored key MUST be preserved
