# Design: manifest-driven-pages

## Architecture Overview

Today, `CnPageRenderer` dispatches `AgentDetail`/`AgentCatalog`/`AgentTemplateGallery`/
`EvalDatasets` straight to a `type:"custom"` Vue component resolved from
`src/customComponents.js` — the renderer has no visibility into what's inside. After this
change, `CnPageRenderer` dispatches `AgentDetail`/`EvalDatasetDetail` to the library's
`CnDetailPage` (driven by `config.register`/`config.schema`/`config.widgets[]`/
`config.layout[]`) and `AgentCatalog`/`AgentTemplateGallery`/`EvalDatasets` to `CnIndexPage`
(driven by `config.register`/`config.schema`/`config.columns`/`config.headerActions`/
`config.actions`). Both built-ins still delegate to app-supplied components for the pieces
that are genuinely bespoke — `CnDetailPage` via `config.widgets[].type:"custom"` +
`page.slots`, `CnIndexPage` via `page.slots.row-actions` — but everything else (layout,
click-to-edit data grid, table sort/search/pagination, standard create dialogs) is now the
shared library code, not hand-rolled Vue.

```
Before:  CnPageRenderer → type:"custom" → AgentDetail.vue (1570 lines, opaque)
After:   CnPageRenderer → type:"detail" → CnDetailPage
                                            ├─ data widget            (agent-core, built-in)
                                            ├─ custom widget × 6      (page.slots → registry)
                                            └─ 3 header actions       (open-modal → registry)
```

## Two widget systems in the v2 manifest schema (verified at HEAD)

There are two independent widget-placement mechanisms in
`nextcloud-vue/src/schemas/app-manifest-v2.schema.json`, and picking the right one matters:

1. **Top-level `pages[].widgets[]`** — uniform grid placement (`widgetKey`, `slot`, `gridX/Y/
   Width/Height`), available on every page type. `widgetKey` resolves against the app
   registry OR a small library built-in-exempt list (`object-table`, `card-grid`,
   `form-renderer`, `map-viewer`, `chart`, `stats-block`, `banner`, `audit-trail`, `header`,
   `text`, `divider`). Hermiq's own `RunAnalytics`/`Dashboard` pages already use this system.
2. **`config.widgets[]` + `config.layout[]`** — the `type:"detail"` page's own content-widget
   vocabulary (`type` ∈ `data | stats-block | related | object-list | integration | chart |
   custom`), interpreted by `CnDetailPage` itself. `type:"custom"` is the only member
   resolved through the app registry, via a `page.slots` map (e.g.
   `"slots": {"widget-initiator": "InitiatorSection"}` — procest's exact `CaseDetail`
   pattern).

`data`, `object-list`, `related`, and `integration` are **not** in system 1's built-in-exempt
list — they only exist in system 2. Every `type:"detail"` page in the fleet (procest's
`CaseDetail`/`BezwaarDetail`, pipelinq's `LeadDetail`, etc.) uses system 2 exclusively; no
detail page in the fleet combines both. `AgentDetail`/`EvalDatasetDetail` therefore use
system 2 exclusively, matching the only precedented shape.

## Decisions

### Decision 1: AgentDetail's core config becomes ONE `data` widget; `tools` moves fully to the existing modal

The current `CnObjectDataWidget` already IS the mechanism behind the `data` widget type
(same click-to-edit grid), so this is a direct swap. The one field that doesn't move
cleanly is `tools`: the current page feeds it through a `#field-tools` slot on
`CnObjectDataWidget` (an `NcSelect` fed by the live `/api/agents/tools` catalogue, since the
schema carries no static enum). The manifest `data` widget's declarative `content.include[]`
has no per-field slot-override hook. `AgentFormModal` already has a complete, working
`tools` editor (same `NcSelect` + catalogue) used both for create (`AgentCatalog`) and edit
— so `tools` is simply excluded from the `data` widget's `include[]` (joining the existing
hidden-fields list: `configuration`, `views`, `invitedUsers`, `groups`, `isPrivate`,
`requestQuota`, `tokenQuota`, `actingUser`, `skillInstalls`, `contextRefs`) and edited only
via the header "Edit agent" action, which opens `AgentFormModal`. No new UI is built; an
existing, already-correct editor is reused instead of inventing a declarative tools-picker
primitive for one field.

**Alternative considered:** add a `fieldWidget` override for `tools`. Rejected — the schema's
`fieldWidget` concept explicitly resolves only against the **library's** exported component
map, not the host app's registry (verified in the schema's `fieldWidget` description), so a
Hermiq-specific `NcSelect`-over-a-live-catalogue widget cannot be expressed there without a
nc-vue change. Out of scope.

### Decision 2: run history and the agent's schedule stay custom — `object-list` does not fit either

