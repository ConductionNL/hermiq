# Tasks: models-bind-a-provider-per-feature

Kind: code. Size S. Row `hermiq:mo-per-feature`.

### Task 1: The binding modal
- **spec_ref**: `openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001`
- **files**: `src/modals/AiFeatureBindingModal.vue`, `l10n/`
- **acceptance_criteria**: GIVEN the policy allows ollama and openai WHEN the modal opens THEN both are offered and only their allowed models
- [ ] Implement
- [ ] Test (component test on the options and the save call; eslint; test:l10n)

### Task 2: The action on the register
- **spec_ref**: `openspec/changes/models-bind-a-provider-per-feature/specs/ai-feature-governance/spec.md#requirement-an-administrator-chooses-the-provider-of-one-ai-feature-req-aibind-001`
- **files**: `src/views/AiFeatureRegister.vue`
- **acceptance_criteria**: GIVEN a saved binding WHEN the modal closes THEN the row shows the new provider, model and residency; GIVEN a 422 THEN the modal shows the server's message and the row is unchanged
- [ ] Implement
- [ ] Test (component test)
