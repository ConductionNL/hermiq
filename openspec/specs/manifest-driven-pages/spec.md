# manifest-driven-pages Specification

## Purpose
TBD - created by archiving change manifest-driven-pages. Update Purpose after archive.
## Requirements
### Requirement: AgentDetail renders as a detail-type widget grid

The system MUST render the Agent detail page (`/agents/:id`) as a `type:"detail"` manifest
page bound to `register:"hermiq"`, `schema:"agent"`, laid out via `config.widgets[]` +
`config.layout[]` per widget's `gridWidth`/`gridHeight` with no inner scrollbars and no
reserved empty grid cells (ADR-062).

#### Scenario: Visiting an agent's detail route renders the grid

- GIVEN an authenticated user with access to an agent
- WHEN they navigate to `/agents/:id`
- THEN `CnPageRenderer` dispatches to `CnDetailPage` with `config.register:"hermiq"` and
  `config.schema:"agent"`
- AND every widget in `config.layout[]` occupies exactly its declared `gridWidth` ×
  `gridHeight` with no widget left without a `gridHeight` sized to its content

#### Scenario: A missing or inaccessible agent still shows a graceful empty state

- GIVEN an agent id that does not exist or is not visible to the caller
- WHEN the page loads
- THEN the page shows an empty/not-found state instead of throwing an unhandled error

### Requirement: Agent core config fields are editable via a data widget with tools excluded

The system MUST render the agent's own scalar config fields (`name`, `description`, `type`,
`provider`, `model`, `prompt`, `temperature`, `maxTokens`, `active`) as a `type:"data"`
widget with `content.columns:2`, click-to-edit in place. The system MUST exclude `tools`
(and the existing hidden-fields list: `configuration`, `views`, `invitedUsers`, `groups`,
`isPrivate`, `requestQuota`, `tokenQuota`, `actingUser`, `skillInstalls`, `contextRefs`) from
this widget's `content.include[]`.

#### Scenario: Editing a scalar field in place

- GIVEN the agent detail page is loaded
- WHEN the user clicks the `model` field and changes its value
- THEN the change is persisted through the same `agentStore` save path the widget already
  uses
- AND the `tools` field does NOT appear in this widget

#### Scenario: Editing tools uses the existing Edit-agent modal

- GIVEN the agent detail page is loaded
- WHEN the user opens "Edit agent" from the header
- THEN `AgentFormModal` opens with its existing tools `NcSelect` fed by the live
  `/api/agents/tools` catalogue
- AND saving updates the same agent object the data widget displays

### Requirement: An agent-scoped run-KPI custom widget shows this agent's run totals

The system MUST render a `type:"custom"` widget (`agent-kpis`) that fetches
`/api/analytics` scoped to the current agent id (the route param) and displays total runs,
success rate, average latency, and total tokens.

#### Scenario: KPIs are scoped to the current agent only

- GIVEN an agent with recorded runs
- WHEN the agent detail page loads
- THEN the KPI widget calls the analytics endpoint with this agent's id
- AND the displayed totals reflect only this agent's runs, not the tenant-wide totals shown
  on the `RunAnalytics` dashboard

### Requirement: A skills attach or detach custom widget manages the agent's skill installs

The system MUST render a `type:"custom"` widget (`agent-skills`) that lists the agent's
installed skills (resolved against the skills catalogue) with a detach action per skill, and
an attach control offering catalogue skills not yet installed.

#### Scenario: Attaching a catalogue skill

- GIVEN a skill in the tenant catalogue not yet installed on this agent
- WHEN the user selects it and attaches it
- THEN the agent's `skillInstalls` includes the skill's uuid
- AND the widget's installed-skills list refreshes to show it

#### Scenario: Detaching an installed skill

- GIVEN a skill currently installed on this agent
- WHEN the user detaches it
- THEN the agent's `skillInstalls` no longer includes the skill's uuid

