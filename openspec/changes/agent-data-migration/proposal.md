---
kind: code
depends_on: [agent-engine-port]
chain:
  - agent-engine-schemas  # hermiq, config
  - agent-engine-port     # hermiq, code
  - agent-data-migration  # this spec (hermiq, code)
---

# Proposal: agent-data-migration

## Why

This is **spec 4 of the agent-core-port chain** (see `agent-engine-schemas/proposal.md` for the full
chain). `agent-engine-port` makes the in-app engine and its OR-object storage available behind a
feature flag, but every existing agent/conversation/message/feedback lives in OpenRegister's
QBMapper tables. Before the feature flag can be flipped on for real customers, their existing data
must exist in the `hermiq` register's `Agent`/`Conversation`/`Message`/`Feedback` objects — otherwise
flipping the flag silently orphans every pre-existing conversation.

This change is a **repair step** (Hermiq's established pattern for data population against live
OpenRegister — see `reference_or-register-import-via-repair-step.md`), not a one-off migration
script run outside the app lifecycle. It runs idempotently on every app upgrade/repair, copying
records that don't yet have a corresponding hermiq-register object and skipping ones that do.

## What Changes

- A new `Repair\` step (mirrors `lib/Repair/` conventions already used for `hermiq_register.json`
  import) that, for each organisation-scoped or global OR record:
  - Reads OR's `openregister_agents` rows via `AgentMapper` and writes an equivalent `Agent` object
    into the `hermiq` register via `ObjectService`, **preserving the original uuid** (OR's `Agent`
    entity already has a `uuid` column — the migration reuses it as the new object's identity, not a
    freshly generated one) plus `owner`/`organisation` (read from the OR row, written as
    `ObjectEntity.owner`/`.organisation` on the new object, not as schema properties — per
    `agent-engine-schemas`'s design).
  - Same pattern for `openregister_conversations` → `Conversation` objects, with the added step of
    **resolving `Conversation.agentId` from an integer FK to a uuid reference**: look up the source
    `Agent` row's `uuid` by its integer id and write that uuid into the new `Conversation.agentId`
    string field (this is the one field-shape change the migration performs, per plan §7.4 step 4
    and `agent-engine-schemas`'s decision to declare `agentId` as uuid from day one).
  - Same pattern for `openregister_messages` → `Message` objects, resolving `conversationId`
    (int → uuid) the same way, and copying `Message.context` (JSON) verbatim — no reshaping.
  - Same pattern for `openregister_feedback` → `Feedback` objects, resolving `messageId`,
    `conversationId`, and `agentId` (all int → uuid) the same way.
  - `openregister_chat_history` is explicitly **NOT migrated** — confirmed dead code at HEAD (see
    `agent-engine-schemas/design.md` "Adaptations vs plan §7"); the plan's original "7 tables"
    estimate is corrected to 4 live tables here too.
- OR's tables are **not dropped** by this change — plan §7.4 step 4 explicitly defers table removal
  "one release later"; this change only copies forward. Table removal is scoped to the not-yet-
  specified future removal change (plan §7.4 step 7), alongside OR's runtime code deletion.
- The migration is **idempotent and re-runnable**: a second run against already-migrated data must
  be a no-op (skip records whose uuid already exists as a hermiq-register object), so it is safe to
  run on every repair-step invocation, not just once at upgrade time.

### MCP coverage

No MCP surface — this change is a one-time (per-record) data-shape migration with no new user-
facing action or controller endpoint (ADR-035 Decision 2, answer 3).

## Impact

- Affected specs: NEW `agent-data-migration` capability (the repair step + its idempotency and
  field-mapping guarantees).
- Affected code (new): `lib/Repair/MigrateAgentEngineDataRepairStep.php` (or equivalent naming
  matching existing `lib/Repair/` conventions), reading via OR's `AgentMapper`/`ConversationMapper`/
  `MessageMapper`/`FeedbackMapper` (cross-app read dependency — same class of dependency
  `ScheduleService` already has on these mappers today; this change does not introduce a new kind of
  coupling, it uses an existing one for a bounded, one-time purpose).
- Depends on: `agent-engine-port` (this repo) — the target schemas and the engine that will read
  the migrated objects must exist and be validated first.
- Downstream: once this migration has run in production and the feature flag (from
  `agent-engine-port`) is flipped on, `or-chat-proxy-deprecation` (openregister, narrated in
  `agent-engine-schemas/design.md` Appendix C) can safely proxy remaining traffic, since the data it
  would otherwise still be serving from OR's tables now also exists in Hermiq's.

## DEFERRED_QUESTIONS

1. **Migration trigger timing.** Should this repair step run automatically on every Hermiq app
   upgrade once merged (standard NC repair-step behavior), or should it be gated behind the same
   `hermiq.engine.enabled` flag `agent-engine-port` introduces, so data isn't copied until an
   operator has opted into the new engine? This change's tasks.md defaults to "runs on every repair
   regardless of the flag" (matching how OR's own register-import repair steps behave — see
   `reference_or-register-import-via-repair-step.md`), on the reasoning that having the data
   present early is strictly safer than a late one-shot migration racing the flag flip, but this is
   a judgment call left for the reviewer to confirm.
2. **Bulk-copy volume/performance.** No traffic/volume figures for existing `openregister_messages`
   row counts were available at spec time (would require querying a live production instance, not
   HEAD source). The tasks.md scopes batched/paginated copying as a requirement but does not
   prescribe a batch size — left to implementation, tuned against real row counts if/when available.
3. **What happens to `Feedback` rows whose `messageId`/`conversationId`/`agentId` FK is already
   dangling in OR** (e.g. a deleted conversation whose messages were cleaned up but feedback
   remained)? OR does not enforce referential integrity on these QBMapper FKs today, so dangling
   rows are a plausible pre-existing state. This change's tasks.md requires the migration to skip
   (log + continue) rather than fail on an unresolvable FK, but the exact log/reporting shape is
   deferred to implementation.

## Decisions (Ruben, 2026-07-06)

- Migration trigger: **auto repair-step on upgrade, gated on the engine feature
  flag**; idempotent (skips already-migrated uuids). Dangling references are
  nulled, logged, and counted in the repair output (no abort).
