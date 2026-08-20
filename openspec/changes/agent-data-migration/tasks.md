# Tasks: agent-data-migration

## 1. Build the repair step

- [x] 1.1 Add `lib/Repair/MigrateAgentData.php`, registered in `appinfo/info.xml` `<repair-steps>`
  `<post-migration>` alongside the existing `InitializeSettings`/`InitializeActions`/`SeedAiFeatures`
  steps. (Hermiq registers repair steps in `info.xml`, not `Application.php` — the tasks.md
  assumption of `Application.php` does not match hermiq's actual convention; followed the real one.)
- [x] 1.2 Read the four OR tables and write via `ObjectService` (lazily resolved through the
  container, so the step no-ops when OR is absent). DEVIATION (Decisions, brief 2026-07-06): reads go
  through `IDBConnection` with a `tableExists()` guard rather than injecting OR's
  `AgentMapper`/`ConversationMapper`/`MessageMapper`/`FeedbackMapper` — a raw read needs no hard DI on
  OR mapper classes, so the repair step still constructs when OR is not installed, and the guard
  handles fresh installs where the tables do not yet exist.

## 2. Migrate Agents

- [x] 2.1 Paginate the `openregister_agents` read; for each row, write an `Agent` object preserving
  the source `uuid` (via the `uuid:` save param) and setting `owner` (by impersonating the source
  owner during the write — the ScheduleService pattern) / `organisation` (via `@self`) from the row,
  never as schema properties.
- [x] 2.2 Skip (existence check by uuid via `ObjectService::find`) any `Agent` already migrated —
  idempotent re-run.

## 3. Migrate Conversations (resolve agentId int → uuid)

- [x] 3.1 Paginate the `openregister_conversations` read; for each row, look up the source `Agent`'s
  uuid by its integer id (from the id→uuid map built in the agents pass) and write a `Conversation`
  object with `agentId` set to that uuid (not the integer), preserving the conversation's own uuid
  and `owner`/`organisation`.
- [x] 3.2 A row whose `agentId` does not resolve is handled per the Decision (Ruben 2026-07-06):
  `agentId` is **nulled**, logged, and counted in the repair output — the row is still migrated (this
  supersedes the original "skip the row" wording; it still logs + continues, never fails the step).

## 4. Migrate Messages (resolve conversationId int → uuid; preserve context)

- [x] 4.1 Paginate the `openregister_messages` read; for each row, resolve `conversationId` to the
  migrated `Conversation`'s uuid and write a `Message` object, copying `content`, `role`, `sources`,
  and `context` (JSON, decoded so the object holds the structured value) verbatim.
- [x] 4.2 A row whose `conversationId` does not resolve is nulled + logged + counted (continue, do
  not fail — per the Decision).

## 5. Migrate Feedback (resolve messageId/conversationId/agentId int → uuid)

- [x] 5.1 Paginate the `openregister_feedback` read; for each row, resolve all three FKs
  (`messageId`/`conversationId`/`agentId`) to their migrated uuids and write a `Feedback` object,
  preserving `userId`, `type`, `comment`.
- [x] 5.2 Each unresolvable FK is nulled + logged + counted (continue, do not fail — per the
  Decision).

## 6. Confirm chat_history is correctly excluded

- [x] 6.1 Re-confirmed at build time (2026-07-06): `grep` over OpenRegister `lib/` shows
  `chat_history` referenced **only** by the table-creating migration
  (`Version002004000Date20251013000000.php`); there is no `ChatHistory` entity, no `ChatHistoryMapper`,
  and zero callers. Confirmed dead — correctly excluded from the migration.

## 7. Idempotency and verification

- [x] 7.1 Idempotency proven by unit test (`testAlreadyMigratedRecordIsSkipped`): a row whose uuid
  already exists as a hermiq object (via `ObjectService::find`) is not written again — the same
  per-record existence guard that makes a second full run a no-op.
- [ ] 7.2 DEFERRED — needs a live OR instance with legacy data. Verifying that a real
  `Schedule.agentId` (pointing at a pre-migration `Agent` uuid) still resolves post-migration with no
  `Schedule` changes requires a running OpenRegister holding actual agent/conversation rows; no such
  instance is available in this environment. The uuid-preserving design (source uuid reused verbatim
  as the object identity) makes this hold by construction, but the end-to-end proof is deferred to
  the live-migration verification below.
- [x] 7.3 `Message.context` round-trip covered by unit test (`testMessageContextPreservedExactly`):
  the migrated object's `context` deep-equals the source snapshot (`appId`/`pageKind`/`capturedAt`).

## 8. Quality gates

- [x] 8.1 Hydra mechanical gates run diff-scoped vs `origin/development`: spdx-headers,
  forbidden-patterns, stub-scan, spec-coverage all PASS for the changed files. (One pre-existing,
  unrelated gate-5 route-auth failure exists on `development` — `appinfo/routes.php` routes
  `preferences#{get,set}Preference` at a `PreferencesController` that does not exist in `lib/`, a
  defect from the engine-port PR #13, not touched by this change — flagged for a separate follow-up.)
- [x] 8.2 Composer audit clean (no advisories); PHPCS clean across all of `lib/` (also fixed a
  pre-existing inline-IF phpcs error in `AiFeatureController.php`); Psalm "No errors found"; PHPStan
  (level 5) "No errors"; full unit suite 267/267 green (baseline 260 + 7 new).

## Deferred verification (needs a live OR instance)

- [ ] D.1 LIVE MIGRATION RUN — on a running OpenRegister that holds legacy
  `openregister_{agents,conversations,messages,feedback}` rows: flip `hermiq`.`engine.enabled` on,
  run `occ maintenance:repair` (or an app upgrade), and assert every source row now has a matching
  hermiq object with uuid/owner/organisation preserved, every int FK resolved to a uuid, a second run
  writes nothing, and task 7.2 (Schedule.agentId resolution) holds. No OR instance with legacy data
  exists in this environment, so this end-to-end verification is deferred (not faked). Also validate
  on a real instance that OR honours `@self.organisation` for the impersonated owner and that
  `@self.created` provenance is acceptable (the public `saveObject` create-path hard-sets `created`
  to now — the source timestamp rides in `@self` for forward-compat but is not persisted as `created`
  by OR today).

## Acceptance criteria

- Every live `openregister_{agents,conversations,messages,feedback}` row has a corresponding
  hermiq-register object after the repair step runs, with uuid identity preserved.
- Every int FK (`Conversation.agentId`, `Message.conversationId`, `Feedback.{messageId,
  conversationId,agentId}`) is resolved to the referenced object's uuid in the migrated data.
- `Message.context` is preserved exactly.
- Re-running the repair step is a no-op on already-migrated records.
- `openregister_chat_history` is confirmed dead and excluded; OR's tables are left in place
  (not dropped by this change).

## Quality reminders

- No mock-based fixes — test against real OR QBMapper data (fixtures inserted via the real mappers,
  not stubbed return values), per the project's "no mock-based test fixes" rule.
- This change reads OR internals (mappers) directly — a known, bounded, temporary coupling (design.md
  Risks); do not expand it into a standing dependency elsewhere in this change.
- Use the Edit tool, not sed/awk/scripts, for any PHP authored in this change.
