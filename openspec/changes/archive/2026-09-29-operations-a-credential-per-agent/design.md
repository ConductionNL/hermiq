# Design: operations-a-credential-per-agent

Read at hermiq development c91e637a.

## Where it lives

- `lib/Settings/hermiq_register.json` Agent: optional `credentialIds` (object, `additionalProperties` a uuid string), with the register version bumped because the import is gated on `info.version`.
- `lib/Service/Credential/CredentialScopeResolver.php`: `resolve()` gains an optional `?array $pinned` (the agent's map); when it names the provider, that id is returned as is and the broker decides.
- `lib/Service/Llm/ProviderFactory.php`: `instantiateChatDriver()` receives the agent's pinned map from the turn's agent (the engine already passes `agentId` to the CLI paths, `:1019`); `resolveCredentialOverride()` passes it on and marks the id as pinned so a broker refusal is not retried with the fallbacks.
- `src/modals/AgentFormModal.vue`: a "Credentials" section with one `NcSelect` per provider (with `inputLabel`), options from the broker's list of credentials the user may use for hermiq.
- Owner-only writes stay as they are: the Agent authorization block omits write actions, so only the owner and admins can set `credentialIds`.

## The payload written into OpenRegister

A PHPUnit test builds the Agent object the form saves with `credentialIds` and validates it against the real Agent schema fragment from the register file with OpenRegister's validator.

## Decisions

- D1. Fail closed on a refused pin. Falling back would run the agent with a wider credential than its owner chose, which is the opposite of the point. Confirmed by Ruben on 29 Sep 2026: the turn stops, no fallback, and the user sees why.
- D2. The pin is a reference, never a secret. The broker keeps the secret and its guards.
