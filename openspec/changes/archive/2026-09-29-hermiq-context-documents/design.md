# Design: hermiq-context-documents

## Architecture Overview

Two seams, both already existing and extended (not invented), per ADR-024:

1. **Assembly** — `ContextAssembler` (`lib/Service/Engine/ContextAssembler.php`)
   already resolves each `Context` an agent references (`Agent.contextRefs`) into
   one budgeted preamble at run start. `assemble()` builds `$sections` from
   `resolveObjectQueries()` + `resolveFiles()`, joins with `\n\n` under a
   `Context: {name}` header, and applies the `charBudget`/`needsConsolidation`
   nudge (`mb_strlen($body) > $budget`). This change adds a third private
   resolver, `resolveDocuments()`, merged into the same `$sections`. One seam,
   one budget contract.

2. **Authoring** — Hermiq has no Context management UI today (no Context
   view/modal in `src/`; `AgentFormModal` does not manage `contextRefs`). This
   change adds a Context editor modal `ContextFormModal.vue` mirroring
   `SkillFormModal.vue`, wired to a new manifest index page over schema `context`
   through the `slots.form-dialog` seam — the same mechanism `SkillsCatalog` uses
   to replace `CnIndexPage`'s built-in generic create/edit dialog.

Depends on `hermiq-context-documents-schema`: the `documents` field must exist in
the imported `Context` schema before this code reads or writes it.

## Goals / Non-Goals

**Goals**

- Render each `documents[]` entry into the budgeted preamble as a titled section
  (its `name`), inside the existing `charBudget` accounting.
- Provide a Context editor (name/description + a `documents` list with a markdown
  editor per entry: add/rename/edit/remove) that also surfaces the existing
  `files`/`objectQueries`.
- Wire a Context management surface so Context objects can be created and edited.

**Non-Goals**

- No `Context` schema field change (owned by the schema change).
- No `viewRefs` resolution (still deferred — unchanged).
- No new backend endpoint, no new trust/guardrail code.

## API Design

No new API endpoints. Context create/edit uses the generic OpenRegister object
write path already exercised by every schema's form modal —
`createObjectStore('context', …).saveObject(...)` issues the same
`PUT/POST /apps/openregister/api/objects/{register}/{schema}` the `agent`,
`evaldataset`, and `agentskill` (edit) stores use. The assembler reads Context
objects through the existing `ObjectService` surface it already uses. There is
nothing new to contract with another project.

## Database Changes

None — Hermiq owns no tables (ADR-001); the `documents` schema field ships in the
dependent schema change and is applied by OpenRegister's version-gated re-import.

## Nextcloud Integration

- Controllers: none new.
- Services: `ContextAssembler` (existing) gains `resolveDocuments()`; it continues
  to read via the injected `ObjectService` and `IRootFolder`.
- Mappers/Entities: none — Context objects live in OpenRegister.
- Events/Hooks: none.

## Security Considerations

No new trust surface (ADR-024 Rule 3). A document `body` is data prepended to the
prompt — never executable, never an escalation. The assembled preamble (now
including documents) passes through the org's existing configurable guardrail
input filters exactly as `files`/`objectQueries` text does; a `design.md` cannot
smuggle a prompt-injection past the guardrail policy. Assembly inherits the run's
acting-user identity (ADR-023). `resolveDocuments()` adds no read of any resource
beyond the Context object itself (no filesystem, no query), so it introduces no
new authorization path. The `context` schema stays `publicRead: false` /
`publicWrite: false` and tenant-scoped via ObjectEntity; the editor writes through
the same RBAC-guarded OR object path the other form modals use.

## NL Design System

The Context editor reuses standard Nextcloud + Conduction components already used
by `SkillFormModal` — `NcModal`, `NcTextField`, `NcTextArea`, `NcButton`,
`NcNoteCard`, `NcLoadingIcon`, and `CnMarkdownEditor` (`value` in / `@input` out)
for each document `body`. No hardcoded colours; CSS variables only, matching the
`skill-form__*` scoped-style pattern. User-facing strings are localised via
`t('hermiq', …)` in English and Dutch (ADR-007).

## File Structure

```
lib/Service/Engine/ContextAssembler.php   # + private resolveDocuments(); + 1 merge line in assemble()
src/modals/ContextFormModal.vue           # NEW — mirrors SkillFormModal
src/store/store.js                         # + useContextStore = createObjectStore('context', {register:'hermiq', schema:'context'})
src/customComponents.js                    # + import + register ContextFormModal
src/manifest.json                          # + Context index page (schema: context, slots.form-dialog: ContextFormModal) + nav entry
```

## Trade-offs

- **`resolveDocuments()` render format:** rendered as a titled section using the
  entry's `name` (ADR-024), consistent with the `Source: {path}` block
  `resolveFiles()` emits, so the model sees a uniform section shape across all
  three source kinds. Alternative (a distinct heading style per kind) rejected —
  it would fracture the single-preamble uniformity for no benefit.
- **Create via generic OR PUT (not an import path):** unlike a Skill (whose CREATE
  goes through `SkillSerializer::fromPackage`), a Context has no package format —
  `documents` is authored inline — so both create and edit use the generic object
  write path. Simpler, and the edit path spreads the existing payload first so
  `viewRefs`/`charBudget`/`needsConsolidation` survive a PUT.
- **New manifest page vs. embedding on the agent form:** a dedicated Context
  management page (mirroring `SkillsCatalog`) keeps Context a first-class object
  with its own list/create/edit, matching the ADR-024 concept table, rather than
  burying authoring inside `AgentFormModal`. `contextRefs` on the agent stays a
  reference picker; authoring lives on the Context surface.
