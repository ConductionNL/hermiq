# Test Plan: manifest-driven-pages

## Test Cases

### TC-1: AgentDetail renders as a detail-type widget grid with no reserved voids
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-001-agentdetail-renders-as-a-detail-type-widget-grid`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator) — the primary Hermiq operator persona
- **preconditions**: An agent exists with a schedule, run history, installed skills, and tool grants
- **steps**: Log in, navigate to `/agents/:id`
- **expected result**: The page renders via `CnDetailPage`; every widget fills its declared `gridWidth`/`gridHeight` with no inner scrollbar and no empty grid cell
- **test command**: `/test-functional`

### TC-2: A missing agent id shows a graceful empty state
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-001-agentdetail-renders-as-a-detail-type-widget-grid`
- **type**: functional
- **persona**: n/a
- **preconditions**: An agent id that does not exist
- **steps**: Navigate to `/agents/00000000-0000-0000-0000-000000000000`
- **expected result**: An empty/not-found state renders; no unhandled JS error in the console
- **test command**: `/test-functional`

### TC-3: Editing a scalar config field in place persists
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-002-agent-core-config-fields-are-editable-via-a-data-widget-with-tools-excluded`
- **type**: functional
- **persona**: Priya
- **preconditions**: An agent detail page is loaded
- **steps**: Click the `model` field in the data widget, change its value, confirm
- **expected result**: The value persists via the agent store; reloading the page shows the new value; `tools` does not appear in this widget
- **test command**: `/test-functional`

### TC-4: Editing tools still works through the existing modal
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-002-agent-core-config-fields-are-editable-via-a-data-widget-with-tools-excluded`
- **type**: functional
- **persona**: Priya
- **preconditions**: An agent detail page is loaded, tool catalogue reachable
- **steps**: Click "Edit agent", change the enabled-tools selection, save
- **expected result**: `AgentFormModal` opens pre-filled; saving updates the agent's `tools`; the data widget's display reflects the change after reload
- **test command**: `/test-functional`

### TC-5: Agent-scoped KPI widget shows only this agent's totals
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-003-an-agent-scoped-run-kpi-custom-widget-shows-this-agents-run-totals`
- **type**: api
- **persona**: n/a
- **preconditions**: Two agents, each with distinct run counts
- **steps**: Call `GET /apps/hermiq/api/analytics?agentId=<agent-A>` and compare against the tenant-wide `RunAnalytics` totals
- **expected result**: The agent-scoped response's `totalRuns` differs from (and is a subset of) the tenant-wide total
- **test command**: `/test-api`

### TC-6: Skills attach/detach updates skillInstalls
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-004-a-skills-attach-or-detach-custom-widget-manages-the-agents-skill-installs`
- **type**: functional
- **persona**: Priya
- **preconditions**: A tenant skill not yet installed on the agent
- **steps**: Select the skill in the attach picker, click Attach; then click Detach on an installed skill
- **expected result**: The agent's `skillInstalls` gains then loses the skill's uuid; the widget's lists update without a full page reload
- **test command**: `/test-functional`

### TC-7: Tool-governance widget enforces owner-only edit
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-005-a-tool-governance-custom-widget-must-combine-tool-grants-and-tool-activity-audit-history`
- **type**: security
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: Two users — the agent's owner and a non-owner with view access
- **steps**: As the non-owner, open the tool-governance widget and attempt to change a grant
- **expected result**: The grant editor is read-only client-side; a forged PUT from the non-owner is also rejected server-side (existing behaviour, unchanged)
- **test command**: `/test-security`

### TC-8: Dry run never invokes a side-effecting tool
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-006-a-run-operations-custom-widget-combines-schedule-dry-run-run-now-budget-and-webhook`
- **type**: functional
- **persona**: Priya
- **preconditions**: An agent with an attached schedule and at least one side-effecting tool granted
- **steps**: Click "Dry run" in the run-operations widget
- **expected result**: The preview shows `would-have-called` for the side-effecting tool; no external call is actually made; the preview renders within the same widget
- **test command**: `/test-functional`

### TC-9: Run now surfaces a graceful, dismissible error
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-006-a-run-operations-custom-widget-combines-schedule-dry-run-run-now-budget-and-webhook`
- **type**: functional
- **persona**: Priya
- **preconditions**: An agent whose execution engine is configured to fail this run (test fixture)
- **steps**: Click "Run now"
- **expected result**: The page does not crash; a dismissible error note renders in the run-operations widget; the run is recorded in run history
- **test command**: `/test-functional`

### TC-10: Run-history trace expand fetches once and caches
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-007-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay`
- **type**: functional
- **persona**: Noor
- **preconditions**: A completed run with a recorded trace
- **steps**: Click "Details" on the run row, collapse, expand again; observe network requests
- **expected result**: The trace endpoint is called exactly once across both expansions
- **test command**: `/test-functional`

### TC-11: Re-run only appears on dead-letter rows and reuses the run-now path
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-007-a-run-history-custom-widget-must-show-run-history-with-per-row-trace-expand-re-run-and-replay`
- **type**: functional
- **persona**: Priya
- **preconditions**: A run history containing one `dead_letter` row and one `ok` row
- **steps**: Inspect both rows' actions; click Re-run on the `dead_letter` row
- **expected result**: "Re-run" is visible only on the `dead_letter` row; clicking it POSTs to the same run-now endpoint the page-level button uses
- **test command**: `/test-functional`

### TC-12: Header actions open the correct registry modal
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-008-header-actions-open-their-modal-via-a-registry-resolved-open-modal-action`
- **type**: functional
- **persona**: Priya
- **preconditions**: An agent detail page is loaded
- **steps**: Trigger "Edit agent", close it; trigger "Version history", select two versions, click Compare; trigger "View compliance factsheet"
- **expected result**: Each action opens its target modal; Compare renders the diff from within the version-history modal itself (no separate parent-mounted dialog)
- **test command**: `/test-functional`

