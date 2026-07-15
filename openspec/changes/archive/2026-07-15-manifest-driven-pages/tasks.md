# Tasks: manifest-driven-pages

## Phase 1 — Agent detail widget grid (PR 1)

### Task 1: Extract AgentDetail's bespoke sections into standalone widget components
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-003-an-agent-scoped-run-kpi-custom-widget-shows-this-agents-run-totals`
- **files**: `src/widgets/AgentKpiWidget.vue`, `src/widgets/AgentSkillsWidget.vue`, `src/widgets/AgentToolGovernanceWidget.vue`, `src/widgets/AgentRunOperationsWidget.vue`, `src/widgets/AgentRunHistoryWidget.vue`
- **acceptance_criteria**:
  - GIVEN `AgentKpiWidget` is mounted on an agent's route WHEN it loads THEN it calls `/api/analytics` scoped to `$route.params.id`, not tenant-wide
  - GIVEN `AgentToolGovernanceWidget` is mounted WHEN it renders THEN it shows `ToolGrantEditor` and `ToolInvocationTable` stacked in one widget
  - GIVEN `AgentRunOperationsWidget` is mounted WHEN the user triggers Dry run, Run now, or Replay THEN `previewResult`/`runError` state stays consistent across all three actions in this one component
  - GIVEN `AgentRunHistoryWidget` is mounted WHEN a row's "Details" is clicked THEN its trace is fetched once and cached, and "Re-run" only shows on `dead_letter` rows
- [x] Implement
- [x] Test

### Task 2: Register the extracted widgets and AgentMemoryPanel in the v2 registry
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-004-a-skills-attach-or-detach-custom-widget-manages-the-agents-skill-installs`
- **files**: `src/registry.js`
- **acceptance_criteria**:
  - GIVEN `src/registry.js` WHEN inspected THEN it has `kind:"widget"` entries for `agent-kpis`, `agent-skills`, `agent-tool-governance`, `agent-run-operations`, `agent-run-history`, and `agent-memory` (wrapping the existing `AgentMemoryPanel.vue` unchanged), each with `defaultSize`/`minSize`/`maxSize`/`allowedSlots`/`propsSchema`
  - GIVEN `npm run check:registry` WHEN run THEN it passes
- [x] Implement
- [x] Test

### Task 3: Merge AgentVersionDiffDialog into AgentVersionHistoryDialog and register the modals
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-008-header-actions-open-their-modal-via-a-registry-resolved-open-modal-action`
- **files**: `src/dialogs/agents/AgentVersionHistoryDialog.vue`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN `AgentVersionHistoryDialog` WHEN the user selects two versions and clicks Compare THEN the diff renders from within the same component, with no parent-provided `AgentVersionDiffDialog` needed
  - GIVEN `src/registry.js` WHEN inspected THEN it has `kind:"modal"` entries for `agent-form` (`AgentFormModal`), `agent-version-history` (the merged dialog), and `agent-factsheet` (`AgentFactsheetDialog`), each with `propsSchema`
- [x] Implement
- [x] Test

### Task 4: Rewrite the AgentDetail manifest page as type:"detail"
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-001-agentdetail-renders-as-a-detail-type-widget-grid`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the `AgentDetail` page entry WHEN inspected THEN `type:"detail"`, `config.register:"hermiq"`, `config.schema:"agent"`
  - GIVEN `config.widgets[]` WHEN inspected THEN it has one `type:"data"` widget (`content.columns:2`, `include` excludes `tools` + the existing hidden fields) and six `type:"custom"` widgets mapped via `page.slots` to Task 1/2's registry keys
  - GIVEN `config.layout[]` WHEN inspected THEN every widget's `gridHeight` matches its content (no inner scrollbars, no reserved voids — ADR-062)
  - GIVEN `page.actions[]` WHEN inspected THEN it has three `type:"open-modal"` entries targeting `agent-form`, `agent-version-history`, `agent-factsheet`
- [x] Implement
- [x] Test

### Task 5: Delete AgentDetail.vue and its registry entries; update the e2e assertion
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-012-manifest-and-registry-changes-keep-checkspecs-and-lint-green`
- **files**: `src/views/AgentDetail.vue`, `src/registry.js`, `src/customComponents.js`, `tests/e2e/dashboard-and-agents.spec.ts`
- **acceptance_criteria**:
  - GIVEN `src/views/AgentDetail.vue` is deleted WHEN `npm run check:registry` and `npm run lint` run THEN both pass (no orphan import)
  - GIVEN `tests/e2e/dashboard-and-agents.spec.ts` WHEN updated THEN it asserts on `CnPageRenderer`'s `data-testid-page-id="AgentDetail"` (or the page's rendered header text) instead of the removed bespoke testids
- [x] Implement
- [x] Test

## Phase 2 — List pages (PR 2)

### Task 6: Convert AgentCatalog to type:"index"
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-009-agentcatalog-renders-as-an-index-type-list-page`
- **files**: `src/manifest.json`, `src/registry.js`, `src/views/AgentCatalog.vue`, `src/customComponents.js`, `tests/e2e/dashboard-and-agents.spec.ts`
- **acceptance_criteria**:
  - GIVEN the `AgentCatalog` page entry WHEN inspected THEN `type:"index"`, `config.register:"hermiq"`, `config.schema:"agent"`, columns `name`/`model`, `rowRoute:"AgentDetail"`, `config.headerActions[]` = create-agent (`open-modal`→`agent-form`) + browse-templates (navigate)
  - GIVEN `src/views/AgentCatalog.vue` is deleted WHEN `npm run check:registry` runs THEN it passes
  - GIVEN `tests/e2e/dashboard-and-agents.spec.ts` WHEN updated THEN its agent-catalog assertions target the new page's stable testid/heading text
