# Migration: talk-agent-sessions

One migration: an OpenRegister schema change. There is no Nextcloud database migration — Hermiq
owns no tables (ADR-001) — and **no data migration**, because Hermiq is unpublished and runs on a
single development instance whose Talk state has been cleared by hand (see Current State).

This file is not skippable under the schema's `skipWhen`: the change does modify an OpenRegister
schema definition. It is deliberately short because the schema edit is genuinely the whole of it.

## Current State

**Schema.** `lib/Settings/hermiq_register.json`, `info.version` `0.21.0`.
`components.schemas.Conversation` is at `version` `0.1.0`, `required: ["agentId"]`, with properties
`title`, `userId`, `agentId`, `metadata`, `talkRoomToken`, `participants`. There is **no**
property recording where the room came from.

**Live data.** The instance has been cleaned ahead of this change: **2 agents** remain —
`Hydra Triage` (`95d4d836-a3ba-4617-bbe8-1c9b7b414742`) and `Hydra Applier — Axel Pliér`
(`b1adfb0f-7d24-439a-bd26-8801e44fa6b6`) — and **3 conversations**, all belonging to those two
agents. 18 agents and 54 conversations of test residue were deleted. There are therefore no
legacy rooms bound to the shared bot left to migrate, and no unresolvable-room case to defend
against.

**Behaviour.** Whatever rooms remain still route through the single `nextcloudapp://hermiq` bot
record until the code in this change installs per-agent bots. That transition is ordinary
application behaviour driven by the agent lifecycle (tasks.md Task 3), not a migration.

## Target State

**Schema.** `info.version` `0.22.0`; `Conversation.version` `0.2.0`; one added OPTIONAL property:

```json
"talkRoomOrigin": {
    "type": "string",
    "title": "Talk room origin",
    "description": "How this conversation came to have a Talk room. 'created' means Hermiq created the room for this session and the agent treats every human message in it as a turn. 'bound' means Hermiq was invited into, or delivered a report into, a room somebody else owns, and the mention gate applies. Unset is treated as 'bound'."
}
```

Not added to `required`; no `if`/`then`/`allOf` block (the OpenRegister importer rejects
conditional blocks).

## Migration Class

None. There is no `OCP\Migration\IMigrationStep` and no `OCP\Migration\IRepairStep` in this change.

The register import is not a class — it is `SettingsService`'s existing register bootstrap invoked
with `importFromApp(force: true)`. **`force: false` advances the stored register version without
applying the schema and still reports success**, which is the specific way this step fails
silently.

## Migration Steps

1. Edit `hermiq_register.json`: add `talkRoomOrigin`, bump `Conversation.version` to `0.2.0`, bump
   `info.version` to `0.22.0`, append the changelog line to `info.description`.
2. Import the register with `force: true`.
3. Read the property back from the live register and assert it is present on the `conversation`
   schema. A version bump alone is not evidence.

Ordering is critical and is why this is written down at all: step 2 must land before any code that
reads `talkRoomOrigin` is deployed. A reader deployed against an unimported schema sees the
property absent on every object, which silently means `bound` — the addressing rule would look
correct while never firing. Task 1 in tasks.md pins this order.

Step 2 is idempotent and safe to re-run.

## Data Impact

- **OpenRegister objects:** zero rewritten. `talkRoomOrigin` is additive and optional; each of the
  3 remaining `Conversation` objects stays valid with the property absent, and absent is
  deliberately the pre-change behaviour (mention gate applies). **No backfill.**
- **spreed rows:** none touched by this migration. Per-agent bot records are installed by the
  agent lifecycle at runtime, not here.
- **Message history:** unaffected by this migration. The history consequences of installing and
  uninstalling bot records are real but belong to the runtime lifecycle — see design.md D5.
- Runs on live data. No lock, no downtime.

## Rollback Procedure

Reverting the code leaves `talkRoomOrigin` declared but unread; objects carrying it stay valid, and
every room falls back to the mention gate, which is the safe default. If the property must be
withdrawn from the register as well, remove it and re-import with `force: true`; existing values
are dropped.

No Talk state is changed by this migration, so there is nothing to unwind on the spreed side.

## Validation

- The `conversation` schema returned by the **live** register contains `talkRoomOrigin` — read it
  back from the register, do not infer it from the file or from the version number.
- A `Conversation` object saved with `talkRoomOrigin: "created"` reads that value back.
- A `Conversation` saved **without** `talkRoomOrigin` still validates.
- The 3 existing `Conversation` objects still load and still behave as `bound`.
