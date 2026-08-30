# Skills Catalog Specification (delta: hermiq-skill-markdown-authoring)

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- hermiq-skill-markdown-authoring

## Purpose

Adds a direct markdown skill-authoring surface to the existing skills catalog. The catalog
already imports/exports/installs skills; this delta makes *authoring* a skill first-class by
replacing the generic schema-driven create/edit dialog on the SkillsCatalog page with a
purpose-built form that edits the SKILL.md `body` as markdown and the `frontmatter` as text,
and can also ingest a pasted agentskills.io package. Related: ADR-003 (skills as OR objects),
ADR-004 (modal-isolation), ADR-031 (this delta adds no imperative OR service — UI only).

## ADDED Requirements

### Requirement: A dedicated markdown authoring form replaces the generic Skill create/edit dialog

The SkillsCatalog page (`type: "index"`, route `/skills`) MUST mount a purpose-built
`SkillFormModal` in place of the built-in generic schema-driven create/edit dialog, for both
create AND edit, wired via the page's TOP-LEVEL `slots` map entry
`form-dialog: "SkillFormModal"` (the `CnIndexPage` `#form-dialog` scoped slot, scope
`{ show, item, schema, close }`) and registered in `src/customComponents.js`. The modal MUST
author the `body` (SKILL.md) with the lib's `CnMarkdownEditor` (`value`-in / `@input`-out) and
MUST surface plain fields for `name` (required), `description`, and `frontmatter`. The modal
MUST also surface an editor for the Skill's auxiliary `files` array (each an
`{ name, content }` object per the `agentskill` schema) supporting add, rename, edit-content,
and remove, so multi-file agentskills.io skills can be hand-authored; the `files` array MUST
round-trip through the existing persistence path with no schema or backend change.

#### Scenario: Opening "Add Skill" mounts the markdown form, not the generic dialog

- GIVEN the SkillsCatalog page declares `slots.form-dialog: "SkillFormModal"` and
  `SkillFormModal` is registered in `customComponents.js`
- WHEN the user activates the page's built-in Add CTA (create mode, `item` is null)
- THEN the `SkillFormModal` MUST be shown with a markdown editor for the `body` and text
  fields for `name`, `description`, and `frontmatter`
- AND the generic schema-driven textarea dialog MUST NOT be shown

#### Scenario: Editing an existing skill opens the same form pre-filled

- GIVEN a `Skill` object exists in the catalog
- WHEN the user triggers edit for that row (the `#form-dialog` slot supplies `item` = the
  Skill being edited)
- THEN the `SkillFormModal` MUST open pre-filled from that Skill's `name`, `description`,
  `frontmatter`, `body`, and its `files` array (each `{ name, content }` shown in the files editor)

### Requirement: Authored skills persist through the existing catalog write path without a new backend

Saving from the authoring form MUST reuse the existing skill persistence path
(`SkillController`/`SkillService` via `src/api/skills.js`) — no new endpoint, route, service,
or schema field is introduced. A newly authored skill MUST persist with `state` `active` and
`createdBy` set to the authoring user (the existing import default). On edit, the existing
Skill payload MUST be merged so schema fields the form does not surface (e.g. `state`,
`source`, `installedOn`, `githubOwner`/`githubRepo`/`publishedAt`) survive the write.

#### Scenario: Writing a new skill by hand persists it via the existing path

- GIVEN the user opens the authoring form in create mode
- WHEN they type a `name`, a YAML `frontmatter` block, and a markdown `body`, then save
- THEN the skill MUST be persisted through the existing `SkillController`/`SkillService`
  create path with the typed `frontmatter` and `body`
- AND its `state` MUST be `active` and `createdBy` MUST be the authoring user

#### Scenario: Editing preserves fields the form does not surface

- GIVEN a `Skill` already installed on an agent (its `installedOn` contains an agent uuid)
- WHEN the user edits only its `body` in the authoring form and saves
- THEN the updated skill MUST retain its `installedOn` association and any provenance
  fields it already had — only the surfaced fields (`name`/`description`/`frontmatter`/
  `body`) are replaced

### Requirement: A pasted agentskills.io package is split into frontmatter and body

The authoring form MUST let a user paste a full agentskills.io package (a leading `---`
fenced frontmatter block followed by the body) and MUST ingest it via the existing import
path (`SkillController::import` → `SkillSerializer::fromPackage`) so it is stored as
structured `frontmatter` + `body`, never as one opaque blob and never double-fenced.

#### Scenario: Pasting a fenced package populates the two fields

- GIVEN the user has a full agentskills.io package string beginning with a `---` fence
- WHEN they paste it into the authoring form's package/paste affordance
- THEN the leading fenced YAML MUST populate `frontmatter` and the remainder MUST populate
  `body`
- AND a subsequent export via `SkillSerializer::toPackage` MUST reproduce the original
  package byte-for-byte (the serializer's existing round-trip guarantee)

## Non-Functional Requirements

- **Performance:** The authoring form is client-side; opening it MUST NOT issue a
  skill-list re-fetch. The `CnMarkdownEditor` default textarea path carries no heavy editor
  dependency (the WYSIWYG mode is lazily loaded and is not used here).
- **Accessibility:** All inputs MUST carry labels; the modal follows ADR-004 modal-isolation
  and WCAG 2.1 AA (`NcModal` focus handling, labelled fields), matching `AgentFormModal`.
- **Internationalization:** Dutch and English MUST be supported (ADR-005); all visible
  strings go through `t('hermiq', …)` with English source keys.

## Acceptance Criteria

- [ ] SkillsCatalog's Add CTA and row-edit both open `SkillFormModal`, not the generic dialog
- [ ] `body` is authored via `CnMarkdownEditor`; `name`/`description`/`frontmatter` are fields
- [ ] Save (create + edit) persists through the existing `src/api/skills.js` path — no new endpoint
- [ ] Editing preserves `installedOn` and provenance fields the form does not surface
- [ ] A pasted fenced package splits into `frontmatter` + `body` and round-trips on export

## Notes

- No schema change: `Skill` (slug `agentskill`) already declares `frontmatter` (string) and
  `body` (string); `required` is `["name"]` (verified in `lib/Settings/hermiq_register.json`).
- The `#form-dialog` slot + `customComponents.js` wiring mirrors `AgentFormModal` on the
  AgentCatalog page exactly (verified in `src/manifest.json` and `src/customComponents.js`).
- Auxiliary `files` editing is deliberately deferred (proposal Out of Scope).
