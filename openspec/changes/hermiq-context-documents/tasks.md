# Tasks: hermiq-context-documents

## Implementation Tasks

### Task 1: Render documents in ContextAssembler
- **spec_ref**: `openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-contextassembler-renders-documents-into-the-budgeted-preamble`
- **files**: `lib/Service/Engine/ContextAssembler.php`, `tests/Unit/Service/Engine/ContextAssemblerTest.php`
- **acceptance_criteria**:
  - GIVEN a Context with a valid `documents` entry WHEN `assemble()` runs THEN a private `resolveDocuments()` renders it as a titled section (its `name`) merged into the same `$sections` as files/object-queries, under the `Context: {name}` header.
  - GIVEN a malformed `documents` entry (not an object, or missing non-empty `name`/`body`) WHEN assembled THEN it is skipped-and-logged and the rest of the Context still assembles (mirrors `resolveFiles()`).
  - GIVEN a Context with no `documents` value WHEN assembled THEN the output is identical to pre-change; the rendered documents are counted in the existing `charBudget`/`needsConsolidation` accounting with no new budget contract and no truncation.
- [ ] Implement
- [ ] Test

### Task 2: Context editor modal + object store
- **spec_ref**: `openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-a-context-editor-authors-documents-with-a-markdown-editor-per-entry`
- **files**: `src/modals/ContextFormModal.vue`, `src/store/store.js`
- **acceptance_criteria**:
  - GIVEN the `#form-dialog` slot contract (`{ show, item, schema, close }`) WHEN `ContextFormModal.vue` is added (mirroring `SkillFormModal`) THEN it exposes required `name` + `description`, a `documents` list with a `CnMarkdownEditor` per `body` and add/rename/edit/remove, plus the existing `files`/`objectQueries`.
  - GIVEN a create or edit WHEN Save is clicked THEN it persists through `useContextStore` (`createObjectStore('context', {register:'hermiq', schema:'context'})`), and an edit spreads the existing payload first so `viewRefs`/`charBudget`/`needsConsolidation` survive the PUT.
- [ ] Implement
- [ ] Test

### Task 3: Wire the Context management surface
- **spec_ref**: `openspec/changes/hermiq-context-documents/specs/context-documents/spec.md#requirement-context-objects-are-managed-through-a-dedicated-surface`
- **files**: `src/customComponents.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `customComponents.js` WHEN updated THEN `ContextFormModal` is imported and registered alongside `SkillFormModal`.
  - GIVEN `src/manifest.json` WHEN updated THEN a Context index page over schema `context` (with columns) and a nav entry exist, with `slots.form-dialog: "ContextFormModal"`, mirroring the `SkillsCatalog` page; triggering create/edit opens the editor and the list reflects the saved Context.
- [ ] Implement
- [ ] Test

## Quality checklist

- New/changed backend logic covered by PHPUnit (`tests/Unit/` — `ContextAssemblerTest` extended for documents rendering, malformed-entry tolerance, budget accounting, and the no-documents identity case).
- UI change (`ContextFormModal`, manifest page) covered by a Playwright browser test: create a Context with a document, edit it, verify persistence.
- No new API endpoint — create/edit uses the generic OpenRegister object write path; no Newman collection needed.
- Dutch (`nl_NL`) and English (`en_US`) strings added for all new user-facing text (ADR-007); i18n keys stay English.
- Manifest re-validated (`slots.form-dialog` + schema `context` page); `openspec validate` passes.
- Feature documentation updated in `docs/` for the new Context authoring surface (ADR-010).
