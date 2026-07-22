---
kind: config
depends_on: []
---

# Proposal: hermiq-agent-files-schema

## Summary

Add a single `uploadFolder` string property to the `Agent` schema in
`lib/Settings/hermiq_register.json`, so each agent can override where its chat
attachments land (today hard-coded to `Hermiq/Attachments` in
`ChatAttachmentController`), and bump the register `info.version`
(0.16.0 → 0.17.0) plus the app `<version>` in `appinfo/info.xml`
(0.1.82 → 0.1.83) so the version-gated Repair-step re-import applies the widened
schema. This is the **head of a two-change chain**: this change delivers only
the schema field and its re-import; the dependent code change
(`hermiq-agent-files`) consumes the field and builds the per-agent Files surface
on top of it. No code is written here.

## Motivation

The user asked to give each agent a per-agent **Files** surface — "like a Claude
project" — combining (a) a configurable upload folder for chat attachments and
(b) related files the agent can scan and use. The decided architecture (steered
by ADR-024's concept model) is **one Files surface backed by the existing Context
system**, not a fourth parallel concept:

- **Related files** are already modeled: a `Context` bundle's `files[]`
  (`{path, description}`) attaches to an agent via `Agent.contextRefs` and is
  read into the budgeted preamble by `ContextAssembler::resolveFiles()`. The
  code change reuses this seam through an agent-owned Context bundle — **no new
  `Agent.relatedFiles[]` field is needed or added.**
- The **upload folder** is the one genuinely missing piece of persistent agent
  state: `ChatAttachmentController::ATTACHMENTS_FOLDER` is a private class
  constant, identical for every agent and every user. To make the destination
  per-agent, the agent object must carry the setting — hence one new field,
  `uploadFolder`.

Doing the schema widening first, in its own config change, keeps the
version-gated re-import isolated and lets the dependent code change assume the
field exists.

## Affected Projects

- [x] Project: `hermiq` — `Agent` schema in `lib/Settings/hermiq_register.json`
  gains an `uploadFolder` string; register `info.version` and app `<version>`
  bumped.

## Scope

### In Scope

- Add `uploadFolder` (string, `default: "Hermiq/Attachments"`, not `required`) to
  the `Agent` schema, with a description stating the path is relative to the
  acting user's Nextcloud folder (identical semantics to `Context.files[].path`).
- Bump register `info.version` 0.16.0 → 0.17.0 in `hermiq_register.json`.
- Bump app `<version>` 0.1.82 → 0.1.83 in `appinfo/info.xml` so the
  `InitializeSettings` Repair step re-imports the register on upgrade.

### Out of Scope

- **No new `Agent.relatedFiles[]` field** — related files reuse `Context.files`
  via `Agent.contextRefs`, which already exists (ADR-024 decision).
- All code: controller changes, folder resolution, the Files widget, the
  on-demand Context bundle, and the form field are the dependent code change
  (`hermiq-agent-files`).
- Path validation/traversal handling of `uploadFolder` at write time (a runtime
  concern, specified for the code change).

## Approach

Widen one schema object and bump two version numbers. The `Agent` schema mirrors
its existing scalar-config fields (e.g. `model`, `type`); the new property is a
plain string with a default matching today's hard-coded constant, so every
existing agent behaves identically until an owner overrides it. The re-import is
driven by OpenRegister's `ConfigurationService::importFromApp()`, gated on the
bumped `info.version`, invoked by `lib/Repair/InitializeSettings.php` on app
upgrade — the same mechanism every prior Hermiq schema change used. Details in
design.md and migration.md.

## New Dependencies

None.

## Impact

- `lib/Settings/hermiq_register.json` — `Agent` schema + `info.version`.
- `appinfo/info.xml` — `<version>`.
- Existing `Agent` objects: unaffected; the absent `uploadFolder` reads as its
  `Hermiq/Attachments` default. No data migration of stored objects.

## Cross-Project Dependencies

None. The `Agent`/`Context` schemas live in this app's register; OpenRegister is
consumed as the storage layer only.

## Risks

### Risk 1: Re-import does not run if the version is not bumped

**Severity:** Medium — **Mitigation:** Bump BOTH the register `info.version`
(gates `importFromApp`) and the app `<version>` (gates the Repair step running at
all). This change specifies both; migration.md documents the verification that
the field is present after upgrade.

### Risk 2: Default folder collides with the chat-attachments folder

**Severity:** Low — **Mitigation:** Intentional. The default value is exactly
today's constant `Hermiq/Attachments`, so the default-configured behaviour is
byte-for-byte the current behaviour; only an explicit override changes anything.

## Rollback Strategy

Revert the two edits (schema property + the two version bumps). Because the field
is optional with a default and no stored object is migrated, a revert leaves
existing `Agent` objects valid; a subsequent re-import at the lower version is a
no-op. No destructive data change to undo.