- [x] Implement
- [x] Test

### Task 7: Build the AgentTemplateGallery row-actions widget and register its modals
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-010-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints`
- **files**: `src/widgets/AgentTemplateRowActions.vue`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN `AgentTemplateRowActions` WHEN rendered for a `quarantined` row THEN it shows Approve calling the existing `approveAgentTemplate()` API (never a declarative patch on `state`)
  - GIVEN `AgentTemplateRowActions` WHEN "Use this template" is clicked THEN it calls `instantiateAgentTemplate()` and shows the model-coercion/unresolved-skill note before navigating
  - GIVEN `src/registry.js` WHEN inspected THEN `template-import` (`TemplateImportModal`) is registered `kind:"modal"` and `agent-template-row-actions` is registered `kind:"widget"`
- [x] Implement
- [x] Test

### Task 8: Convert AgentTemplateGallery to type:"index"
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-010-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints`
- **files**: `src/manifest.json`, `src/views/AgentTemplateGallery.vue`, `src/customComponents.js`, `tests/e2e/wave2-surfaces.spec.ts`
- **acceptance_criteria**:
  - GIVEN the `AgentTemplateGallery` page entry WHEN inspected THEN `type:"index"`, `config.register:"hermiq"`, `config.schema:"agenttemplate"`, columns `name`/`category`/`description`/`state`, `page.slots.row-actions:"agent-template-row-actions"`, `config.headerActions[]` = import-template (`open-modal`→`template-import`)
  - GIVEN `src/views/AgentTemplateGallery.vue` is deleted WHEN `npm run check:registry` runs THEN it passes
  - GIVEN `tests/e2e/wave2-surfaces.spec.ts` WHEN updated THEN its `agent-template-gallery-heading` assertion targets the new page
- [x] Implement
- [x] Test

### Task 9: Split EvalDatasets into an index page and a new EvalDatasetDetail page
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-011-evaldatasets-renders-as-an-index-type-list-page-with-per-dataset-run-management-on-a-new-evaldatasetdetail-page`
- **files**: `src/widgets/EvalRunPanelWidget.vue`, `src/manifest.json`, `src/registry.js`, `src/views/EvalDatasets.vue`, `src/customComponents.js`, `tests/e2e/wave2-surfaces.spec.ts`
- **acceptance_criteria**:
  - GIVEN `EvalRunPanelWidget` WHEN mounted on `/evals/:id` THEN it shows the agent picker, Run action (calling the existing `runEval()`), and this dataset's run history
  - GIVEN the `EvalDatasets` page entry WHEN inspected THEN `type:"index"`, columns `name`/`description`, `rowRoute:"EvalDatasetDetail"`, `config.headerActions[]` = new-dataset (`open-modal`→`EvalDatasetFormModal`)
  - GIVEN a new `EvalDatasetDetail` page entry WHEN inspected THEN `type:"detail"`, `config.register:"hermiq"`, `config.schema:"evaldataset"`, one `type:"custom"` widget mapped to `eval-run-panel`
  - GIVEN `src/views/EvalDatasets.vue` is deleted WHEN `npm run check:registry` runs THEN it passes
  - GIVEN `tests/e2e/wave2-surfaces.spec.ts` WHEN updated THEN its `evals-heading` assertion targets the new index page
- [x] Implement
- [x] Test

### Task 10: l10n additions and final validation pass
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-012-manifest-and-registry-changes-keep-checkspecs-and-lint-green`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN every new/changed user-facing string across Tasks 1–9 WHEN checked THEN it has an English key in `l10n/en.json` and a Dutch translation in `l10n/nl.json`
  - GIVEN `npm run check:specs`, `npm run lint`, and `npm test` WHEN run THEN all three pass with zero new failures
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by Vitest/Jest unit tests where Hermiq already has coverage for the extracted component
- UI changes covered by the updated Playwright e2e specs (`tests/e2e/dashboard-and-agents.spec.ts`, `tests/e2e/wave2-surfaces.spec.ts`)
- All tests pass (`npm run check:specs`, `npm run lint`, `npm test`)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-005)
- `openspec validate manifest-driven-pages --type change --strict` passes
- No `type:"handler"`/`type:"api-call"`/`object-op` action types introduced (design.md Decisions 6–7)
