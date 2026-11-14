# Tasks: chat-carry-a-conversation-across-channels

Kind: code. Size S to M. Row `hermiq:dm-cross-channel`.

## Implementation tasks

### Task 1: Record the channel of a turn
- **spec_ref**: `openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md#requirement-each-turn-records-where-it-was-typed-req-schan-004`
- **files**: `lib/Settings/hermiq_register.json` (SessionTurn gains optional `channel`, enum `talk|hermiq`; bump `info.version`), `lib/Service/Talk/TalkTurnService.php`, `lib/Service/Engine/MessageHistoryHandler.php`
- **acceptance_criteria**:
  - GIVEN a turn from Talk WHEN it is stored THEN `channel` is `talk`; GIVEN a turn from `/chat` THEN it is `hermiq`
- [ ] Implement
- [ ] Test (PHPUnit on both paths, with the real register fragment validated)

### Task 2: A bound session takes the room's name
- **spec_ref**: `openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md#requirement-a-session-begun-in-talk-can-be-continued-on-the-chat-page-req-schan-001`
- **files**: `lib/Service/Talk/TalkRoomBinding.php` (`createBound()`, `bind()`)
- **acceptance_criteria**:
  - GIVEN a bound room named "Inkoop overleg" WHEN the session is created THEN its title is "Inkoop overleg"
  - GIVEN an owner who renamed the session WHEN the room is renamed THEN the session title stays
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 3: Mirror a Chat-page turn into the room
- **spec_ref**: `openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md#requirement-a-turn-typed-on-the-chat-page-reaches-the-bound-room-req-schan-002`
- **files**: `lib/BackgroundJob/MirrorTurnToTalkJob.php`, `lib/Service/Talk/TalkTurnMirror.php`, `lib/Controller/ChatController.php`, `lib/Controller/ChatStreamController.php`, the bot listener under `lib/Listener/`
- **acceptance_criteria**:
  - GIVEN a session with a room WHEN a turn is sent on `/chat` THEN the job posts the question and the answer as the agent's bot
  - GIVEN a mirrored message WHEN the bot listener sees it THEN no turn is created
  - GIVEN Talk refuses the post WHEN the job runs THEN it logs and the turn stays stored
- [ ] Implement
- [ ] Test (PHPUnit with the real Talk event class; one live round-trip on the dev instance)

### Task 4: The doors on the Chat page and in the room
- **spec_ref**: `openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md#requirement-each-door-points-at-the-other-req-schan-003`
- **files**: `src/views/Chat.vue` (Talk icon, "Open in Talk" menu action, "via Talk" line), `lib/Service/Talk/TalkTurnService.php` (first-answer link), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a session with a room WHEN the list renders THEN it shows the Talk icon and the menu offers "Open in Talk"
  - GIVEN the agent's first answer in a room THEN it ends with the "Continue in Hermiq" link; the second answer does not
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/spec-coverage/chat-carry-a-conversation-across-channels.spec.ts`; PHPUnit on the formatter)

### Task 5: Seed data
- **files**: `lib/Settings/hermiq_register.json` seed section
- [ ] Add the "Inkoop overleg" demo session with mixed channels

## Verification
- [ ] `openspec validate chat-carry-a-conversation-across-channels --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check: begin in a Talk room, continue on `/chat`, see the mirrored turn in the room
