# Tasks: agent-data-migration

## 1. Build the repair step

- [ ] 1.1 Add `lib/Repair/MigrateAgentEngineDataRepairStep.php` (or matching existing `lib/Repair/`
  naming conventions), registered in `lib/AppInfo/Application.php` alongside the existing register-
  import repair step.
- [ ] 1.2 Inject OR's `AgentMapper`/`ConversationMapper`/`MessageMapper`/`FeedbackMapper` (read-only
  use) and `ObjectService` (writes into the `hermiq` register).

## 2. Migrate Agents

- [ ] 2.1 Paginate `AgentMapper::findAll()`; for each row, write an `Agent` object preserving the
  source `uuid` and setting `owner`/`organisation` from the row (via `ObjectService`, not as schema
  properties).
- [ ] 2.2 Skip (existence check by uuid) any `Agent` already migrated — idempotent re-run.

## 3. Migrate Conversations (resolve agentId int → uuid)

- [ ] 3.1 Paginate `ConversationMapper::findAll()`; for each row, look up the source `Agent`'s uuid
  by its integer id and write a `Conversation` object with `agentId` set to that uuid (not the
  integer), preserving the conversation's own uuid and `owner`/`organisation`.
- [ ] 3.2 Skip (log + continue, do not fail the repair step) any row whose `agentId` does not
  resolve to an already-migrated `Agent`.

## 4. Migrate Messages (resolve conversationId int → uuid; preserve context)

- [ ] 4.1 Paginate `MessageMapper::findAll()`; for each row, resolve `conversationId` to the
  migrated `Conversation`'s uuid and write a `Message` object, copying `content`, `role`, `sources`,
  and `context` (JSON) verbatim.
- [ ] 4.2 Skip (log + continue) any row whose `conversationId` does not resolve.

## 5. Migrate Feedback (resolve messageId/conversationId/agentId int → uuid)

- [ ] 5.1 Paginate `FeedbackMapper::findAll()`; for each row, resolve all three FKs to their
  migrated uuids and write a `Feedback` object, preserving `userId`, `type`, `comment`.
- [ ] 5.2 Skip (log + continue) any row with an unresolvable FK.

## 6. Confirm chat_history is correctly excluded

- [ ] 6.1 Re-confirm (re-grep at build time, not just trust this spec) that
  `openregister_chat_history` has zero live callers before finalizing the "not migrated" decision —
  if a caller has since been added, escalate rather than silently drop data.

## 7. Idempotency and verification

- [ ] 7.1 Run the repair step twice in a row against the same test data; assert the second run
  performs zero additional writes (pure idempotency check).
- [ ] 7.2 Verify a `Schedule.agentId` value pointing at a pre-migration `Agent` uuid still resolves
  correctly post-migration with no `Schedule` object changes required.
- [ ] 7.3 Verify `Message.context` round-trips byte-for-byte (JSON deep-equal, not just presence).

## 8. Quality gates

- [ ] 8.1 Run the full Hydra mechanical gate suite (spdx-headers, forbidden-patterns, stub-scan,
  spec-coverage) on the new repair-step class before requesting review.
- [ ] 8.2 Composer audit + PHPCS/PHPMD/Psalm/PHPStan clean.

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
