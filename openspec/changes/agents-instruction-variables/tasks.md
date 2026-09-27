# Tasks: agents-instruction-variables

Kind: code. Size M. Rows `hermiq:dm-prompt-placeholders`, `hermiq:dm-agent-form-fields`.

## Implementation tasks

### Task 1: Schema for start fields and values
- **spec_ref**: `openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002`
- **files**: `lib/Settings/hermiq_register.json` (Agent `startFields`, session `startValues`, Schedule `startValues`)
- **acceptance_criteria**:
  - GIVEN the re-import WHEN it runs THEN the three properties exist with the key pattern and the ten-field limit
- [ ] Implement
- [ ] Test (npm run check:register; coordinate the session property with the open session-schema-declaration chain and name the order in the PR)

### Task 2: The placeholder resolver in the engine
- **spec_ref**: `openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001`
- **files**: `lib/Service/Engine/PromptVariableResolver.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN each placeholder WHEN resolved THEN the value is right and unknown ones stay
  - GIVEN a retrieved document containing `{{user.id}}` WHEN the turn runs THEN it is not replaced
- [ ] Implement
- [ ] Test (PHPUnit table test for every placeholder, time zones and the no-template rule)

### Task 3: Start fields on the chat page
- **spec_ref**: `openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002`
- **files**: `src/views/Chat.vue`, `src/components/StartFieldsForm.vue`, `src/api/chat.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a required field WHEN it is empty THEN the send button is disabled with the field marked
- [ ] Implement
- [ ] Test (Playwright under tests/e2e/spec-coverage/)

### Task 4: The field editor, placeholder menu and preview on the agent form
- **spec_ref**: `openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001`
- **files**: `src/modals/AgentFormModal.vue`, `lib/Controller/AgentsController.php` (prompt-preview), `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN the owner WHEN they preview THEN the filled-in text shows; a non-owner gets 404
- [ ] Implement
- [ ] Test (PHPUnit on the preview guard; Playwright for the editor)

## Verification
- [ ] `openspec validate agents-instruction-variables --type change --strict` passes
- [ ] PHPUnit and Playwright run, exit codes read