### Requirement: A tool-governance custom widget MUST combine tool grants and tool-activity audit history

The system MUST render a `type:"custom"` widget (`agent-tool-governance`) hosting both the
schema-scoped tool-grant editor and the EU AI Act art.12/14 tool-invocation audit table for
this agent.

#### Scenario: Owner edits a tool grant

- GIVEN the current user owns this agent
- WHEN they change a tool's grant scope and save
- THEN the change persists via the existing tool-grants endpoint
- AND the agent's `tools` display (on the data widget) reflects the updated set after reload

#### Scenario: Non-owner sees a read-only grant editor

- GIVEN the current user does not own this agent
- WHEN they view the tool-governance widget
- THEN the grant editor is read-only (server-enforced regardless of client state)

### Requirement: A run-operations custom widget combines schedule, dry-run, run-now, budget, and webhook

The system MUST render a `type:"custom"` widget (`agent-run-operations`) that: attaches or
edits the agent's schedule; triggers Dry run and Run now; shows the pre-run cost estimate and
current budget status; manages the webhook trigger (create/rotate/revoke, copy-once secret
reveal); and shows the last dry-run/replay preview outcome.

#### Scenario: Dry run never changes anything

- GIVEN an agent with an attached schedule
- WHEN the user clicks "Dry run"
- THEN every side-effecting tool call is reported as `would-have-called`, never invoked
- AND the preview outcome renders in this same widget

#### Scenario: Run now surfaces a graceful error without breaking the page

- GIVEN an agent's execution engine returns an error for this run
- WHEN the user clicks "Run now"
- THEN the error renders as a dismissible note within this widget
- AND the run is still recorded in run history (REQ-007)

### Requirement: A run-history custom widget MUST show run history with per-row trace expand, re-run, and replay

The system MUST render a `type:"custom"` widget (`agent-run-history`) showing this agent's
schedule's run history (status, started, duration, attempt, agent version), with a per-row
"Details" expand that fetches and caches the run's step trace, a "Re-run" action visible only
on `dead_letter` rows, and a "Replay" action that re-executes the run's recorded prompt as a
dry run.

#### Scenario: Expanding a run's trace

- GIVEN a completed run row
- WHEN the user clicks "Details"
- THEN the widget fetches the run's trace on first expand and caches it for subsequent
  toggles
- AND a run whose execution path recorded no tool-call detail is labelled as such, never
  implying zero tool activity

#### Scenario: Re-run only appears for dead-lettered runs

- GIVEN a run row with status `dead_letter`
- WHEN the row renders
- THEN a "Re-run" action is shown
- AND it dispatches through the same run-now path as the widget's page-level "Run now"
  action (no separate endpoint)

### Requirement: Header actions open their modal via a registry-resolved open-modal action

The system MUST expose "Edit agent", "Version history", and "View compliance factsheet" as
`page.actions[]` entries of `type:"open-modal"`, targeting registry `kind:"modal"` entries.

#### Scenario: Edit agent reuses the create-flow modal

- GIVEN the agent detail page is loaded
- WHEN the user triggers the "Edit agent" action
- THEN the SAME `AgentFormModal` registry entry used by `AgentCatalog`'s create action opens,
  pre-filled with this agent's data

#### Scenario: Version history's compare flow is self-contained

- GIVEN the version-history modal is open with two versions selected
- WHEN the user clicks "Compare"
- THEN the diff view opens from within the SAME modal component (no parent-provided sibling
  dialog required)

### Requirement: AgentCatalog renders as an index-type list page

The system MUST render `/agents` as a `type:"index"` manifest page bound to
`register:"hermiq"`, `schema:"agent"`, with `name` and `model` columns, `rowRoute:
"AgentDetail"`, and header actions "Create agent" (`open-modal` → the same `AgentFormModal`
entry as REQ-008) and "Browse templates" (navigate to `AgentTemplateGallery`).

