# Tasks: record-chat-ready-made-prompts

Kind: code. Size S. Row `hermiq:ch-prompt-library`.

### Task 1: Read the prompts for a record type
- **spec_ref**: `openspec/changes/record-chat-ready-made-prompts/specs/ai-feature-admin-surface/spec.md#requirement-the-record-chat-offers-the-ready-made-prompts-for-its-record-type-req-rcprompt-001`
- **files**: `src/utils/readyMadePrompts.js`, `tests/record-chat-prompts.spec.js`
- **acceptance_criteria**: GIVEN a record type WHEN the prompts load THEN the scoped read is made and the order is kept; a failure yields none
- [x] Implement
- [x] Test

### Task 2: Offer them in the record chat
- **spec_ref**: `openspec/changes/record-chat-ready-made-prompts/specs/ai-feature-admin-surface/spec.md#requirement-the-record-chat-offers-the-ready-made-prompts-for-its-record-type-req-rcprompt-001`
- **files**: `src/components/CnAgentChatTab/CnAgentChatTab.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**: GIVEN prompts for the record type WHEN the person picks one THEN its text is in the message box and nothing was sent
- [x] Implement
- [x] Test
