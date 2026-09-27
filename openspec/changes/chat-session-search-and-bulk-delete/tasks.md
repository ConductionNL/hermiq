# Tasks: chat-session-search-and-bulk-delete

Kind: code. Size M. Rows `hermiq:ch-search-history`, `dm-bulk-delete-chats`.

## Implementation tasks

### Task 1: A search term on the session list
- **spec_ref**: `openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001`
- **files**: `lib/Controller/SessionController.php`, `lib/Service/SessionSearchService.php`
- **acceptance_criteria**:
  - GIVEN a caller with a turn containing "monument" WHEN they call `GET /api/sessions?search=monument` THEN the session is returned with an excerpt and the turn uuid
  - GIVEN a shared session owned by someone else WHEN the caller searches a word only it contains THEN nothing is returned
  - GIVEN no `search` parameter WHEN the list is called THEN the answer is identical to today's
- [ ] Implement
- [ ] Test (PHPUnit on the service with two identities; Newman that seeds a turn and finds it)

### Task 2: The search field and opening a result at its turn
- **spec_ref**: `openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-search-their-own-sessions-by-title-and-content-req-ssrch-001`
- **files**: `src/views/Chat.vue`, `src/api/chat.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Active tab WHEN a person types a term THEN results replace the groups, each with its excerpt, and clearing the field brings the groups back
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/session-search-and-bulk.spec.ts`)

### Task 3: One deletion routine for single and bulk delete
- **spec_ref**: `openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-deleting-a-session-removes-everything-that-belongs-to-it-req-ssrch-003`
- **files**: `lib/Controller/SessionController.php`
- **acceptance_criteria**:
  - GIVEN a session with feedback WHEN it is deleted through `/permanent` THEN its feedback, turns and the session are gone
- [ ] Implement
- [ ] Test (PHPUnit on `destroy()` and `destroyPermanent()` asserting the feedback delete)

### Task 4: The bulk route
- **spec_ref**: `openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002`
- **files**: `appinfo/routes.php`, `lib/Controller/SessionController.php`
- **acceptance_criteria**:
  - GIVEN three uuids of which one is foreign WHEN action `delete` is posted THEN two are deleted and the third is reported `not_found`
  - GIVEN 101 uuids WHEN posted THEN the answer is 400 and nothing changes
- [ ] Implement
- [ ] Test (PHPUnit; Newman for each action)

### Task 5: Selection mode and the bulk bar
- **spec_ref**: `openspec/changes/chat-session-search-and-bulk-delete/specs/session-surface/spec.md#requirement-a-person-can-archive-restore-or-delete-several-sessions-in-one-action-req-ssrch-002`
- **files**: `src/views/Chat.vue`, `src/modals/SessionBulkDeleteModal.vue`, `src/api/chat.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN selection mode on the Archive tab WHEN a person ticks rows and confirms Delete THEN the rows leave the list and the modal named the count
  - GIVEN the open session is archived in bulk WHEN the action completes THEN the thread column shows the start surface
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/session-search-and-bulk.spec.ts`, keyboard selection included)

## Verification
- [ ] `openspec validate chat-session-search-and-bulk-delete --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: search a word from last week's session and bulk archive three sessions
