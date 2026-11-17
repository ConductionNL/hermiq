---
kind: config
depends_on: []
---

# Proposal: hermiq-context-documents-schema

## Summary

Add a fourth source kind — **`documents`** — to Hermiq's `Context` schema (slug
`context`, in `lib/Settings/hermiq_register.json`). A `documents` entry holds
inline Markdown (`{ name, body, format, description }`, `format` defaulting to
`"markdown"`), mirroring the existing `files` field's array-of-objects shape.
This is the schema (config) head of a two-change chain implementing **ADR-024**;
no PHP or Vue is written here. It only widens the schema and version-gates the
re-import so the code change that follows can render and author documents.

## Motivation

ADR-024 (status: accepted) asks how document-shaped context — a project
`design.md`, a coding-standards doc, an API contract, a persona brief — should
reach an agent. Today the only home is a `files` entry pointing at a Nextcloud
file, which conflates "a file the user happens to store in NC" with "a curated,
versioned piece of context that is part of the agent's definition", and gives no
first-class handle for authoring, versioning, or sharing that content. ADR-024
Rule 2 resolves this: document-shaped context becomes a first-class inline
`documents` source on `Context`, self-contained and versionable through
OpenRegister's AuditTrail (ADR-004), authored with the same `CnMarkdownEditor`
surface the skill-authoring work already ships — while `files` stays for genuine
"include this NC file" cases and `objectQueries` stays for live data.

This change is the necessary first link: the field must exist in the schema
(and the register re-import must be version-gated) before any code can read,
render, or author it.

## Chain Arc

This proposal is the head of a config→code chain that mirrors the
skill-authoring build:

1. **`hermiq-context-documents-schema`** (this change, config) — add the
   `documents` field to the `Context` schema; bump the register `info.version`
   and the app version so the change re-imports.
2. **`hermiq-context-documents`** (code, `depends_on` this) — `ContextAssembler`
   gains a `resolveDocuments()` that renders each `documents[]` entry into the
   same budgeted preamble as `files`/`objectQueries`, plus a Context editor modal
   (mirroring `SkillFormModal`) and a Context management surface wired through the
   manifest.

The two are split so the version-gated register change can land and re-import
independently of the code that consumes it — the assembler tolerates a missing
`documents` value already (it reads `$data['documents'] ?? []`-style), so the
schema can ship first with no runtime breakage.

## Affected Projects

- [ ] Project: `hermiq` — add `documents` to the `Context` schema in
  `lib/Settings/hermiq_register.json`; bump register `info.version` 0.14.0 →
  0.15.0 and the app version in `appinfo/info.xml`.

## Scope

### In Scope

- Add a `documents` array property to the `Context` schema, each item an object
  `{ name, body, format, description }` with `format` defaulting to `"markdown"`,
  modelled exactly on the existing `files` field's title/description/`default: []`
  style. Not added to the schema's `required` list.
- Bump the register `info.version` (0.14.0 → 0.15.0) so the re-import version
  gate fires, and bump the app `<version>` in `appinfo/info.xml`.
- Provide a realistic seed/example `Context` object carrying a `documents` entry
  (design.md-style body, safe placeholder content) as design.md Seed Data.

### Out of Scope

- Any PHP or Vue code — `ContextAssembler.resolveDocuments()`, the Context editor
  modal, and the manifest wiring all belong to the dependent code change
  `hermiq-context-documents`.
- Changing `files`, `objectQueries`, `viewRefs`, `charBudget`, or
  `needsConsolidation` — only the single new `documents` field is added.
- The GitHub-shareable-context bundle and a conversational context-creator
  (parked as follow-ons by ADR-024 Rule 4).

## Approach

Edit the `Context` schema block in `lib/Settings/hermiq_register.json`, appending
a `documents` property alongside `files`. Copy the `files` field's exact shape:
`type: array`, `default: []`, a `Documents` title and a description, and an
`items` object with typed sub-properties (`name`, `body`, `format` with
`default: "markdown"`, `description`), each carrying its own `title`/`description`
in the house style. Bump `info.version` to 0.15.0 and the app `<version>` in
`appinfo/info.xml`. Re-import is driven by the existing version gate.

## New Dependencies

None.

## Impact

- `lib/Settings/hermiq_register.json` — one new property on the `Context` schema;
  register `info.version` bump.
- `appinfo/info.xml` — app `<version>` bump.
- No runtime code path changes: `ContextAssembler` at HEAD does not read
  `documents`, so importing the widened schema is inert until the code change
  lands.

## Cross-Project Dependencies

None. The `Context` schema is Hermiq-owned; OpenRegister validates and stores it
but no other app reads the `documents` field.

## Risks

### Risk 1: Re-import does not fire without a version bump

**Severity:** Medium — **Mitigation:** Bump BOTH the register `info.version`
(0.14.0 → 0.15.0) and the app `<version>` in `appinfo/info.xml`; the register
re-import is version-gated and silently no-ops if the version is unchanged.

### Risk 2: Shape drift from `files` breaks the editor's reuse assumption

**Severity:** Low — **Mitigation:** Model `documents.items` field-for-field on the
existing `files.items` (array of typed objects), so the code change can reuse the
same array-of-objects editor pattern without a bespoke shape.

## Rollback Strategy

Revert the two edits (the `Context` schema property and the two version bumps).
Since no code reads `documents`, any Context objects authored with a `documents`
value simply carry an unread field; removing the schema property leaves those
values as untyped free-form data in OpenRegister, non-fatal. To fully undo,
re-import at the reverted (lower-or-equal) version requires a manual version
gate reset.

## Open Questions

None.
