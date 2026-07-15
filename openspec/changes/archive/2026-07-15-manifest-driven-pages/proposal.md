# Proposal: manifest-driven-pages

## Summary

Converts Hermiq's bespoke `type:"custom"` Agent detail and list pages to manifest-driven
pages rendered by the nc-vue manifest-v2 renderer, matching the fleet convention (procest
has 42 `type:"detail"` grid pages, pipelinq 24; Hermiq currently has 0). Phase 1 converts
`AgentDetail` (`/agents/:id`) from a single hand-stacked Vue template into a `type:"detail"`
widget grid, extracting its genuinely-bespoke sections into small, individually-justified
`type:"custom"` escape-hatch widgets (ADR-036/049) instead of one opaque page. Phase 2
converts `AgentCatalog` (`/agents`), `AgentTemplateGallery` (`/agent-templates`), and
`EvalDatasets` (`/evals`, split into a list + a new `EvalDatasetDetail`) to `type:"index"`
list pages, again preserving their essential bespoke actions as small justified custom
surfaces.

## Motivation

A `type:"custom"` page is architecturally opaque to the fleet's shared tooling: it cannot
be laid out via the declarative widget grid, cannot be audited by the
`hydra-gate-custom-widget-ratchet` / `hydra-gate-detail-page-discipline` gates the way a
`type:"detail"`/`type:"index"` page can, and every interaction — including plain
schema-field display — is hand-rolled Vue instead of the shared `CnObjectDataWidget` /
`CnIndexPage` machinery every other app relies on. `AgentDetail.vue` alone is a single
~1570-line component stacking nine sections vertically with no grid discipline (ADR-062:
"the cell is the budget" — every widget should fill its `gridWidth`/`gridHeight` exactly,
with no inner scrollbars and no reserved voids). `AgentCatalog`, `AgentTemplateGallery`,
and `EvalDatasets` each re-implement list rendering that the shared `CnIndexPage` already
provides for free (sort, search, pagination, mass actions, standard create/edit dialogs).

Converting these pages is the highest-leverage step in the broader design-alignment effort
because the Agent detail page is Hermiq's single most-visited screen, and because these are
the pages new users hit first — while `Chat`, `ApprovalInbox`, `AgentMemory`, `AgentSessions`,
`SkillsCatalog`, `AiFeatureRegister`, `TenantOps`, `McpTools`, and `ComplianceDashboard` are
already carrying deliberate, spec-reviewed `_note` justifications in `src/manifest.json` for
staying `type:"custom"` (each documents a specific non-object-CRUD capability), so re-litigating
them is out of scope here (see Deferred Decisions).

## Affected Projects

- [ ] Project: `hermiq` — `src/manifest.json`, `src/registry.js`, `src/customComponents.js`,
  new `src/widgets/Agent*.vue` + `src/widgets/EvalRunPanelWidget.vue`, a merged
  `AgentVersionHistoryDialog.vue`, deletion of `src/views/AgentDetail.vue` /
  `AgentCatalog.vue` / `AgentTemplateGallery.vue` / `EvalDatasets.vue`, a new
  `EvalDatasetDetail` manifest page, `l10n/en.json` + `l10n/nl.json`, and
  `tests/e2e/dashboard-and-agents.spec.ts` + `tests/e2e/wave2-surfaces.spec.ts` selector
  updates.

## Scope

### In Scope

- `AgentDetail` (`/agents/:id`) converted to `type:"detail"` (`config.register:"hermiq"`,
  `config.schema:"agent"`) with a `data` widget for the agent's core config fields and six
  justified `type:"custom"` content widgets (KPIs, skills, memory, tool governance, run
  operations, run history) resolved via `page.slots`, plus three page-level header actions
  (`type:"open-modal"`) replacing the header buttons.
- `AgentCatalog` (`/agents`) converted to `type:"index"` (register `hermiq`, schema `agent`).
- `AgentTemplateGallery` (`/agent-templates`) converted to `type:"index"` (register `hermiq`,
  schema `agenttemplate`) with a small custom row-actions widget for "Use this template" /
  "Approve" / "Export".
- `EvalDatasets` (`/evals`) converted to `type:"index"` (register `hermiq`, schema
  `evaldataset`); a new `EvalDatasetDetail` (`/evals/:id`, `type:"detail"`) hosts the
  per-dataset agent-picker + Run + run-history surface that does not fit a flat list.
- Registering the modals/widgets these pages already use (`AgentFormModal`,
  `AgentFactsheetDialog`, a merged version-history modal, `TemplateImportModal`,
  `EvalDatasetFormModal`) in `src/registry.js` as `kind:"modal"` / `kind:"widget"` entries.
- Updating the two e2e specs that assert on the converted pages' bespoke `data-testid`s.

### Out of Scope

- Re-litigating `Chat`, `ApprovalInbox`, `AgentMemory`, `AgentSessions`, `SkillsCatalog`,
  `AiFeatureRegister`, `TenantOps`, `McpTools`, `ComplianceDashboard` — each already carries a
  spec-reviewed `_note` justifying `type:"custom"` at HEAD; a future change can revisit them
  individually.
- The nav/settings restructuring (Settings menu, `ai-features`→NC admin settings, Tenant ops
  scoping, Dashboard schedules/agents-in-use widgets) — owned by sibling areas
  (`ai-features-to-admin`, `dashboard-org-widgets`) in this same design-alignment effort.
- Any change to the OpenRegister `agent` / `schedule` / `agenttemplate` / `evaldataset` /
  `evalrun` schema shapes themselves (field additions/removals) beyond what's needed to
  register the pages.
