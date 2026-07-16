# Design: hermiq-skill-markdown-authoring

## Architecture Overview

This change is **pure frontend UI** on the existing skills-catalog data path. Nothing new
runs on the server; nothing new is declarative-vs-imperative interesting.

```
SkillsCatalog page (type:"index", route /skills, src/manifest.json)
  └─ slots.form-dialog: "SkillFormModal"      ← NEW manifest line
        │  (CnPageRenderer forwards a page's top-level `slots` map as scoped
        │   slots; CnIndexPage's #form-dialog scoped slot — scope
        │   { show, item, schema, close } — replaces its built-in create/edit dialog)
        ▼
  src/customComponents.js  ── registers ──▶  src/modals/SkillFormModal.vue   ← NEW
        │                                         │
        │  body authored with                     │  save via existing helper
        ▼                                         ▼
  @conduction/nextcloud-vue CnMarkdownEditor   src/api/skills.js
   (value-in / @input-out)                     (importSkill / update — SkillController → SkillService)
```

The reference implementation is `src/modals/AgentFormModal.vue` wired to the AgentCatalog
page via that same `slots.form-dialog` mechanism (verified in `src/manifest.json` line ~200
and `src/customComponents.js`). This change is the skills analogue.

### ADR-031 (declarative-vs-imperative) note

This change introduces **no** new PHP service class and **no** state-machine semantics for
an OR-owned object. The modal is presentation only; persistence flows through the existing
`SkillService` create/import and update paths, which already write through OpenRegister's
`ObjectService` single write-path. There is no new declarative OR behaviour, no new
lifecycle transition, no aggregation, and no notification dialect touched. ADR-031 is
therefore satisfied trivially — there is nothing imperative to flag because the change adds
no server logic at all.

## API Design

No API change. Reuses the existing, unchanged endpoints via `src/api/skills.js`:

- `POST /apps/hermiq/api/skills` (`SkillController::import`) — create/import a skill from a
  package string. `importSkill(package, createdBy)` calls `SkillSerializer::fromPackage`,
  so a full pasted agentskills.io package splits into `frontmatter` + `body`.
- Update on edit goes through the generic OR object write path the skills surface already
  uses (the same PUT the row-edit path issues), merging the existing Skill payload so
  unsurfaced fields survive.

The modal composes the `frontmatter` + `body` (+ name/description) into the shape these
existing calls already accept; it does not define a new request/response contract.

## Nextcloud Integration

- Controllers: none new (existing `SkillController` reused).
- Services: none new (existing `SkillService` / `SkillSerializer` reused).
- Mappers/Entities: none — `Skill` is an OpenRegister object (slug `agentskill`), no
  Hermiq-owned table.
- Events/Hooks: none.

## Security Considerations

No new security surface. Persistence stays on the existing `SkillController` routes, which
are `@NoAdminRequired` + tenant-scoped through OpenRegister RBAC (OR's native tenancy is the
guard, per the controller's own docblock). The modal sends no new parameters and opens no
new endpoint. `src/api/skills.js` uses `@nextcloud/axios`, which attaches the CSRF
requesttoken automatically. Pasted content is treated as skill data (frontmatter + body),
not executed; the markdown preview renders through `CnMarkdownEditor`'s sanitised
`cnRenderMarkdown` pipeline (verified in the component).

## NL Design System

- `CnMarkdownEditor` (from `@conduction/nextcloud-vue`) for the SKILL.md `body` — textarea
  + live preview + formatting toolbar, themed with NC CSS variables; `value`-in /
  `@input`-out contract (verified: prop `value: String`, emits `input`).
- Standard NC components for the rest: `NcModal` (ADR-004 modal-isolation), `NcTextField`
  (name, description, frontmatter), `NcButton`, `NcNoteCard` (error), `NcLoadingIcon` —
  the exact component set `AgentFormModal` uses. No hardcoded colors; NC CSS variables only.

## File Structure

```
src/
  modals/
    SkillFormModal.vue        (NEW — accepts { show, item, schema, close })
  customComponents.js         (MODIFIED — register SkillFormModal)
  manifest.json               (MODIFIED — SkillsCatalog page: slots.form-dialog)
```

## Trade-offs

- **Top-level `slots.form-dialog` (chosen) vs a bespoke "New skill" button + custom route.**
  The slot approach reuses the built-in Add CTA and row-edit entry points the `type:"index"`
  page already renders, matching the just-shipped AgentFormModal pattern exactly — one line
  of manifest, zero new routing. A bespoke button would duplicate CTA/edit plumbing and
  diverge from the established convention.
- **Reuse the import path for pasted packages (chosen) vs a new "parse package" endpoint.**
  `SkillController::import` + `SkillSerializer::fromPackage` already split a fenced package
  losslessly; adding an endpoint would be redundant backend for a capability that exists.
- **`CnMarkdownEditor` for `body` only (chosen) vs a full YAML editor for `frontmatter`.**
  `frontmatter` is small structured YAML; a plain text field keeps the form honest and
  avoids pulling a YAML-editor dependency. A richer frontmatter editor is deferred (see
  proposal Out of Scope) rather than speculatively built.
- **Editing auxiliary `files` inline (chosen) vs deferring them.** The modal surfaces the
  `files` array (each `{ name, content }`) with add/rename/edit/remove so multi-file
  agentskills.io skills can be hand-authored, not just imported. This enlarges the modal
  but is required for authoring parity with imported skills; content fields are plain text
  (Markdown files may reuse `CnMarkdownEditor`), and the array round-trips through the
  existing persistence path with no schema or service change.

<!-- Seed Data section omitted: this change introduces no new schema or entity — `Skill`
     (slug `agentskill`) already exists with `frontmatter` + `body`, and this UI-only change
     seeds no data. The dependent change hermiq-skill-conversational-authoring owns the
     skill-creator seed. -->
