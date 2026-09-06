# Tasks: agent-management-ui

## 1. Backend: thin Run-now endpoint

- [x] 1.1 Refactor `ScheduleService`: add a public `runNow(ObjectEntity $schedule): void` that reuses the EXISTING private `dispatch()` run-one path (compute/commit run-state, impersonate owner, invoke the OR agent, deliver, write `action='run'` audit) wrapped in the same `recordFailure` isolation as the tick — do NOT duplicate dispatch logic.
- [x] 1.2 Add `RunNowController` (`@NoAdminRequired @NoCSRFRequired`) with `run($scheduleId)`: load the schedule via `ObjectService` (RBAC on), assert requester === owner (404 otherwise, IDOR — mirror `RunHistoryController`), call `runNow`, return the updated schedule status (`lastStatus`/`lastError`/`nextRun`). Register `POST /api/schedules/{scheduleId}/run` in `appinfo/routes.php`.
- [x] 1.3 Unit tests: owner triggers a run (200 + status); non-owner refused (404, `runNow` never called); unauthenticated 401; plus a `ScheduleService::runNow` test asserting it drives the dispatch path (agent run + persist + audit). Run `composer test:unit` + `phpcs`/`psalm`/`phpstan` on the new PHP.

## 2. Store bindings

- [x] 2.1 Add a `schedule` object store in `src/store/store.js` via `createObjectStore('schedule', { register: 'hermiq', schema: 'schedule' })`.
- [x] 2.2 Add a plain (non-Pinia) agents API helper for the OpenRegister agents resource (`/apps/openregister/api/agents` list/get/create/update, `GET /api/agents/tools`) plus the Hermiq run-now POST and run-history GET; use `@nextcloud/axios` + `generateUrl` (no custom Pinia store).

## 3. Agent catalog (main nav page)

- [x] 3.1 Add an Agent Catalog custom view under `src/views/` (Vue 2.7 + Options API) listing agents with name, model, schedule-attached indicator, and last-run status (derive schedule/last-run by matching `Schedule.agentId`/`lastStatus` from the schedule store); NcLoadingIcon while loading, NcEmptyContent with a "Create agent" CTA when empty.
- [x] 3.2 Register the catalog in `src/registry.js` (kind `page`) and wire it as the app's main nav route + an agent-detail route in `src/manifest.json` (dedicated nav page — no dashboard-in-dashboard).

## 4. Create/edit agent

- [x] 4.1 Add `src/modals/AgentFormModal.vue` (own file per ADR-004) with name, model/provider, prompt, and an enabled-tools picker; every NcSelect carries `inputLabel`; populate the tools picker from `GET /apps/openregister/api/agents/tools`.
- [x] 4.2 Wire create + edit to persist via the agents API helper; refresh the catalog on save without a full page reload.

## 5. Agent detail: schedule, run now, run history

- [x] 5.1 Add an Agent Detail custom view under `src/views/` showing the agent config and its attached schedule (or an "attach schedule" prompt when none).
- [x] 5.2 Add `src/modals/ScheduleFormModal.vue` exposing kind (once|interval|cron), cronExpr, intervalMinutes, runAt, prompt, deliver, deliverTarget, enabled, repeat; persist as a `hermiq`/`schedule` object via the schedule store; NcSelect fields carry `inputLabel`.
- [x] 5.3 Add a "Run now" action that POSTs to `/apps/hermiq/api/schedules/{id}/run`; surface the run result and render a graceful, dismissible error state when OpenRegister returns a run error (OR agent-execution is WIP).
- [x] 5.4 Add a run-history list on the detail view consuming `GET /apps/hermiq/api/schedules/{scheduleId}/runs`, newest-first with status + timing, with empty-state and non-blocking error state.

## 6. i18n, accessibility, quality, verify

- [x] 6.1 Use ENGLISH i18n source keys throughout (`t('hermiq', '...')`, added to `l10n/en.json`); verify WCAG 2.1 AA (keyboard reachability, focus order in modals, NcSelect `inputLabel` on every select).
- [x] 6.2 Run `npm run lint` (+ `stylelint`) and **`npm run build`** (built js must exist), the relevant Hydra gates (modal-isolation, nc-input-labels, dashboard-antipattern, route-auth, no-admin-idor), and `composer check:strict`-scope on new PHP; fix any pre-existing issues touched.
- [x] 6.3 Bump `appinfo/info.xml` `<version>` (immutable `/custom_apps/*.js` cache-bust).
- [x] 6.4 Verified live in the browser (Playwright, browser-1) on NC 34: the Agents nav page renders and lists real OpenRegister agents (Default Assistant/qwen, CMS Test Agent/llama3.2, …) with name/model/schedule/last-run + Create agent; Agent Detail shows live config (provider/model/system-prompt/enabled-tools); Attach schedule opens the isolated modal (NcSelect `inputLabel`s present) and persists a cron Schedule via OpenRegister; Run now became enabled, fired synchronously, and rendered a graceful, dismissible error alert (OR WIP: `column "owner" of relation "oc_openregister_conversations" does not exist`) while advancing Next run to the next cron slot; the Run history table populated with the `error`/started/duration row (read via the owner-scoped runs endpoint). 0 console errors throughout.

## Acceptance criteria

- The agent catalog is a dedicated nav app page listing RBAC-scoped agents with model, schedule-attached, and last-run status; empty-state shown when there are none.
- Create/edit agent persists via the OpenRegister agents API (no bespoke Pinia store, no bespoke CRUD backend); schedules persist via `createObjectStore`.
- Agent detail attaches/edits a Schedule (all listed fields), offers Run now via the thin `POST /api/schedules/{id}/run` endpoint, and shows run history from the run-history endpoint.
- Run now reuses `ScheduleService`'s dispatch path (no duplicated logic) and is owner-guarded (404 for a non-owner, like the run-history GET).
- A Run-now error from OpenRegister is recorded (status `error`, kept in run history) and displayed as a graceful error state without breaking the detail view.
- UI uses @conduction/nextcloud-vue + @nextcloud/vue components and meets WCAG 2.1 AA.

## Quality reminders

- Vue 2.7 + Options API only; no Composition API in views; no custom Pinia stores.
- All modals live in their own files under `src/modals/` (ADR-004); no inline NcModal/NcDialog.
- Every NcSelect carries `inputLabel` (accessibility gate); never pair a manual `<label>` with NcSelect.
- No dashboard-in-dashboard nesting; the catalog is a standard nav page.
- i18n keys are ENGLISH source.
- Component/composable logic that belongs in the shared lib goes to `@conduction/nextcloud-vue`, not forked here.
- Use safe placeholder values in examples (nil UUID `00000000-0000-0000-0000-000000000000`, `<deliver-target>`).
