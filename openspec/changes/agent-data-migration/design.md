# Design: agent-data-migration

## Context

Hermiq's established pattern for populating OpenRegister with data at install/upgrade time is the
repair step (`lib/Repair/`), used today for `hermiq_register.json` import
(`ConfigurationService::importFromApp()`). This change extends that pattern to a one-time (but
idempotent, re-runnable) cross-app data copy: OR's four live QBMapper tables →
`hermiq`-register objects declared in `agent-engine-schemas`.

This is **spec 4 of 5 hermiq/openregister/nextcloud-vue changes** in the agent-core-port program
(see `agent-engine-schemas/proposal.md` chain diagram). It is the step where the "int-FK → uuid ref"
decision made declaratively in `agent-engine-schemas` becomes real data: every `Conversation
.agentId`, `Message.conversationId`, and `Feedback.{messageId,conversationId,agentId}` gets resolved
from an OR auto-increment integer to the corresponding object's uuid during the copy.

## Goals / Non-Goals

**Goals:**
- Copy every row in `openregister_agents`, `openregister_conversations`, `openregister_messages`,
  `openregister_feedback` into equivalent `hermiq`-register objects, preserving uuid identity and
  `owner`/`organisation` tenancy.
- Resolve every integer FK to the referenced object's uuid during the copy (the one field-shape
  change this migration performs; agent-engine-schemas already declared the target fields as uuid
  strings, so this is a straight lookup-and-write, not a live re-modeling).
- Preserve `Message.context` (the AI Chat Companion snapshot) byte-for-byte as JSON.
- Be idempotent: safe to run on every repair-step invocation, a no-op on already-migrated records.

**Non-Goals:**
- Migrating `openregister_chat_history` — confirmed dead code, not migrated (see proposal.md).
- Dropping OR's tables — deferred to a future, not-yet-specified removal change (plan §7.4 step 7).
- Any change to the engine's read/write behavior — that is `agent-engine-port`, already merged by
  the time this change builds.
- Backfilling data for organisations that never used the OR agent/chat feature at all — the
  migration only copies what exists; it does not create default agents/conversations.

## Decisions

**Uuid preserved, not regenerated.** OR's `Agent` entity already has a `uuid` column
(`protected ?string $uuid = null` in `lib/Db/Agent.php`); the migrated `hermiq`-register `Agent`
object reuses that exact uuid as its own identity. This means any external reference to an agent by
uuid (e.g. a `Schedule.agentId` value already pointing at that uuid — `Schedule.agentId` in
`hermiq_register.json` was already declared as a uuid reference to "the OpenRegister Agent entity",
per the existing `Schedule` schema read during ground-truth verification) continues to resolve
correctly after migration with **zero changes to existing `Schedule` objects** — the reference target
moves from "an OR Agent row with this uuid" to "a hermiq Agent object with this uuid", transparently.

**Owner/organisation come from the source row, applied as `ObjectEntity` fields, not schema
properties.** OR's QBMapper `Agent`/`Conversation`/`Feedback` rows carry their own `owner`/
`organisation` columns (a QBMapper-era pattern). The migration reads those values and sets them on
the new object via `ObjectService`'s standard object-creation path (which accepts owner/organisation
at write time, the same way any other OR object is created on behalf of a specific user/org) —
consistent with `agent-engine-schemas`'s decision not to declare them as schema properties.

**Batched, paginated copy — volume unknown, so the mechanism must not assume small tables.** No
production row-count data was available at spec time (querying a live instance is out of scope for
this artifact-only pass). The repair step MUST paginate its reads from each QBMapper (`AgentMapper`/
`ConversationMapper`/`MessageMapper`/`FeedbackMapper` already support `findAll()` with limit/offset)
rather than loading a full table into memory, so it degrades gracefully whether a given install has
ten conversations or ten thousand.

**Skip-and-log on unresolvable FK, never fail the whole repair step.** A `Feedback` or `Message` row
whose parent FK doesn't resolve to a migrated object (dangling reference, pre-existing data quality
issue OR never enforced) is logged and skipped, not treated as a fatal repair-step error — one bad
row must not block every other row's migration or block the app upgrade itself.

**Idempotency via existence check, not a migration-run marker.** Rather than a one-time "have I run
before" flag (which would need its own state and wouldn't handle partial-failure resume cleanly),
each record's migration checks whether a hermiq-register object with the source uuid already exists
before writing — a natural per-record idempotency check that also makes partial failures trivially
resumable (re-running the repair step only touches the records that didn't make it last time).

## Declarative vs imperative

This entire change is imperative (a one-time data-shape copy is exactly the kind of work ADR-031
does not ask to be declarative — it is not a derived field, aggregation, or lifecycle rule on an
object, it is a migration procedure). No schema changes accompany this spec; `agent-engine-schemas`
already declared the target shape.

## Seed Data (ADR-001)

Illustrative before/after for one conversation (uuids/placeholders per convention). Before
(OpenRegister QBMapper rows, `openregister_conversations` / `openregister_messages`):

```json
// openregister_conversations row (id=42, agentId=7 — an integer FK)
{ "id": 42, "uuid": "11111111-1111-1111-1111-111111111111", "agentId": 7, "title": "..." }
// openregister_agents row (id=7)
{ "id": 7, "uuid": "22222222-2222-2222-2222-222222222222", "name": "Permit drafting assistant" }
```

After migration (hermiq-register objects, `agentId` resolved to the Agent's uuid):

```json
// Agent object, uuid preserved from the OR row
{ "uuid": "22222222-2222-2222-2222-222222222222", "name": "Permit drafting assistant" }
// Conversation object, uuid preserved; agentId now the Agent's uuid, not the integer 7
{ "uuid": "11111111-1111-1111-1111-111111111111", "agentId": "22222222-2222-2222-2222-222222222222", "title": "..." }
```

## Risks / Trade-offs

- **Cross-app read dependency on OR's mappers is real but bounded and temporary.** This change
  reads `AgentMapper`/`ConversationMapper`/`MessageMapper`/`FeedbackMapper` directly — the same class
  of coupling `ScheduleService` already carries today. Accepted because it is a one-time (per-
  install), clearly-scoped data copy rather than a standing runtime dependency; once OR's tables are
  eventually dropped (future change), this repair step's read path stops mattering and can be
  deleted alongside it.
- **Volume/performance is unverified against real data.** Mitigated by batched/paginated reads
  (Decisions) but the actual runtime on a large install is unknown until run; flagged in
  `DEFERRED_QUESTIONS` item 2 rather than guessed at.
- **Dangling FK data quality issues pre-date this migration.** The skip-and-log decision surfaces
  them without blocking the migration, but does not repair the underlying data quality gap in OR —
  that is out of scope for this change.
- **Two systems of record exist simultaneously during the compat window.** Until OR's tables are
  dropped (future change) and `or-chat-proxy-deprecation` (Appendix C, narrated only) is live, both
  OR's original rows and Hermiq's migrated copies exist. This migration does not attempt to keep
  them in sync going forward — it is a one-time copy forward, not a bidirectional replication. Any
  write that happens on the OR side after this migration runs (e.g. via the not-yet-flipped engine
  path) will not be reflected back; this is acceptable because the feature flag from
  `agent-engine-port` ensures only one engine is live at a time for any given install.
