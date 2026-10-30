# Tasks: chat-work-together-in-one-session

Kind: code. Size M. Row `hermiq:ch-shared-session`.

### Task 1: The owner manages the participant list
- **spec_ref**: `openspec/changes/chat-work-together-in-one-session/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001`
- **files**: `appinfo/routes.php`, `lib/Controller/SessionParticipantController.php`, `lib/Service/SessionParticipantService.php`, `lib/Notification/Notifier.php`
- [x] Implement
- [x] Test (PHPUnit: owner adds and removes, non-owner 404, Talk-bound 409, owner or unknown uid 400; the saved payload validated against the real `agentsession` schema fragment)

### Task 2: A participant opens and reads the session
- **spec_ref**: `openspec/changes/chat-work-together-in-one-session/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002`
- **files**: `lib/Controller/SessionController.php`
- [x] Implement
- [x] Test (PHPUnit: participant sees the session in the list with role participant, reads show and messages; a stranger gets 403; a participant's rename or delete is refused)

### Task 3: Invite colleagues and see who asked on /chat
- **spec_ref**: `openspec/changes/chat-work-together-in-one-session/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001`
- **files**: `src/modals/SessionParticipantsModal.vue`, `src/views/Chat.vue`, `src/api/chat.js`, `l10n/en.json`, `l10n/nl.json`
- [x] Implement
- [x] Test (vitest on the modal where the repo has it; lint and l10n checks)
