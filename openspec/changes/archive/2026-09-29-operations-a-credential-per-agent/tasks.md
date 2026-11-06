# Tasks: operations-a-credential-per-agent

Kind: code. Size M. Row `hermiq:op-agent-credential`.

### Task 1: Schema
- **spec_ref**: `openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**: GIVEN an Agent payload with credentialIds WHEN validated against the real schema THEN it passes; a non-uuid value fails
- [x] Implement
- [x] Test (check:register; PHPUnit with the real validator)

### Task 2: Resolution order and fail closed
- **spec_ref**: `openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002`
- **files**: `lib/Service/Credential/CredentialScopeResolver.php`, `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**: GIVEN a pin for openai WHEN a turn resolves THEN the pin is used; GIVEN the broker refuses it THEN the turn stops with the error and no other credential is tried
- [x] Implement
- [x] Test (PHPUnit)

### Task 3: The agent form
- **spec_ref**: `openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001`
- **files**: `src/modals/AgentFormModal.vue`, `l10n/`
- **acceptance_criteria**: GIVEN the owner WHEN they pick a credential for openai and save THEN the agent stores it
- [x] Implement
- [x] Test (eslint, test:l10n)

## Deviations and decisions
- D1 (fail closed on a refused pin) was confirmed by Ruben on 29 Sep 2026 (build-all DECISIONS.md row 12): the turn stops, never falls back, and the person reads why.
- "The broker refuses" is checked before the turn by `CredentialScopeResolver::usablePin()` with the broker's own tests (exists, provider, allowed for hermiq, the acting user's personal key or the agent organisation's key); the broker still re-runs its guards on use. A pin with no resolver to check it is refused.
- Pinnable providers are openai and fireworks, the two whose turns take a broker credential override today. The form offers one picker per provider.
- The refusal reaches the person in both chat paths: `ChatController` (message + errorCode `pinned_credential_refused`) and `ChatStreamController` (error event with the same code), unmasked.