The brief's working assumption was that FK-scoped `object-list` widgets could cover "run
history and schedules." Verified at HEAD in `src/api/agents.js`: `listRuns()` reads
"a schedule's run history (owner-scoped OpenRegister **AuditTrail** entries)" — runs are not
addressable via `register`+`schema`+`filter` at all, so `object-list` (which requires a real
OR schema collection) cannot express them regardless of cardinality. Separately, `Schedule`
is a 1:1 relationship per agent in practice (`AgentDetail.vue`'s own `.find()` on the
schedule collection), and schedule management is all WRITE actions (attach/edit, dry-run,
run-now, re-run, replay) that `object-list` (a read-only child-collection browser) cannot
express either way. Both stay custom widgets; this is a correction to the brief, grounded in
code, not a deviation from it.

### Decision 3: six custom widgets, not one — grouped by shared state, not by visual section

`AgentDetail.vue`'s nine template sections collapse into six `type:"custom"` widgets because
several bundles of UI share tightly-coupled reactive state that the declarative grid has no
mechanism to wire between independent sibling widgets:

| Widget | Absorbs | Why one widget |
|---|---|---|
| `agent-kpis` | total runs / success rate / latency / tokens | New widget, but tiny — reuses `api/analytics.js` `getAnalytics(agentId)` already used by `RunAnalytics`'s KPI widget, just scoped via the route param instead of tenant-wide. Not a `stats-block` — the analytics endpoint is computed, not an OR object count (same ADR-049 rationale already documented for `analytics-kpis` on the `RunAnalytics` dashboard). |
| `agent-skills` | attach/detach section | `skillInstalls` is an array-of-uuid field on `Agent` referencing an independent `Skill` catalogue — the reverse of an `object-list`'s FK-child-collection shape, so it can't be expressed declaratively either. |
| `agent-memory` | `AgentMemoryPanel` | Already a standalone component (used verbatim on the standalone `/memory` page); registering it as-is needs no extraction. |
| `agent-tool-governance` | `ToolGrantEditor` + `ToolInvocationTable` | Both are read/write surfaces over the SAME capability (ADR-063 derived tool catalogue: grants + the audit trail of their use) — merged into one widget so the grid gets one coherent "tool governance" cell instead of two thin strips. |
| `agent-run-operations` | schedule attach/edit, dry-run, run-now, cost estimate, budget status, webhook trigger, dry-run/replay preview | These seven pieces all read or write the SAME `schedule` object and share `previewResult`/`runError` state across dry-run, run-now, and replay. Splitting them across independent grid widgets would require a cross-widget state channel the manifest grid doesn't have (widgets don't share props/events with siblings). Consolidated into one widget rather than fragmenting a tightly-coupled state machine. |
| `agent-run-history` | run history table, per-row trace expand, re-run, replay, download-trace | `object-list`'s static `columns[]`/`rowRoute` shape has no per-row expand-in-place, no per-row conditional action set (re-run only on `dead_letter`), and no trace fetch-and-cache. Genuinely bespoke interaction (ADR-049). |

**Alternative considered:** one widget per section (9 widgets, mirroring the original 9
template sections exactly). Rejected — `agent-run-operations`'s constituent pieces cannot be
split without either duplicating `schedule` state fetches across widgets (stale-data risk)
or inventing a new cross-widget event bus (out of scope, no fleet precedent).

**On the custom-widget count itself:** six of seven content widgets ending up `type:"custom"`
looks high against ADR-036/049's "keep custom near-zero" framing, but that framing is about
justifying each one, not capping the count in absolute terms — see proposal.md Risk 1. Hermiq
governs autonomous AI execution (kill-switch, budget, approval, dry-run/replay, trace
observability); those interactions are inherently bespoke to Hermiq's execution engine, not
generic OR CRUD, unlike procest/pipelinq's largely-plain-data domain objects.

### Decision 4: header actions via `page.actions[]` + `type:"open-modal"`, not header-slot widgets

