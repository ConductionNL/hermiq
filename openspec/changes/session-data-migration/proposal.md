---
kind: code
depends_on: [session-schema-declaration]
---

# Proposal: session-data-migration

## Summary

Move hermiq's 282 live `conversation` objects onto the `session` schema declared by the previous spec, preserving uuid, ownership, timestamps, messages and archive state. This is the only spec in the chain that touches data, and the only one that can lose any.

## Motivation

The `session` schema is inert until the objects live under it. Everything after this spec — the API rename, the frontend rename — reads sessions, so this is the gate.

The count is not incidental. 282 objects is enough that "just recreate them" is not an option, and small enough that a per-object verification pass is affordable. Take it.

## Affected Projects

- [ ] Project: `hermiq` — a migration that moves conversation objects to the session schema

## Scope

### In Scope

- Move every `conversation` object in register `hermiq` to the `session` schema.
- Preserve `_uuid`, `_owner`, `_organisation`, `_created`, `_updated`, and the archive marker (`metadata.deletedAt` / `metadata.deletedBy`) exactly. A uuid change breaks every message that references its conversation.
- Include soft-deleted (archived) conversations. An archived session is still a session, and the Archive tab must not empty itself.
- Set the trigger-origin property to `human` on every migrated object — see the previous spec's Risk 3.
- Re-point message objects at the migrated sessions.
- A verification pass that compares source and destination field by field and reports a count, not a "looks fine".

### Out of Scope

- Deleting the `conversation` schema, or deleting the source objects. The migration copies; retiring the old schema is a later, separate decision made once the chain is verified in production.
- Any route, controller, or frontend change.

## Approach

A migration that reads each `conversation` object and writes a `session` object carrying the same uuid and metadata, then re-points messages.

Two properties of the environment shape the design, both measured:

1. **`saveObject` is PUT-semantic.** A partial write silently drops unlisted fields, so the migration must construct the full object, not patch it.
2. **A soft-deleted tombstone holds its uuid forever.** The `_uuid` unique index has no `WHERE _deleted IS NULL`, so writing a session with a uuid that a soft-deleted row already holds fails. If any target uuid collides, `DELETE /api/deleted/{uuid}` is the documented escape hatch — but the migration must detect the collision and report it, never work around it silently.

## New Dependencies

None.

## Impact

- 282 objects gain a second representation. Until the API spec ships, nothing reads it.
- Storage roughly doubles for this schema. At 282 objects that is immaterial and buys a trivially safe rollback.

## Cross-Project Dependencies

Depends on `session-schema-declaration` (same repo, so `depends_on` gates it). Transitively depends on the OpenRegister slug-resolution fix through that spec.

## Risks

### Risk 1: A property present on one schema and absent on the other silently drops
**Severity:** High — **Mitigation:** This is the classic migration failure and it is silent by construction. The verification pass must compare field-by-field per object and fail loudly on any mismatch. Derive the property list from the live schemas, never transcribe it. **Run it against a copy first, and confirm the verifier CAN fail** — deliberately corrupt one migrated object and check the verifier reports exactly that object. A verifier that has never failed is not evidence.

### Risk 2: Archived conversations are missed
**Severity:** High — **Mitigation:** Archived conversations are soft-deleted, and OpenRegister's default reads exclude soft-deleted rows — the very trap measured on 2026-08-13, where `_includeDeleted=true` moved a `total` from 22 to 106 while the result set stayed at 22 live rows. **Do not assume any include-deleted flag works; verify the archived count comes back before relying on it.** If the read path cannot return them, the migration must go through a path that can, and say so.

### Risk 3: Messages are orphaned
**Severity:** High — **Mitigation:** Messages reference their conversation by uuid. Preserving uuid is what keeps them attached, which is why uuid preservation is a hard requirement rather than a nicety. Verify by counting messages per session before and after.

### Risk 4: uuid collision with a tombstone
**Severity:** Low — **Mitigation:** Detect and report; do not auto-purge. A collision means something unexpected exists and a human should look.

## Rollback Strategy

Delete the migrated `session` objects. The `conversation` objects are untouched by design — the migration copies rather than moves — so rollback is a delete of the new copies and nothing is lost. This is the reason the source objects are explicitly out of scope for deletion.

## Open Questions

- Should the migration be re-runnable (idempotent upsert by uuid) or one-shot with a guard? Re-runnable is safer under partial failure. To be settled in design.md.
