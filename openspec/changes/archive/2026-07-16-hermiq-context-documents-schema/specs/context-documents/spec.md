# context-documents Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-context-documents-schema (this change — schema)
- hermiq-context-documents (dependent — assembler + editor)

## Purpose

Give document-shaped agent context — a project `design.md`, a coding-standards
doc, an API contract, a persona brief — a first-class home on Hermiq's `Context`
schema, distinct from `files` (Nextcloud file refs) and `objectQueries` (live
data). Per ADR-024 Rule 2, such content is stored **inline** as a new `documents`
source kind so a Context object is self-contained and versionable (ObjectEntity
AuditTrail, ADR-004). This change delivers only the schema field and its
version-gated re-import; rendering and authoring are specified for the dependent
code change.

## ADDED Requirements

### Requirement: Context schema declares an inline documents source kind

The `Context` schema MUST declare an inline `documents` source kind, so that
document-shaped context (a design.md, persona brief, standards doc) is a
first-class Context source distinct from `files` and `objectQueries` (ADR-024).

The `Context` schema (slug `context`, in `lib/Settings/hermiq_register.json`)
MUST declare a `documents` property of `type: array` with `default: []`, whose
items are objects with the fields `name`, `body`, `format`, and `description`.
`body` MUST hold inline Markdown authored directly on the Context object.
`format` MUST default to `"markdown"`. The property MUST NOT be added to the
schema's `required` list.

#### Scenario: The widened schema is imported

- GIVEN the `Context` schema at register `info.version` 0.14.0 with fields `name, description, files, objectQueries, viewRefs, charBudget, needsConsolidation`
- WHEN the register is re-imported at the bumped version
- THEN the `Context` schema exposes a `documents` array property whose items are `{ name, body, format, description }`
- AND `format` carries `default: "markdown"`
- AND `documents` is absent from the schema's `required` list

#### Scenario: A Context object persists a documents entry

- GIVEN the widened `Context` schema is imported
- WHEN a Context object is saved with a `documents` value of one entry `{ name: "design.md", body: "# Design…", format: "markdown", description: "…" }`
- THEN the object persists with that `documents` array intact
- AND a Context object saved WITHOUT any `documents` value still validates (the field is optional and defaults to `[]`)

### Requirement: The documents field mirrors the files field shape

The `documents` property MUST mirror the existing `files` field's structure and
house style: `type: array` + `default: []` at the field level, a human-readable
`title` and `description`, and an `items` object whose sub-properties each carry
their own `title` and `description`. Only the single `documents` property MUST be
added; no other `Context` field (`files`, `objectQueries`, `viewRefs`,
`charBudget`, `needsConsolidation`) SHALL be changed, and no new schema SHALL be
introduced.

#### Scenario: Shape parity with files

- GIVEN the widened `Context` schema
- WHEN the `documents` field is compared to the `files` field
- THEN both are `type: array` with `default: []` and array-of-typed-objects items
- AND each `documents.items` sub-property (`name`, `body`, `format`, `description`) carries its own `title` and `description`

#### Scenario: No collateral schema change

- GIVEN the change diff to `hermiq_register.json`
- WHEN the `Context` schema block is inspected
- THEN the only added property is `documents`
- AND `files`, `objectQueries`, `viewRefs`, `charBudget`, and `needsConsolidation` are unchanged

### Requirement: The register re-import is version-gated

The change MUST bump the register `info.version` from 0.14.0 to 0.15.0 AND the
Hermiq app `<version>` in `appinfo/info.xml`, so OpenRegister's version-gated
re-import applies the widened schema on upgrade. An unchanged version MUST NOT be
relied on to re-import.

#### Scenario: Versions bumped so the re-import fires

- GIVEN the register `info.version` is 0.14.0 and the app has a prior `<version>`
- WHEN this change is prepared
- THEN the register `info.version` reads 0.15.0
- AND `appinfo/info.xml` `<version>` is bumped above its prior value

## Non-Functional Requirements

- **Performance:** No runtime cost — this change adds only a schema property and version bumps; no code path executes the new field until the dependent code change lands.
- **Accessibility:** N/A for a schema-only change (no UI in this change).
- **Internationalization:** Dutch and English MUST be supported (ADR-007). Field `title`/`description` text is English in the register (the source-of-truth convention); UI-facing labels are localised in the dependent code change.

## Acceptance Criteria

- `documents` array property exists on the `Context` schema with items `{ name, body, format, description }` and `format` default `"markdown"`.
- `documents` is NOT in the schema's `required` list.
- No other `Context` field is altered and no new schema is added.
- Register `info.version` is 0.15.0 and `appinfo/info.xml` `<version>` is bumped.
- A seed `Context` object with a `documents` entry is provided.

## Notes

- Driving decision: ADR-024 (accepted) Rule 2. ADR-031 framing: `documents` is a plain declarative array field, not declarative business logic — no `x-openregister-*` block.
- The dependent change `hermiq-context-documents` adds `ContextAssembler.resolveDocuments()` and a Context editor surface; its scenarios live in that change's spec delta to this same capability.