- Adding a reverse-FK ("does this agent have a schedule attached", "this dataset's latest
  pass rate") calculation primitive to OpenRegister — deferred (see design.md).
- Adopting the manifest's `type:"handler"` / `type:"api-call"` action types anywhere in
  Hermiq — verified unused fleet-wide (see design.md); this change does not pioneer them.

## Approach

Two phases, landed as separate PRs but specified as one change:

**Phase 1 (priority — the stacked-layout complaint).** Extract `AgentDetail.vue`'s bespoke
sections into standalone `src/widgets/*.vue` components (most already exist as standalone
files — `AgentMemoryPanel`, `ToolGrantEditor`, `ToolInvocationTable` — and only need
registering; the schedule/run-now/dry-run/budget/webhook cluster and the run-history table
are extracted as two new cohesive widgets because their state is tightly coupled and the
declarative grid has no sibling-widget data-sharing mechanism). Register them in
`src/registry.js` as `kind:"widget"` with the required `defaultSize`/`minSize`/`maxSize`/
`allowedSlots`/`propsSchema` metadata (mirroring the existing `analytics-kpis` /
`analytics-breakdown` entries). Rewrite the `AgentDetail` manifest page as `type:"detail"`
with a `config.widgets[]` + `config.layout[]` grid (procest `CaseDetail` shape) plus three
`page.actions[]` header buttons wired to `type:"open-modal"`.

**Phase 2 (list pages).** Convert `AgentCatalog`, `AgentTemplateGallery`, and `EvalDatasets`
to `type:"index"`, each verified against its actual read path (all three already fetch
through the generic OpenRegister objects endpoint via `createObjectStore`/`registerObjectType`
— `AgentTemplateService::list()` is a bare `findAll()` with zero extra scoping, so the switch
is read-equivalent) and each preserving its write actions through their existing guarded API
calls (never re-implemented as a raw `object-op`, since e.g. approving a quarantined template
gates through `ActionAuthService::requireAction('agenttemplate.approve-quarantined')` server-side
— a declarative patch would silently bypass that gate).

## New Dependencies

None.

## Impact

- `src/manifest.json` — `AgentDetail`, `AgentCatalog`, `AgentTemplateGallery`, `EvalDatasets`
  page entries rewritten; a new `EvalDatasetDetail` page added.
- `src/registry.js` — up to 9 new entries (`kind:"widget"` × 7, `kind:"modal"` × 3, one
  reused across both phases: `AgentFormModal`).
- `src/customComponents.js` — the four removed pages' entries deleted.
- `src/views/AgentDetail.vue`, `AgentCatalog.vue`, `AgentTemplateGallery.vue`,
  `EvalDatasets.vue` — deleted (logic redistributed into `src/widgets/*.vue`).
- `src/dialogs/agents/AgentVersionHistoryDialog.vue` — absorbs
  `AgentVersionDiffDialog`'s mount so the pair registers as one self-contained modal.
- `l10n/en.json`, `l10n/nl.json` — new widget/header-action strings.
- `tests/e2e/dashboard-and-agents.spec.ts`, `tests/e2e/wave2-surfaces.spec.ts` — selector
  updates for the converted pages' new DOM shape.

## Cross-Project Dependencies

None — this change is internal to Hermiq's own manifest/frontend. It follows conventions
already shipped in `nextcloud-vue` (manifest-v2 renderer, `type:"detail"`/`type:"index"`,
`page.slots`, `type:"open-modal"` actions) without requiring any nc-vue change.

## Risks

### Risk 1: Custom-widget count rises even as the custom-page surface shrinks

**Severity:** Medium — **Mitigation:** `AgentDetail` moves from ONE opaque `type:"custom"`
page to a `type:"detail"` grid with six justified `type:"custom"` content widgets, which
looks like more custom surface by raw count but is a net reduction in opacity: each widget
is now individually gridded, individually justified in its registry `_note`, and individually
auditable by `hydra-gate-custom-widget-ratchet` / `hydra-gate-detail-page-discipline`, versus
one 1570-line component the gates cannot see inside. Flagged explicitly for the reviewer
rather than hidden.

### Risk 2: Dropping the derived "schedule attached" / "last run" list columns is a minor UX regression

**Severity:** Low — **Mitigation:** these columns require a reverse-FK join
(`Schedule.agentId` → `Agent`) that OpenRegister's calculation engine does not support (it
computes forward references only, per `procest`'s own `x-openregister-calculations` usage).
Rather than fabricate an unsupported join, the columns are dropped from the list; the same
information is now one click away on `AgentDetail`'s run-operations widget, which is a
richer surface than the old list badge ever was.

### Risk 3: e2e tests break on the DOM-shape change

**Severity:** Low — **Mitigation:** `tests/e2e/dashboard-and-agents.spec.ts` and
`tests/e2e/wave2-surfaces.spec.ts` currently assert on bespoke `data-testid`s
(`agent-catalog-heading`, `agent-template-gallery-heading`, `evals-heading`) that disappear
with the custom components. Both specs are updated in the same PR as the page they assert on
(tasks 5, 7, 9), asserting on `CnPageRenderer`'s stable `data-testid-page-id` instead.

## Rollback Strategy

Each phase is an isolated PR touching only `src/manifest.json` + `src/registry.js` +
`src/customComponents.js` + the affected `src/views/*.vue` / new `src/widgets/*.vue`. Revert
is a straight `git revert` of the phase's merge commit — the removed `type:"custom"` view
components are recoverable from git history, and no OpenRegister schema or data shape
changes, so there is no data migration to unwind.

## Open Questions

None — the design decisions above (widget grid shape, which sections stay custom, the
list-page read-path equivalence, and the approve-action security constraint) were resolved
by reading the current code at HEAD rather than left open; see design.md Decisions and
DEFERRED_DECISIONS for the full reasoning trail.
