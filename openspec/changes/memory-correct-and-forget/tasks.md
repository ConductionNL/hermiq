# Tasks: memory-correct-and-forget

Kind: code. Size S. Row `hermiq:me-view-edit`.

### Task 1: Service: correct an entry and give old entries an id
- **spec_ref**: `openspec/changes/memory-correct-and-forget/specs/agent-memory/spec.md#requirement-an-owner-can-correct-a-remembered-fact-req-memedit-001`
- **files**: `lib/Service/MemoryService.php`
- **acceptance_criteria**: GIVEN an entry WHEN corrected THEN the old entry has deletedAt, a new entry holds the text, and the saved object validates against the real Memory schema
- [ ] Implement
- [ ] Test (PHPUnit with the real schema fragment and validator)

### Task 2: Endpoints and their authorization
- **spec_ref**: `openspec/changes/memory-correct-and-forget/specs/agent-memory/spec.md#requirement-an-owner-can-make-an-agent-forget-a-fact-req-memedit-002`
- **files**: `lib/Controller/MemoryController.php`, `appinfo/routes.php`
- **acceptance_criteria**: GIVEN the owner WHEN they call DELETE or PUT THEN 200; GIVEN another user THEN 404 and nothing changes
- [ ] Implement
- [ ] Test (PHPUnit per role)

### Task 3: Actions on the memory list
- **spec_ref**: `openspec/changes/memory-correct-and-forget/specs/agent-memory/spec.md#requirement-an-owner-can-correct-a-remembered-fact-req-memedit-001`
- **files**: `src/api/memory.js`, `src/components/AgentMemoryPanel.vue`, `src/dialogs/ForgetMemoryDialog.vue`, `l10n/`
- **acceptance_criteria**: GIVEN the panel WHEN an entry is corrected or forgotten THEN the list reloads showing the result
- [ ] Implement
- [ ] Test (eslint, test:l10n)
