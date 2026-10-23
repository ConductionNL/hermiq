# Tasks: chat-fork-and-share-link

Kind: code. Size M. Rows `hermiq:dm-fork-chat`, `ch-share-link`.

## Implementation tasks

### Task 1: Measure OpenRegister's read grant on sessions first
- **spec_ref**: `openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002`
- **files**: `tests/integration/hermiq.postman_collection.json`
- **acceptance_criteria**:
  - GIVEN a session owned by user A WHEN user B of the same organisation reads it through `/apps/openregister/api/objects/hermiq/agentsession/<uuid>` THEN the result is recorded; a 200 stops this change until the schema grant is narrowed
- [ ] Implement
- [ ] Test (Newman, two users, result written into the PR body)

### Task 2: Fork a session at an answer
- **spec_ref**: `openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/routes.php`, `lib/Controller/SessionController.php`, `lib/Service/SessionForkService.php`
- **acceptance_criteria**:
  - GIVEN an owner and an assistant turn WHEN they fork THEN a new session holds copies up to that turn with `forkedFrom`, and the source is untouched
  - GIVEN a user turn or a foreign session WHEN a fork is requested THEN the answer is 400 or 404 and nothing is created
- [ ] Implement
- [ ] Test (PHPUnit on the service including full-payload saves; Newman for the three outcomes)

### Task 3: "Fork from here" on the Chat page
- **spec_ref**: `openspec/changes/chat-fork-and-share-link/specs/session-fork/spec.md#requirement-the-owner-can-fork-a-session-at-an-answer-req-sfork-001`
- **files**: `src/views/Chat.vue`, `src/api/chat.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a ten-turn session WHEN the owner forks at the fourth answer THEN the new session opens with four turns and the list shows both
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/session-fork-and-share.spec.ts`)

### Task 4: The share list and its routes
- **spec_ref**: `openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/routes.php`, `lib/Controller/SessionShareController.php`, `lib/Service/SessionShareService.php`
- **acceptance_criteria**:
  - GIVEN the owner WHEN they add a user and a group THEN `sharedWith` holds both and the audit trail shows the save
  - GIVEN "share only with group members" WHEN the owner adds an outsider THEN the share is refused
  - GIVEN a non-owner WHEN they call any share route THEN the answer is 404
- [ ] Implement
- [ ] Test (PHPUnit with a fake `IManager`; Newman for owner and non-owner)

### Task 5: Read access for recipients, nothing more
- **spec_ref**: `openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002`
- **files**: `lib/Controller/SessionController.php`, `lib/Service/SessionShareService.php`
- **acceptance_criteria**:
  - GIVEN a user in a shared group WHEN they read the session and its turns THEN both answer 200, and rename, archive, delete, fork and send are refused
- [ ] Implement
- [ ] Test (PHPUnit per method; Newman as a recipient)

### Task 6: Share dialog, notification and the shared view
- **spec_ref**: `openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-revoke-a-share-at-once-req-sshare-003`
- **files**: `src/modals/SessionShareModal.vue`, `src/views/SharedSession.vue`, `src/views/Chat.vue`, `src/manifest.json`, `lib/Notification/Notifier.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a new share WHEN it is saved THEN the recipient gets the `session_shared` notification linking to `/chat/shared/<uuid>`
  - GIVEN a revoked share WHEN the former recipient opens the link THEN they see "This session is not available"
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/session-fork-and-share.spec.ts` with two users)

## Verification
- [ ] `openspec validate chat-fork-and-share-link --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: share a session with a second user, read it as that user, revoke, read again
