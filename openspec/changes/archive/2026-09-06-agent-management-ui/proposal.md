---
kind: code
---

# Proposal: agent-management-ui

## Why

Hermiq's backend surfaces are live (Schedule schema + dispatcher, run-history
audit read) and OpenRegister already owns the agent core, but a user still has no
Nextcloud-native way to browse agents, create/configure one, attach a schedule,
run it, or review run history — the "+" management UX of Option C+ (ADR-001) is
unbuilt. This change delivers that MVP frontend, consuming only already-merged
surfaces (no new backend for agent/schedule CRUD).

## What Changes

- Add a dedicated **nav app page** (agent catalog) as the app's main route, built
  on the existing manifest-driven Vue 2.7 scaffold — no dashboard-in-dashboard
  nesting.
- Add an **Agent catalog** view listing agents the user may see (name, model,
  schedule-attached indicator, last-run status), scoped by Nextcloud RBAC.
- Add **create/edit agent** (name, model/provider, prompt, enabled tools)
  persisting via OpenRegister's agents API.
- Add an **agent detail** view that attaches/edits a `Schedule` (kind/cron/
  interval/once, prompt, deliver, deliverTarget, enabled, repeat), offers a
  **Run now** action, and shows a **Run history** list from the run-history
  endpoint.
- Add ONE thin Hermiq backend endpoint — `POST /apps/hermiq/api/schedules/{id}/run`
  — that runs the bound agent immediately by reusing `ScheduleService`'s existing
  dispatch (run-one) path; owner-guarded (IDOR) exactly like the run-history GET.
- All modals live in their own files under `src/modals/` (ADR-004
  modal-isolation); every `NcSelect` carries `inputLabel` (accessibility gate).

## Capabilities

### New Capabilities
<!-- none — this change implements the pre-existing planned spec -->

### Modified Capabilities
- `agent-management-ui`: moves the planned MVP requirements (agent catalog,
  create/configure agent, attach schedule + run-now, run-history view) from
  *planned* to *implemented* by adding the Vue frontend that satisfies them. No
  requirement text changes; the delta records the frontend behaviours that
  fulfil the existing scenarios.

## Impact

- **Frontend** — `src/` Vue 2.7 (Options API) views, modals, a `schedule`
  `createObjectStore` binding, and a plain (non-Pinia) agents API helper;
  `src/manifest.json` menu/pages + `src/registry.js`.
- **One thin backend endpoint** — `POST /api/schedules/{id}/run` on a new
  `RunNowController`, delegating to a new public `ScheduleService::runNow()` that
  reuses the existing dispatch path. No OR agent-trigger endpoint exists, so this
  minimal action is required; it adds no new agent/schedule CRUD.
- **Consumes (no changes):** OpenRegister agents API
  (`/apps/openregister/api/agents` — resource CRUD, RBAC-filtered mapper;
  `GET /api/agents/tools` for the tool picker); OpenRegister Schedule objects
  (`/apps/openregister/api/objects/hermiq/schedule`); Hermiq run-history
  (`GET /apps/hermiq/api/schedules/{scheduleId}/runs`).
- **Depends on:** shared `@conduction/nextcloud-vue` + `@nextcloud/vue`
  components; no other Conduction app is affected.