The v2 schema's `page` definition carries a top-level `actions[]` (typed, with `open-modal`
as one discriminator) separate from `config`. `shillinq`'s `config.headerActions[]` +
`type:"open-modal"` (`import-bill`, `create-invoice`) is the closest fleet precedent for
button-opens-existing-modal. `AgentDetail`'s three header buttons ("Edit agent", "Version
history", "View compliance factsheet") become three actions targeting three registry
`kind:"modal"` entries: `AgentFormModal` (reused from `AgentCatalog`'s create flow),
`AgentFactsheetDialog`, and a merged `AgentVersionHistoryDialog`.

`AgentVersionHistoryDialog` currently emits `compare` for its **parent** (`AgentDetail.vue`)
to mount `AgentVersionDiffDialog`. A registry modal entry must be self-contained (nothing
else mounts a sibling dialog for it), so `AgentVersionHistoryDialog.vue` absorbs
`AgentVersionDiffDialog`'s mount internally, listening to its own `compare` event rather than
emitting it outward. This is a small, mechanical refactor with no behavior change.

### Decision 5: list-page read-path equivalence verified before converting (no silent regression)

Before converting each list page to `type:"index"` (which reads via the generic OpenRegister
objects endpoint through `useObjectStore`), the existing read path was checked for extra
scoping that a generic read would drop:

- `AgentCatalog` / `EvalDatasets` — both already read via `createObjectStore`/
  `registerObjectType('agent'|'evaldataset'|'evalrun', ..., 'hermiq')`, i.e. the SAME generic
  endpoint `type:"index"` would use. Zero risk.
- `AgentTemplateGallery` — reads via a bespoke `AgentTemplateController::index()` →
  `AgentTemplateService::list()`. Verified: `list()` is a bare
  `$objectService->setRegister('hermiq')->setSchema('agenttemplate')->findAll(['limit'
  =>200])` with no extra filtering/scoping beyond what OpenRegister's own RBAC already
  applies. Read-equivalent; safe to point `type:"index"` at the generic endpoint directly.

Write actions are a different story — see Decision 6.

### Decision 6: "Approve" stays a custom action — `object-op` would bypass a real authorization gate

`object-op` (`type:"patch"`) looked declaratively attractive for `AgentTemplateGallery`'s
"Approve" (quarantined→active). Verified at HEAD:
`AgentTemplateController::approve()` gates through
`$this->actionAuth->requireAction($user, 'agenttemplate.approve-quarantined')` (ADR-023
action-authorization) before calling the service, and additionally requires
`'agenttemplate.override-scan-verdict'` when force-approving past a dangerous scan verdict —
neither check exists on the generic OpenRegister object-patch path. A declarative
`object-op` would silently bypass both gates. "Approve" (and "Use this template", "Export")
therefore stay in a small custom row-actions widget that calls the existing guarded API
functions (`approveAgentTemplate`, `instantiateAgentTemplate`, `exportAgentTemplate`)
unchanged — the list/columns/search/pagination convert; the three write actions do not.

### Decision 7: `type:"handler"` / `type:"api-call"` action types are not adopted here

A fleet-wide scan of every `apps-extra/*/src/manifest.json` found `type:"handler"` used only
with the special built-in `handler:"navigate"` shortcut (no app-authored handler function
name) plus procest's `customComponents.js`-registered function entries (e.g.
`voorstelReminder`) — a real, working pattern, but one that resolves against the SAME
registry map used for components (`ctx.customComponents[name]`, verified in
`CnIndexPage/manifestActionDispatch.js`). `type:"object-op"` has exactly one fleet adopter
(petstore's `mark-complete`). `type:"api-call"` has zero adopters fleet-wide. Given Decision
6 rules out `object-op` for the one action that looked like a fit, and no other action here
needs a bare PATCH/POST, this change does not pioneer new action-type adoption — it stays
within `open-modal` (shillinq-precedented) and plain custom-widget handlers.

### Decision 8: EvalDatasets splits into index + a new EvalDatasetDetail, mirroring the AgentDetail recipe

`EvalDatasets.vue` today renders one card per dataset with an embedded runs sub-table and an
inline agent-picker + Run button per card — a nested, not flat, shape that `type:"index"`
(one row per object) cannot express directly. Rather than leave it fully custom, it splits
using the SAME recipe as `AgentDetail`: the outer list becomes `type:"index"` (`name`,
`description` columns — no derived pass-rate column, see Decision 9), and a new
`EvalDatasetDetail` (`type:"detail"`, `register:"hermiq"`, `schema:"evaldataset"`) hosts the
per-dataset agent-picker + Run button + run history as one custom `eval-run-panel` widget
(the "Run" action has no OR-object equivalent, same justification `EvalDatasets.vue`
already carried).

### Decision 9: derived list columns (schedule-attached, last-run, latest-pass-rate) are dropped, not faked

`AgentCatalog`'s "Schedule" / "Last run" columns and a hypothetical `EvalDatasets`
"pass rate" column all require a **reverse** FK join (`Schedule.agentId`→`Agent`,
`EvalRun.datasetId`→`EvalDataset`). OpenRegister's calculation engine (verified against
procest's `x-openregister-calculations` usage) computes forward references from an object's
own fields (e.g. `case.statusType.isFinal`), not reverse aggregates across an external
collection. Fabricating this via client-side joins inside a declarative `type:"index"`
column is not possible; adding a reverse-lookup calculation primitive to OpenRegister is a
platform change out of scope for a UI-manifest conversion. The columns are dropped; the same
status is now visible one click away on the object's own detail page (a strictly richer
surface than the old list badge). Flagged as Risk 2 in proposal.md.

## Risks / Trade-offs

- [Custom-widget count on AgentDetail is high in absolute terms] → Mitigated by Decision 3's
  per-widget justification table and proposal.md Risk 1's explicit framing for the reviewer.
- [Dropping derived list columns is a small UX regression] → Mitigated by Decision 9; the
  same data is one click away, richer than before.
- [`page.slots`-resolved widgets are a less-traveled path than dashboard `widgets[]`] →
  Mitigated by using the exact procest `CaseDetail`/`InitiatorSection` shape verified at
  HEAD to have real fleet mileage, rather than an untested combination.

## Migration Plan

No data migration (no OpenRegister schema field additions/removals). Deploy is a normal PR
merge per phase; rollback is `git revert` of the phase's merge commit (see proposal.md
Rollback Strategy). The one operational step: after Phase 1 merges, confirm
`tests/e2e/dashboard-and-agents.spec.ts` passes against a live instance (the heading
`data-testid` changes), not just in CI.

## Open Questions

None outstanding — all decisions above were resolved by reading code at HEAD during
authoring rather than deferred.
