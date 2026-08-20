# Design: agent-management-ui

## Context

Hermiq is a thin management + scheduling app over OpenRegister's agent core
(ADR-001). The agent engine, tools (MCP), LLM, and governance/audit all live in
OpenRegister; Hermiq owns only the scheduling layer and the management UX (the
"+" of Option C+). The backend surfaces this UI consumes are already merged and
live:

- **Agents** are a first-class OpenRegister entity, NOT generic OR objects. They
  are served by `OCA\OpenRegister\Controller\AgentsController` through
  `AgentMapper` (`oc_openregister_agents` table) with RBAC filtering in the
  mapper layer. The API is the resource route `api/agents`:
  `GET /apps/openregister/api/agents` (index, org/RBAC-scoped),
  `GET|POST|PUT|PATCH|DELETE /api/agents/{id}` (show/create/update/patch/destroy),
  plus `GET /api/agents/tools` (metadata for the enabled-tools picker) and
  `GET /api/agents/stats`. There is **no** OR agent run/trigger endpoint.
- **Schedules** are generic OpenRegister objects in the `hermiq` register at
  `/apps/openregister/api/objects/hermiq/schedule` (CRUD) — the canonical
  `createObjectStore` path. Schema fields per `agent-schedule`: `name`,
  `agentId`, `kind` (once|interval|cron), `cronExpr`, `intervalMinutes`, `runAt`,
  `prompt`, `deliver` (talk|notification|none), `deliverTarget`, `enabled`,
  `repeat {times, completed}`, plus derived `nextRun`/`lastStatus`/`lastError`.
- **Run history** is Hermiq's own owner-scoped read:
  `GET /apps/hermiq/api/schedules/{scheduleId}/runs` →
  `{results: [...], total}`, newest-first run records (status, timing, agentId).

The existing frontend is manifest-driven (`src/manifest.json`, app-manifest-v2)
on a Vue 2.7 scaffold (`src/App.vue`, `src/main.js`, `src/store/store.js` with
`createObjectStore`, `src/store/modules/settings.js`). This change builds on that
scaffold — it does not reinvent it.

## Goals / Non-Goals

**Goals:**
- A dedicated nav app page (agent catalog) as the main route, plus an agent
  detail route — not a dashboard-in-dashboard.
- Create/edit agent, attach/edit schedule, Run now, and run-history views that
  satisfy the `agent-management-ui` MVP requirements.
- Full Conduction frontend conventions + WCAG 2.1 AA.

**Non-Goals:**
- No new backend for agent or schedule CRUD (those endpoints exist).
- No memory editor, skills catalog/marketplace, or run analytics (later phases).
- No schema/seed/declarative work — this is a pure frontend consuming schemas
  that already exist. There is **no Seed Data / declarative section** in this
  design.

## Decisions

### Frontend conventions (hard rules — enforced)
- **Vue 2.7 + Options API only.** No `<script setup>`, no Composition API in
  views.
- **Components** come from `@conduction/nextcloud-vue` and `@nextcloud/vue`
  (NcButton, NcSelect, NcTextField, NcTextArea, NcCheckboxRadioSwitch,
  NcModal, NcEmptyContent, NcLoadingIcon, Cn* list/detail primitives). Do not
  hand-roll what the shared libs provide.
- **No custom Pinia stores.** Data access uses the `createObjectStore` pattern
  (`src/store/store.js`) — an `agents` store bound to the OR agents surface and
  a `schedule` store bound to `hermiq`/`schedule`. Component/composable logic
  that belongs in the shared lib is contributed to `@conduction/nextcloud-vue`,
  not forked here.
- **Modals in their own files** under `src/modals/` (ADR-004 modal-isolation) —
  no inline `<NcModal>`/`<NcDialog>` in a parent view.
- **`NcSelect` MUST carry `inputLabel`** (nc-input-labels accessibility gate);
  never pair a manual `<label>` with NcSelect.
- **i18n keys are ENGLISH source** — `t('hermiq', 'Create agent')`.
- **No dashboard-in-dashboard nesting** (dashboard-antipattern gate): the agent
  catalog is a standard list/index page, not a `type:"dashboard"` page rendering
  `<CnDashboardPage>`.

