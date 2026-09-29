# Tasks: operations-a-credential-per-agent

Kind: code. Size M. Row `hermiq:op-agent-credential`.

### Task 1: Schema
- **spec_ref**: `openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**: GIVEN an Agent payload with credentialIds WHEN validated against the real schema THEN it passes; a non-uuid value fails
- [ ] Implement
- [ ] Test (check:register; PHPUnit with the real validator)

### Task 2: Resolution order and fail closed
- **spec_ref**: `openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002`
- **files**: `lib/Service/Credential/CredentialScopeResolver.php`, `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**: GIVEN a pin for openai WHEN a turn resolves THEN the pin is used; GIVEN the broker refuses it THEN the turn stops with the error and no other credential is tried
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 3: The agent form
- **spec_ref**: `openspec/changes/operations-a-credential-per-agent/specs/agent-credentials/spec.md#requirement-an-agent-can-carry-its-own-credential-per-provider-req-agcred-001`
- **files**: `src/modals/AgentFormModal.vue`, `l10n/`
- **acceptance_criteria**: GIVEN the owner WHEN they pick a credential for openai and save THEN the agent stores it
- [ ] Implement
- [ ] Test (eslint, test:l10n)
