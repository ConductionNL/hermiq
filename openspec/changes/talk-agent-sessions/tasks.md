# Tasks: talk-agent-sessions

Depends on `talk-chat-bridge`, `talk-room-grouping` and `talk-approval-reactions` — all archived
and live-verified. `Conversation.talkRoomToken` and `Conversation.participants` **already exist**
(shipped by `talk-chat-bridge-schema`, register `info.version` 0.21.0); do not re-add them.

## Implementation Tasks

### Task 1: Add `Conversation.talkRoomOrigin` and force-import the register

- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room`
- **files**: `lib/Settings/hermiq_register.json`, `migration.md`
- **acceptance_criteria**:
  - GIVEN the register file WHEN `talkRoomOrigin` is added THEN it is OPTIONAL, absent from `required`, and carries no `if`/`then`/`allOf` block — the OpenRegister importer rejects conditional blocks
  - GIVEN the edit WHEN it lands THEN `Conversation.version` is `0.2.0` and `info.version` is `0.22.0` with the changelog line appended to `info.description`
  - GIVEN the import WHEN it runs THEN it uses `force: true` — `importFromApp(force: false)` advances the version WITHOUT applying the schema and still reports success
  - GIVEN the import completed WHEN the live register is read back THEN `talkRoomOrigin` is present on the `conversation` schema; a version bump alone is not evidence
  - GIVEN a `Conversation` saved without `talkRoomOrigin` WHEN it is validated THEN it still validates
  - Seed data per design.md: seeded conversations must exercise BOTH `created` and `bound`, or the created-room rule ships undemonstrated on a fresh install
- [ ] Implement
- [ ] Test

### Task 2: Per-agent bot identity — retire `TalkBridge::BOT_URL` as a constant

- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity`
- **files**: `lib/Service/Talk/TalkBridge.php`, `lib/Service/Talk/TalkBotInstaller.php`, `lib/Service/Talk/TalkBridgeStatus.php`
- **acceptance_criteria**:
  - GIVEN an agent id WHEN the bot URL is derived THEN it is `nextcloudapp://hermiq-<agentId>` and `botActorId($agentId)` is `bot-` + `sha1` of that URL, matching the `url_hash` spreed stores
  - GIVEN `BOT_URL`/`BOT_NAME` WHEN this task lands THEN neither is used as an identity constant; `isHermiqBotUrl()` and `agentIdFromBotUrl()` replace them
  - GIVEN `postToRoomReturningId()` WHEN called THEN it posts under the agent's actor id; every current caller has an agent in hand
  - PROVE, do not assume: confirm live that a bot record can be installed AND renamed programmatically rather than by shelling out to `occ talk:bot:install`. Source reading says `BotInstallEvent` is an upsert keyed on (url, secret) that calls `setName()`/`update()` on a hit, and `BotService` exposes no install/rename method — verify that against the running instance and record the result
  - GIVEN the upsert key WHEN a rename is dispatched THEN the per-agent secret must be identical to the install secret, or spreed rejects it as "same URL, different secret"
- [ ] Implement
- [ ] Test

### Task 3: Bind the bot lifecycle to the agent lifecycle

- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle`
- **files**: `lib/Service/Talk/TalkBotInstaller.php`, `lib/Service/AgentService.php`, `lib/Controller/AgentController.php`
- **acceptance_criteria**:
  - GIVEN an agent WHEN it becomes `talkEnabled` THEN its bot is installed; WHEN it is renamed THEN the bot record's name follows and no second bot appears; WHEN it is Talk-disabled or deleted THEN its bot is uninstalled
  - GIVEN spreed absent WHEN any of these fire THEN nothing is attempted and no error surfaces
  - PROVE, do not assume: confirm whether renaming a bot rewrites ALREADY-POSTED message history. Source reading says `MessageParser` resolves the display name at render time from the bot record via `url_hash`, so it probably does. Confirm live and state the consequence in the docs task — an agent rename retroactively re-signs its past messages
  - GIVEN the confirmed answer WHEN it is recorded THEN also record the uninstall consequence: the `url_hash` lookup is scoped to the conversation, so a bot no longer in the room renders as `<actorId>-bot`
- [ ] Implement
- [ ] Test

### Task 4: Rework the bot-URL guard in BOTH listeners

- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-hermiq-recognises-any-of-its-own-bot-urls-and-resolves-the-agent-from-it`
- **files**: `lib/Listener/TalkBotInvokeListener.php`, `lib/Listener/TalkApprovalReactionListener.php`
- **acceptance_criteria**:
  - GIVEN `readPayload()` in EACH listener WHEN it guards THEN it accepts any Hermiq per-agent bot URL and resolves the agent from it, instead of comparing to one constant
  - GIVEN a URL that is not `nextcloudapp://hermiq-<agentId>` WHEN it arrives THEN `agentIdFromBotUrl()` returns null and neither listener acts
  - GIVEN the approval path WHEN the guard loosens THEN nothing downstream loosens: the approval is still resolved by recorded message id and the reviewer check is untouched
  - This is the highest-risk edit in the change — flag it for security review, and see Task 7 before calling it done
- [ ] Implement
- [ ] Test

### Task 5: The session owns its room — create, name, rename, participants

- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room`
- **files**: `lib/Service/Talk/TalkSessionRoom.php`, `lib/Service/Talk/TalkRoomBinding.php`, `lib/Controller/ConversationController.php`, `lib/Controller/ChatController.php`
- **acceptance_criteria**:
  - GIVEN a session created for a Talk-enabled agent WHEN it is saved THEN a room named after the session exists, the owner is a participant, the agent's bot is enabled in it, and the session carries `talkRoomToken` plus `talkRoomOrigin: "created"`
  - GIVEN a Talk-disabled agent WHEN a session is created THEN no room is created
  - GIVEN a session that owns a room WHEN its title changes THEN the room is renamed; GIVEN a session merely bound to somebody else's room THEN the room is NOT renamed
  - GIVEN any room or rename failure WHEN it occurs THEN the session is still created/renamed and usable from Hermiq's own UI; the failure is logged
  - Reuse `DeliveryService::resolveDefaultRoom()`'s `RoomService::createConversation()` path; do not add a second room-creation route. Note `saveObject` is PUT-semantic — carry every field forward
- [ ] Implement
- [ ] Test

### Task 6: Addressing rule and roster sync

- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room`
- **files**: `lib/Listener/TalkBotInvokeListener.php`, `lib/Service/Talk/TalkSessionRoom.php`, `lib/Service/Talk/TalkRoomGrouping.php`
- **acceptance_criteria**:
  - GIVEN `talkRoomOrigin === "created"` WHEN any human message arrives THEN a turn is taken; otherwise the mention gate applies unchanged, including the one-to-one and reply-to-the-agent paths
  - GIVEN the origin WHEN it is evaluated THEN it is READ from stored data, never inferred from the room's current participants or type
  - GIVEN an agent name WHEN matching a mention THEN matching is on the DECODED text (`object.content` is a JSON envelope, not plain text) and tolerates multi-word names, case differences and trailing punctuation; a non-match is a silent no-turn, never an error
  - GIVEN a room participant added at creation or later WHEN synced THEN they appear in `Conversation.participants`, excluding bots and the owner, and the room is filed under that user's Hermiq tag honouring the `talk_group_rooms` opt-out
  - GIVEN authorization WHEN it runs THEN it still reads the STORED roster, never live Talk room membership
- [ ] Implement
- [ ] Test

### Task 7: Live regression guard on the approval-reaction path

- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-hermiq-recognises-any-of-its-own-bot-urls-and-resolves-the-agent-from-it`
- **files**: `docs/`
- **acceptance_criteria**:
  - The bot-URL guard rework sits on a path that was 100% INERT until PR #75 and is provable only by live checks. Unit tests alone do NOT close this task
  - Re-run live after the guard change: (i) an approval posted to a room records its room token AND message id
  - Re-run live after the guard change: (ii) the reviewer's 👍 approves and 👎 denies, each recording `decidedVia=reaction`
  - Re-run live after the guard change: (iii) a NON-reviewer's 👍 leaves the approval pending with no decision recorded
  - Also live: two agents in two rooms each post under their own name; a message in a created room is answered unmentioned; the same message in an invited-into room is ignored; rename an agent and read a PAST message back to confirm the history consequence from Task 3
  - Record the observed results in this change's verification section — a green suite is not evidence for this path
- [ ] Implement
- [ ] Test

### Task 8: Playwright e2e coverage under `tests/e2e/spec-coverage/`

- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-room-grouping/spec.md#requirement-a-room-hermiq-creates-for-a-session-is-filed-at-creation`
- **files**: `tests/e2e/spec-coverage/talk-agent-sessions.spec.ts`, `tests/e2e/spec-coverage/_fixtures.ts`
- **acceptance_criteria**:
  - GIVEN the suite WHEN it seeds THEN it uses `TEST_PREFIX` and `cleanupFamily` from `_fixtures.ts` following the existing conventions in `chat.spec.ts` and `agents-approvals.spec.ts`
  - GIVEN the first-run tour WHEN a spec starts THEN a shared `dismissTour` helper hoisted INTO `_fixtures.ts` cancels the `cn-wizard-dialog` by clicking its Cancel button — it does NOT close on Escape; the three duplicated private copies are replaced by the hoisted one
  - GIVEN console-error collection WHEN it runs THEN it is scoped to hermiq's own bundle and ignores other apps' `/custom_apps/<app>/` scripts
  - GIVEN every scenario in this change's specs carrying an `@e2e` annotation WHEN gate-19 runs THEN each is referenced by this spec file; scenarios needing Talk's own client carry `@e2e exclude` and are covered by Task 7 instead
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests — this change adds none
- UI changes covered by Playwright browser tests (Task 8)
- All tests pass (`composer test`, `composer check:strict`, `npx playwright test`)
- Feature documentation updated in `docs/`: per-agent bot naming, the `(Bot)` suffix and `>_` glyph
  being unavoidable, the created-room-answers-everything rule, and the agent-rename-re-signs-history
  consequence from Task 3
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- Hydra gates apply: `@spec` traceability on changed methods, `@e2e` on added/modified scenarios,
  SPDX headers on new PHP files, no stubs
- Do not use sed/awk/scripts to modify code — use the Edit tool
- Fix pre-existing quality issues in the files you touch rather than leaving them
- spreed classes stay lazily resolved by FQCN string, never type-hinted, so Hermiq still boots with
  Talk absent
- `openspec validate talk-agent-sessions --type change --strict` passes
