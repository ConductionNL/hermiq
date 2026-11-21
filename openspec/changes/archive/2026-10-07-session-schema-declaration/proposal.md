---
kind: config
depends_on: []
---

# Proposal: session-schema-declaration

## Summary

hermiq calls the same thing three different names. The user-facing surface says "conversation", the OpenRegister schema is `conversation`, parts of the codebase and the agent-memory routes say "session", and the page itself is called "Chat". This change declares the canonical `session` schema in hermiq's schema register and is the head of a four-spec chain that retires the other two words end to end.

This spec is `kind: config` — it edits `lib/Settings/hermiq_register.json` and nothing else. The migration, the API rename and the frontend rename are the three code specs that follow.

## Motivation

Three words for one concept is a tax paid on every read of the codebase and every glance at the UI. A user archives a "conversation" from a page called "Chat" while the backend records a "session". Nobody can tell from a method name which of the three layers they are in.

The direction is settled: **session** is the word. It is the only one of the three that describes the thing accurately — a bounded interaction with an agent, which may be started by a human or by a cron, an event, or a flow. "Chat" is wrong for the automated ones, and "conversation" implies a human on both ends.

## Affected Projects

- [ ] Project: `hermiq` — declares a `session` schema in `lib/Settings/hermiq_register.json`

## Scope

### In Scope

- Declare the `session` schema in hermiq's schema register, carrying every property the current `conversation` schema carries.
- Add the property that lets the Chat page split human from automated sessions — the trigger origin (`human` | `cron` | `event` | `flow`). Today "Active" lumps both together, and no property distinguishes them.
- Keep the `conversation` schema declared and intact. Nothing is removed here; the migration spec moves the data and a later spec retires the old schema.

### Out of Scope

- Moving any of the 282 existing objects — that is `session-data-migration`.
- Routes, controllers, services — that is `session-api-rename`.
- Vue, stores, UI strings — that is `session-frontend-rename`.
- Deleting the `conversation` schema. It stays until the chain completes and the migration is verified; deleting a schema is how you lose 282 objects.

## Approach

Add the `session` schema as a declarative entry in `lib/Settings/hermiq_register.json`, mirroring `conversation`'s properties and adding the trigger-origin property.

The name is available **only because `register-scoped-schema-slug-resolution` lands first** — see Cross-Project Dependencies. Without it, `session` collides with scholiq's schema of the same slug.

## New Dependencies

None.

## Impact

- `lib/Settings/hermiq_register.json` — one added schema.
- No behavioural change on its own. Nothing reads the new schema until the migration spec runs; this spec is safe to merge in isolation and is inert until then.

## Cross-Project Dependencies

**Hard dependency on `openregister` → `register-scoped-schema-slug-resolution`.**

A schema with slug `session` already exists instance-wide: scholiq's, id 1286, "a scheduled occurrence of a Cohort meeting". OpenRegister resolves slugs globally and returns the first match, so declaring `session` before that fix means hermiq's schema may resolve to scholiq's — the exact failure already live for `agent`, where `GET /api/schemas/agent` returns openbuild's 6-property schema instead of hermiq's 36-property one.

That dependency lives in a **different repository**, so Hydra's supervisor cannot gate this spec on it (`depends_on` resolves slugs to issue numbers within one repo). `depends_on` is therefore empty and **the ordering is a human gate**: do not apply this spec until the OpenRegister change is merged AND released.

## Risks

### Risk 1: Applied before the OpenRegister fix ships
**Severity:** High — **Mitigation:** This is the whole reason the chain has a head. The verification step is concrete and must be run before anything else in this spec: with the fix in place, resolving slug `session` in register `hermiq` returns hermiq's schema and not id 1286. If it returns 1286, stop — the fix is not in.

### Risk 2: Property drift between `conversation` and `session`
**Severity:** Medium — **Mitigation:** The migration spec copies objects between the two schemas, so a property present on one and absent on the other silently drops data. The property list must be derived from the live `conversation` schema rather than transcribed by hand, and the migration must assert field-by-field rather than trusting the copy.

### Risk 3: The trigger-origin property has no value for existing objects
**Severity:** Low — **Mitigation:** All 282 existing objects predate the property. The migration spec sets them to `human`, which is what they are — the automated channels did not write conversations. Decide the default here so the migration does not invent one.

## Rollback Strategy

Remove the schema entry from `hermiq_register.json`. Because nothing reads it until the migration spec runs, rollback before that point is inert — no objects exist under the new schema and no code references it.

## Open Questions

None. The slug question was settled in favour of fixing OpenRegister's resolution rather than picking a defensive slug.
