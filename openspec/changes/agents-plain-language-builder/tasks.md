# Tasks: agents-plain-language-builder

> Archive pass 2026-10-07: code done; open: task 1 live chat with the builder, task 2 Newman run (live check).

Kind: code. Size M. Rows `hermiq:ag-nl-builder`, `hermiq:dm-builder-upgrade`.

## Implementation tasks

### Task 1: Seed the agent-builder skill and the Agent builder agent
- **spec_ref**: `openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-described-agent-becomes-a-draft-in-chat-req-agbuild-001`
- **files**: `lib/Repair/SeedAgentBuilder.php`, `appinfo/info.xml` (repair step)
- **acceptance_criteria**:
  - GIVEN a fresh install and an upgrade WHEN the step runs twice THEN one skill and one agent exist, with no write tools granted
- [x] Implement (`lib/Repair/SeedAgentBuilder.php`, both repair lists in `appinfo/info.xml`)
- [x] Test: PHPUnit idempotency, existing builder left alone, seeds pass the real Skill and Agent fragments with Opis (negative control), `tests/Unit/Repair/SeedAgentBuilderTest.php`
- [ ] Test: a live chat with the builder, answer in the PR body (needs the live instance)

### Task 2: The draft check endpoint
- **spec_ref**: `openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002`
- **files**: `lib/Service/AgentDraftService.php`, `lib/Controller/AgentsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a draft with an unknown tool, a forbidden model, an unseen group and a bad cron WHEN checked THEN four findings come back
  - GIVEN invalid JSON WHEN checked THEN 422
- [x] Implement (`lib/Service/Agent/AgentDraftService.php`, `lib/Controller/AgentDraftController.php`, route `agent_draft#check`)
- [x] Test: PHPUnit per finding and 422, `tests/Unit/Service/Agent/AgentDraftServiceTest.php`, `tests/Unit/Controller/AgentDraftControllerTest.php`
- [ ] Test: Newman for 200 and 422, folder "Agent draft check" in `tests/integration/hermiq.postman_collection.json` (written; run needs the live instance)

### Task 3: Open as agent in chat and the pre-filled form
- **spec_ref**: `openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002`
- **files**: `src/views/Chat.vue`, `src/modals/AgentFormModal.vue`, `src/modals/ScheduleFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a message with a draft WHEN "Open as agent" is chosen THEN the form is pre-filled with findings beside fields
  - GIVEN the form is saved WHEN a schedule was proposed THEN the schedule form opens pre-filled
- [x] Implement (`src/utils/agentDraft.js`, `src/api/agents.js` checkAgentDraft, Chat.vue, AgentFormModal.vue, ScheduleFormModal.vue heading, l10n)
- [x] Test: `tests/agent-draft.spec.js` (node) and `tests/e2e/spec-coverage/agents-plain-language-builder.spec.ts` (Playwright, stubbed assistant message; runs nightly)

## Verification
- [x] `openspec validate agents-plain-language-builder --type change --strict` passes
- [x] A test asserts no tool id with create, update or delete on a hermiq schema was added (`SeedAgentBuilderTest::testNoToolWritesAHermiqSchema`)