#### Scenario: Opening an agent from the list

- GIVEN the agent list is loaded
- WHEN the user clicks a row
- THEN the browser navigates to that agent's `/agents/:id` detail page

#### Scenario: Creating an agent from the list

- GIVEN the agent list is loaded
- WHEN the user triggers "Create agent"
- THEN `AgentFormModal` opens in create mode (no pre-filled agent)
- AND saving navigates to or refreshes the list to include the new agent

### Requirement: AgentTemplateGallery renders as an index-type list page with write actions kept behind their existing guarded endpoints

The system MUST render `/agent-templates` as a `type:"index"` manifest page bound to
`register:"hermiq"`, `schema:"agenttemplate"`, with `name`, `category`, `description`,
`state` columns, a header action "Import template" (`open-modal` → `TemplateImportModal`),
and row actions "Use this template", "Approve" (quarantined templates only), and "Export"
implemented as a single custom row-actions widget calling the existing
`instantiateAgentTemplate`/`approveAgentTemplate`/`exportAgentTemplate` API functions
unchanged. The system MUST NOT implement "Approve" as a declarative `object-op` patch.

#### Scenario: Using a template surfaces model-coercion / unresolved-skill notes

- GIVEN a template whose suggested model is outside the caller's tenant model policy
- WHEN the user triggers "Use this template"
- THEN the instantiated agent uses the policy-resolved model instead
- AND a note describing the substitution is shown before the user navigates to the new agent

#### Scenario: Approving a quarantined template still enforces the server-side authorization gate

- GIVEN a quarantined template and a caller without the `agenttemplate.approve-quarantined`
  action grant
- WHEN the caller triggers "Approve"
- THEN the existing guarded `approveAgentTemplate` endpoint rejects the request
- AND no client-side action bypasses this check (there is no declarative `object-op` path to
  this field)

### Requirement: EvalDatasets renders as an index-type list page with per-dataset run management on a new EvalDatasetDetail page

The system MUST render `/evals` as a `type:"index"` manifest page bound to
`register:"hermiq"`, `schema:"evaldataset"`, with `name` and `description` columns,
`rowRoute:"EvalDatasetDetail"`, and a header action "New dataset" (`open-modal` →
`EvalDatasetFormModal`). The system MUST add a new `EvalDatasetDetail` page
(`/evals/:id`, `type:"detail"`, `register:"hermiq"`, `schema:"evaldataset"`) hosting a single
custom widget (`eval-run-panel`) with the agent picker, "Run" action, and this dataset's run
history (pass rate, regression-gate result, status).

#### Scenario: Running a dataset against an agent

- GIVEN an eval dataset and an agent selected in the run panel
- WHEN the user clicks "Run"
- THEN the existing governed, non-delivering `runEval` call executes
- AND the new run appears in this dataset's run history with its pass rate and
  regression-gate result

#### Scenario: The dataset list has no per-row run controls

- GIVEN the eval dataset list page
- WHEN it renders
- THEN no agent picker or "Run" control appears in the list itself — those controls exist
  only on `EvalDatasetDetail`

### Requirement: Manifest and registry changes keep check:specs and lint green

The system MUST NOT introduce a manifest, registry, or customComponents change that fails
`check:json-strict`, `check:manifest-v2`, `check:register`, or `check:registry`, and MUST NOT
leave an orphaned `customComponents.js`/`registry.js` import after a `type:"custom"` page is
removed.

#### Scenario: Removing a custom page removes its registry entry in the same commit

- GIVEN `AgentDetail`'s manifest page entry changes from `type:"custom"` to `type:"detail"`
- WHEN the change is committed
- THEN `AgentDetail`'s `customComponents.js` entry and its `AgentDetail.vue` import are
  removed in the same commit
- AND `npm run check:registry` passes (no orphan import, `kind:"page"` requirement still
  satisfied by the remaining custom pages)

