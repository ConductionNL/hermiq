# Tasks: agents-plain-language-builder

Kind: code. Size M. Rows `hermiq:ag-nl-builder`, `hermiq:dm-builder-upgrade`.

## Implementation tasks

### Task 1: Seed the agent-builder skill and the Agent builder agent
- **spec_ref**: `openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-described-agent-becomes-a-draft-in-chat-req-agbuild-001`
- **files**: `lib/Repair/SeedAgentBuilder.php`, `appinfo/info.xml` (repair step)
- **acceptance_criteria**:
  - GIVEN a fresh install and an upgrade WHEN the step runs twice THEN one skill and one agent exist, with no write tools granted
- [ ] Implement
- [ ] Test (PHPUnit idempotency; a live chat with the builder, answer in the PR body)

### Task 2: The draft check endpoint
- **spec_ref**: `openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002`
- **files**: `lib/Service/AgentDraftService.php`, `lib/Controller/AgentsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a draft with an unknown tool, a forbidden model, an unseen group and a bad cron WHEN checked THEN four findings come back
  - GIVEN invalid JSON WHEN checked THEN 422
- [ ] Implement
- [ ] Test (PHPUnit per finding; Newman for 200 and 422)

### Task 3: Open as agent in chat and the pre-filled form
- **spec_ref**: `openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002`
- **files**: `src/views/Chat.vue`, `src/modals/AgentFormModal.vue`, `src/modals/ScheduleFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a message with a draft WHEN "Open as agent" is chosen THEN the form is pre-filled with findings beside fields
  - GIVEN the form is saved WHEN a schedule was proposed THEN the schedule form opens pre-filled
- [ ] Implement
- [ ] Test (Playwright with a stubbed assistant message carrying a draft)

## Verification
- [ ] `openspec validate agents-plain-language-builder --type change --strict` passes
- [ ] A test asserts no tool id with create, update or delete on a hermiq schema was added
