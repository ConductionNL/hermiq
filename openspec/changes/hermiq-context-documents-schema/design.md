# Design: hermiq-context-documents-schema

## Architecture Overview

Hermiq owns no database tables (ADR-001 data-layer — all domain data lives as
OpenRegister objects). The `Context` schema is declared in
`lib/Settings/hermiq_register.json` and imported into OpenRegister at a
version-gated re-import. This change adds a single new property — `documents` —
to that schema block and bumps the two versions that drive the re-import. It is
the config head of the ADR-024 chain; the code that reads and authors `documents`
lands in the dependent change `hermiq-context-documents`.

Today the `Context` schema declares `name`, `description`, `files`,
`objectQueries`, `viewRefs`, `charBudget`, `needsConsolidation`. At run start,
`ContextAssembler` (`lib/Service/Engine/ContextAssembler.php`) resolves every
`Context` an agent references (via `Agent.contextRefs`) into one budgeted text
preamble prepended to the system prompt. The assembler reads each field
defensively (`$data['files'] ?? []`, `$data['objectQueries'] ?? []`), so a
Context object that already carries a `documents` value is inert until the code
change teaches the assembler to read it — the widened schema is safe to import on
its own.

## Goals / Non-Goals

**Goals**

- Add a `documents` array source kind to the `Context` schema, each item
  `{ name, body, format, description }` with `format` defaulting to `"markdown"`,
  modelled field-for-field on the existing `files` field.
- Version-gate the re-import: bump register `info.version` 0.14.0 → 0.15.0 and
  the app `<version>` in `appinfo/info.xml`.
- Provide realistic Seed Data (ADR-001) so the field is exercisable on install.

**Non-Goals**

- No PHP or Vue. `ContextAssembler.resolveDocuments()`, the Context editor modal,
  and the manifest wiring belong to `hermiq-context-documents`.
- No change to any other `Context` field (`files`, `objectQueries`, `viewRefs`,
  `charBudget`, `needsConsolidation`) and no new schema.
- No new OpenRegister declarative extension points (see Decisions).

## Decisions

### Decision 1: `documents` is a plain declarative array field, not declarative business logic (ADR-031)

ADR-031 steers authors toward OpenRegister's schema extension points
(`x-openregister-lifecycle`, `-aggregations`, `-calculations`, `-notifications`,
`-relations`, `-widgets`) instead of PHP service classes when behaviour can be
expressed as schema metadata. `documents` needs **none** of them: it introduces
no state machine, no derived aggregate, no cross-object calculation, and no
notification. It is a plain typed `array` property whose items are typed objects
— exactly the shape `files` already uses. So this change adds only a property to
the `properties` map; it declares no `x-openregister-*` block.

**Alternative considered:** model documents as a separate related schema
(`ContextDocument`) referenced by uuid. Rejected — ADR-024 Rule 2 is explicit
that a `design.md` is *content*, stored inline so the Context object is
self-contained and versionable via the ObjectEntity AuditTrail (ADR-004),
without a second object's lifecycle to keep in sync. An inline array mirrors
`files` and keeps one budget contract.

### Decision 2: Mirror the `files` field shape exactly

