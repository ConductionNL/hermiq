# Tasks: hermiq-skill-markdown-authoring

## Implementation Tasks

### Task 1: Build SkillFormModal.vue (markdown body + fields, slot bindings, save)
- **spec_ref**: `openspec/changes/hermiq-skill-markdown-authoring/specs/skills-catalog/spec.md#requirement-a-dedicated-markdown-authoring-form-replaces-the-generic-skill-createedit-dialog`
- **files**: `src/modals/SkillFormModal.vue`, `src/api/skills.js`
- **acceptance_criteria**:
  - GIVEN create mode (`item` null) WHEN the modal opens THEN it shows a `CnMarkdownEditor` for `body` and `NcTextField`s for `name` (required), `description`, `frontmatter`
  - GIVEN edit mode WHEN `item` is a Skill THEN the form is pre-filled from its `name`/`description`/`frontmatter`/`body`/`files`
  - GIVEN the auxiliary files editor WHEN the user adds/renames/edits/removes a file THEN the `files` array (each `{ name, content }`) round-trips through save with no schema/backend change
  - GIVEN the slot bindings `{ show, item, schema, close }` WHEN the modal closes/saves THEN it calls both `$emit('close')` and `this.close?.()` (AgentFormModal parity), accepting `schema` as an unused prop
  - GIVEN a valid form WHEN the user saves THEN it persists via the existing `src/api/skills.js` path with `state` `active` and `createdBy` set (create), no new endpoint
- [ ] Implement
- [ ] Test

### Task 2: Package-paste ingestion and edit payload-merge
- **spec_ref**: `openspec/changes/hermiq-skill-markdown-authoring/specs/skills-catalog/spec.md#requirement-a-pasted-agentskillsio-package-is-split-into-frontmatter-and-body`
- **files**: `src/modals/SkillFormModal.vue`, `src/api/skills.js`
- **acceptance_criteria**:
  - GIVEN a pasted string starting with a `---` fence WHEN ingested THEN it routes through the import path (`SkillSerializer::fromPackage`) so `frontmatter` + `body` populate separately, never double-fenced
  - GIVEN a subsequent export WHEN `SkillSerializer::toPackage` runs THEN the original package is reproduced byte-for-byte
  - GIVEN edit of a Skill with an `installedOn` association WHEN only `body` changes and is saved THEN `installedOn` and provenance fields survive (existing payload merged before write)
- [ ] Implement
- [ ] Test

### Task 3: Wire SkillFormModal into the SkillsCatalog page
- **spec_ref**: `openspec/changes/hermiq-skill-markdown-authoring/specs/skills-catalog/spec.md#requirement-a-dedicated-markdown-authoring-form-replaces-the-generic-skill-createedit-dialog`
- **files**: `src/manifest.json`, `src/customComponents.js`
- **acceptance_criteria**:
  - GIVEN the SkillsCatalog page WHEN the manifest declares `slots.form-dialog: "SkillFormModal"` and `customComponents.js` registers it THEN the Add CTA and row-edit both mount `SkillFormModal`, not the generic dialog
  - GIVEN the existing `row-actions: "skill-row-actions"` slot WHEN the new `form-dialog` slot is added THEN the row-actions behaviour is unchanged
- [ ] Implement
- [ ] Test

## Quality checklist

- New/changed frontend logic covered by unit tests (`tests/` Vue specs) where feasible
- UI changes covered by a Playwright browser test (Add + row-edit open `SkillFormModal`; save round-trips)
- All tests pass (`composer test`, frontend `npm test`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