### ADR-031 note
This is a **frontend** change consuming existing schemas; it defines no
OpenRegister derived fields, so the ADR-031 declarative-notification/derived-field
dialect does not apply here.

### Data-path decisions
- **Agents via the OR agents resource API, not the generic object store.**
  Agents have their own controller/mapper (numeric `{id}`, RBAC in the mapper),
  so the agents store binds to `api/agents` rather than
  `api/objects/{register}/{schema}`. Rationale: reading via the generic object
  path would bypass `AgentsController`'s org/RBAC filtering and the `tools`
  metadata endpoint.
- **Schedules via `createObjectStore('schedule', {register:'hermiq', schema:'schedule'})`** —
  the canonical object CRUD path, exactly as the scaffold's `example` store.
- **Catalog columns** (name, model, schedule-attached, last-run status) are
  assembled client-side: agents from `api/agents`; the schedule-attached flag +
  last-run status by matching `Schedule.agentId`/`lastStatus` from the schedule
  store (and `runs` for the freshest run when a detail is open).
- **Run now (LOCKED): a thin Hermiq endpoint `POST /apps/hermiq/api/schedules/{id}/run`.**
  No OR agent-trigger endpoint exists, so Run now is a single new backend action.
  A new public `ScheduleService::runNow(ObjectEntity $schedule): void` reuses the
  EXISTING private `dispatch()` run-one path — the same code a tick runs for one
  schedule (compute + commit run-state, impersonate owner, invoke the OR agent,
  deliver, write the `action='run'` audit entry). `dispatch()` is NOT duplicated:
  the tick loop (`run()`) and `runNow()` both call it, wrapped in the same
  per-schedule `recordFailure` isolation. A new `RunNowController::run($scheduleId)`
  (`@NoAdminRequired @NoCSRFRequired`) loads the schedule via `ObjectService` with
  RBAC on, asserts the requester is the owner (404 otherwise — same IDOR guard as
  `RunHistoryController`), calls `runNow()`, then returns the updated schedule
  status (`lastStatus`/`lastError`/`nextRun`). An OR agent error is caught inside
  `dispatch()`, recorded as `lastStatus='error'` + audited, so the endpoint returns
  200 with `status='error'` and the UI renders the error state; a catastrophic
  failure re-throws and the controller returns a graceful 5xx error body.

## Risks / Trade-offs

- **OR agent-execution `Undefined column` bug (OR WIP)** → Run-now will currently
  surface an execution error from OpenRegister. The UI MUST handle and display
  run errors gracefully (error state on the run record / a dismissible inline
  error), so a broken run does not break the view. Accepted for MVP.
- **No OR agent-trigger endpoint** → resolved by the single thin Hermiq
  `POST /api/schedules/{id}/run` endpoint that reuses `dispatch()` (no logic
  duplication, no new CRUD). This is the only net-new backend in this change.
- **Manual run reuses the tick's commit-before-run path** → running a `once`
  schedule via Run now consumes it (sets `enabled=false`), and a finite `repeat`
  increments `completed`, exactly as a scheduled tick would. This is intended
  ("same path as a tick") and documented in `runNow()`.
- **Agents are not generic OR objects** → binding the agent list to the generic
  object store would skip RBAC/tools; must use `api/agents`. Mitigation: an
  `agents` store explicitly targeting that resource path.
- **Immutable JS cache** → `/custom_apps/*.js` is immutable; a version bump in
  `appinfo/info.xml` is required for the deploy to be picked up (live-verify
  task covers this).

## Migration Plan

Additive frontend only — new views/modals + manifest menu/page entries and store
bindings. Rollback = revert the frontend commit and bump the version; no data or
schema migration, nothing to undo server-side.

## Open Questions

All three forks are now LOCKED by the coordinator:

1. **UI placement** — RESOLVED: a dedicated Hermiq nav app page; the agent catalog
   is the main route.
2. **Run now transport** — RESOLVED: a thin Hermiq endpoint
   `POST /apps/hermiq/api/schedules/{id}/run` reusing `ScheduleService::dispatch()`
   via a new public `runNow()`; owner-guarded (IDOR).
3. **OR Run-now `Undefined column` error surfacing** — RESOLVED: acceptable for
   MVP; the run error is recorded (status `error`, kept in run history) and the UI
   renders a graceful, dismissible error state.