### TC-13: AgentCatalog list, sort, and navigate
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-009-agentcatalog-renders-as-an-index-type-list-page`
- **type**: functional
- **persona**: Priya
- **preconditions**: At least three agents exist
- **steps**: Navigate to `/agents`, sort by name, click a row
- **expected result**: The list renders via `CnIndexPage`; sorting works; clicking a row navigates to that agent's `/agents/:id`
- **test command**: `/test-functional`

### TC-14: Create agent and browse templates from the list header
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-009-agentcatalog-renders-as-an-index-type-list-page`
- **type**: functional
- **persona**: Janwillem (Small Business Owner) — a first-time Hermiq user
- **preconditions**: `/agents` is loaded
- **steps**: Click "Create agent", fill and save; separately click "Browse templates"
- **expected result**: The new agent appears in the list; "Browse templates" navigates to `/agent-templates`
- **test command**: `/test-functional`

### TC-15: Approve a quarantined template still enforces the authorization gate
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-010-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints`
- **type**: security
- **persona**: Noor
- **preconditions**: A quarantined template; a user WITHOUT the `agenttemplate.approve-quarantined` action grant
- **steps**: As that user, click "Approve" on the quarantined row
- **expected result**: The request is rejected server-side (403); the row remains `quarantined`; confirm via API inspection that no `object-op` PATCH request was ever issued for `state`
- **test command**: `/test-security`

### TC-16: Use this template surfaces model-coercion notes
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-010-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints`
- **type**: functional
- **persona**: Mark (MKB Software Vendor)
- **preconditions**: A template whose suggested model is outside the caller's tenant model policy
- **steps**: Click "Use this template" on that row
- **expected result**: A note describing the model substitution renders before navigating; clicking "Open agent" navigates to the newly instantiated agent
- **test command**: `/test-functional`

### TC-17: Export produces the existing package format
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-010-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints`
- **type**: functional
- **persona**: Mark
- **preconditions**: An active template
- **steps**: Click "Export" on that row
- **expected result**: The export modal shows the same JSON package shape as before the conversion
- **test command**: `/test-functional`

### TC-18: EvalDatasets list and per-dataset run panel
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-011-evaldatasets-renders-as-an-index-type-list-page-with-per-dataset-run-management-on-a-new-evaldatasetdetail-page`
- **type**: functional
- **persona**: Priya
- **preconditions**: An eval dataset with at least one case and one agent available
- **steps**: Navigate to `/evals`, confirm no per-row run controls, click a row to reach `/evals/:id`, select an agent and click Run
- **expected result**: The list shows only `name`/`description`; the detail page's `eval-run-panel` widget runs the dataset and shows the new run's pass rate and regression-gate result
- **test command**: `/test-functional`

### TC-19: check:specs, lint, and unit tests stay green
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-012-manifest-and-registry-changes-keep-checkspecs-and-lint-green`
- **type**: regression
- **persona**: n/a
- **preconditions**: All Phase 1 + Phase 2 tasks implemented
- **steps**: Run `npm run check:specs`, `npm run lint`, `npm test`
- **expected result**: All three commands exit 0; no orphan `customComponents.js`/`registry.js` imports
- **test command**: `/test-regression`

### TC-20: e2e specs pass against the converted pages
- **spec_ref**: `openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-012-manifest-and-registry-changes-keep-checkspecs-and-lint-green`
- **type**: regression
- **persona**: n/a
- **preconditions**: A live dev instance with the converted manifest deployed
- **steps**: Run `tests/e2e/dashboard-and-agents.spec.ts` and `tests/e2e/wave2-surfaces.spec.ts`
- **expected result**: Both specs pass with their updated selectors; zero console errors during the flows
- **test command**: `/test-regression`

## Coverage Summary

- REQ-001 (detail grid renders) — covered: TC-1, TC-2
- REQ-002 (data widget, tools excluded) — covered: TC-3, TC-4
- REQ-003 (agent-scoped KPIs) — covered: TC-5
- REQ-004 (skills attach/detach) — covered: TC-6
- REQ-005 (tool governance) — covered: TC-7
- REQ-006 (run operations) — covered: TC-8, TC-9
- REQ-007 (run history) — covered: TC-10, TC-11
- REQ-008 (header actions/open-modal) — covered: TC-12
- REQ-009 (AgentCatalog index) — covered: TC-13, TC-14
- REQ-010 (AgentTemplateGallery index + guarded actions) — covered: TC-15, TC-16, TC-17
- REQ-011 (EvalDatasets split) — covered: TC-18
- REQ-012 (specs/lint green) — covered: TC-19, TC-20

## Out of Scope

- Persona flows for `Chat`, `ApprovalInbox`, `AgentMemory`, `AgentSessions`, `SkillsCatalog`,
  `AiFeatureRegister`, `TenantOps`, `McpTools`, `ComplianceDashboard` — unchanged by this
  change (see proposal.md Out of Scope).
- Load/performance testing of the analytics endpoint under high run volume — pre-existing
  behaviour, not introduced by this conversion.
- Accessibility audit beyond the `inputLabel` check already carried by the existing
  `NcSelect` usages (unchanged markup, just relocated into widgets).