`files.items` is `{ path, description }` (array of typed objects, each sub-field
titled/described, the field itself `type: array` + `default: []`). `documents`
mirrors this: `type: array`, `default: []`, an `items` object with `name`,
`body`, `format` (`default: "markdown"`), `description`, each sub-field carrying
its own `title`/`description` in the house style. This lets the code change reuse
the same array-of-objects editor pattern the skill work already ships, with no
bespoke shape (ADR-024 Rule 4 — reuse, don't reinvent).

### Decision 3: `ContextAssembler` is an existing imperative service, extended later (not now)

Per ADR-001/context-system, `ContextAssembler` already exists and already renders
`files` + `objectQueries` into a budgeted preamble. Rendering `documents` is an
extension of that existing imperative service (a new private `resolveDocuments()`
merged into the same `$sections`), scheduled for the dependent code change. This
config change writes no code and does not touch the assembler.

## Database Changes

None in the Nextcloud/Doctrine sense — Hermiq owns no tables (ADR-001). The only
"schema" change is to the OpenRegister JSON schema in `hermiq_register.json`,
applied by OpenRegister's version-gated register re-import. The register
`info.version` bump (0.14.0 → 0.15.0) is what makes the re-import fire; the
`Context` schema's own `version` (`0.1.0`) may also be bumped for provenance but
the register-level `info.version` is the gate.

## Nextcloud Integration

- Controllers: none.
- Services: none (the existing `SettingsService` register import path applies the
  widened schema at install/upgrade; no new code).
- Mappers/Entities: none — OpenRegister stores `Context` objects.
- Events/Hooks: none.

## Security Considerations

No new trust surface (ADR-024 Rule 3). A `documents` entry is **data prepended to
a prompt**, never executable and never a way to escalate. It inherits the run's
acting-user identity (ADR-023) and carries no authorization of its own. Because
this change adds no code, there is no new input-handling path: when the code
change later assembles `documents` into the preamble, the org's existing
configurable guardrail input filters apply to it exactly as they do to `files`
and `objectQueries` today. This config change itself only widens a schema that is
already `publicRead: false` / `publicWrite: false` and tenant-scoped via
ObjectEntity.

## File Structure

```
lib/Settings/hermiq_register.json    # + documents property on the Context schema; info.version 0.14.0 → 0.15.0
appinfo/info.xml                     # <version> bump (re-import gate)
```

## Seed Data

Per ADR-001, the feature ships with a realistic seed `Context` object so the
`documents` field is exercisable immediately on install. General-organisation
flavour (works for a municipality or a consultancy), safe placeholder content
only. The apply agent generates the `_registers.json` seed entry from this
section.

### Schema: `context`

| Field | Object 1 |
|-------|----------|
| `@self.register` | `hermiq` |
| `@self.schema` | `context` |
| `@self.slug` | `permit-team-project-context` |
| `name` | `Permit team project context` |
| `description` | `Shared design and standards reference for the permit-intake agent.` |
| `files` | `[]` |
| `objectQueries` | `[{ "register": "hermiq", "schema": "agent", "limit": 5 }]` |
| `documents` | see below |
| `charBudget` | `8000` |
| `needsConsolidation` | `false` |

`documents` value (one entry, design.md-style inline Markdown, safe placeholders):

```json
[
  {
    "name": "design.md",
    "format": "markdown",
    "description": "Intake agent design brief for the permit team.",
    "body": "# Permit intake agent — design\n\n## Purpose\nTriage incoming permit requests and draft a first-pass summary for a case handler to review. The agent never issues a decision.\n\n## Scope\n- In scope: classify request type, extract applicant + address, flag missing documents.\n- Out of scope: any approve/deny decision, any payment, any external correspondence.\n\n## Tone\nPlain Dutch, no jargon. Address the citizen politely and neutrally.\n\n## Escalation\nIf a request is ambiguous or references an objection procedure, stop and hand off to a human handler with a short note."
  }
]
```

**Related items per object:**

- Files: none (the `files` array is empty for this seed; document content is
  inline in `documents`).
- Notes: n/a.
- Tasks: n/a.
- Contacts: n/a.

## Trade-offs

- **Inline vs. referenced document:** inline `documents` can drift from a
  source-of-truth `design.md` the team edits elsewhere (ADR-024 Consequences).
  Accepted — teams who want a live file use `files`; `documents` is deliberately
  for curated context that is part of the agent's definition.
- **Two-change split:** shipping the schema separately from the code means a brief
  window where a `documents` value can be authored (via the generic OR object API)
  but is not yet rendered into the preamble. Accepted — the assembler reads every
  field defensively, so an unread `documents` value is non-fatal, and the split is
  what lets the version-gated re-import land independently.
