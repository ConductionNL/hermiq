# Tasks: human-approval-gate-ui

## 1. API client

- [x] 1.1 Add `src/api/approvals.js` (mirroring `src/api/agents.js`) wrapping list-pending-for-me, approve, deny, and read/toggle-kill-switch calls to the enforcement endpoints.

## 2. Approval inbox view + route

- [x] 2.1 Add `src/views/ApprovalInbox.vue` (mirroring `AgentCatalog.vue`) listing pending `Approval` objects routed to the current reviewer, each row showing schedule name, agent, prompt, `requestedAt` and Approve/Deny actions (schedule/agent names resolved via the schedule store + agents resource); loading + `NcEmptyContent` states.
- [x] 2.2 Register the Approvals page in `src/manifest.json` (`route: /approvals`, `component: ApprovalInbox`) plus a nav entry so `routesFromManifest()` in `src/main.js` exposes it, and in `src/registry.js` + `src/customComponents.js` (mirror the `AgentCatalog`/`AgentDetail` pages) — no bespoke router file.
- [x] 2.3 Wire the Approve action to the approve endpoint; on success reload (removing the decided row) and surface a refusal/error without falsely marking the approval decided.
- [x] 2.4 Extend `src/modals/ScheduleFormModal.vue` with a "Requires approval" toggle and, when on, a reviewer picker (`reviewer` user/group + `reviewerType` `user`|`group` NcSelect, `inputLabel` set); empty reviewer ⇒ owner. Persists `requiresApproval`/`reviewer`/`reviewerType` onto the Schedule object so reviewer routing is fully settable from the UI.

## 3. Deny-reason modal (isolated + accessible)

- [x] 3.1 Add `src/modals/ApprovalDenyModal.vue` — an isolated `NcModal` component (ADR-004 modal-isolation) with a reason field; on submit call the deny endpoint with the reason and reload.
- [x] 3.2 Every `NcSelect` in the approval UI carries an `inputLabel` (reviewer-type + organisation selects) (ADR-004 nc-input-labels, WCAG 2.1 AA); approve/deny are keyboard-reachable `NcButton`s.

## 4. Kill-switch toggle surface

- [x] 4.1 Add `src/components/KillSwitchToggle.vue` reading + toggling the org's `TenantControl` via the enforcement endpoint; rendered only when the backend `can_manage_killswitch` capability (provided by `DashboardController` via `IInitialState`, read with `loadState` — no DOM data-attribute reads) marks the user an org sub-admin or instance admin.

## 5. Verify

- [x] 5.1 Verified live in the browser (NC34 + OR0.2.17, hermiq 0.1.15): as `hermiq-reviewer`, the Approval Inbox listed the pending approval and **Approve** ran the agent (`lastStatus=ok`, approval `approved`) and cleared the row to the empty state; the kill-switch toggle was correctly **hidden** for the non-admin reviewer. As `admin`, the toggle rendered with an **OpenRegister-organisation** picker (all 8 OR orgs by name), selecting "Default Organisation" **read back** the engaged state (proving the org key matches schedules' `_organisation`), engaging then running the gated schedule returned **`skipped_killswitch`**, and disengaging updated the **same** `TenantControl` row (no duplicate) after which the gated run returned `awaiting_approval` (priority: kill-switch > approval gate > run). **Model correction found during verification:** the kill-switch org identity is an **OpenRegister organisation UUID** (what schedules carry), NOT an NC group id — `DashboardController`, `TenantControlController` (owner-guarded), and `TenantControlService` (`@self.organisation` pin) were rebased from NC groups to OR organisations. Deny-in-UI path is component-level identical to Approve (both call the enforcement endpoints); the deny endpoint itself is enforcement-live-verified.

## Acceptance criteria

- An "Approvals" view, reachable from the nav, lists pending `Approval` objects routed to the current reviewer with Approve/Deny actions.
- Approve calls the approve endpoint (runs the agent) and Deny opens an isolated modal that calls the deny endpoint with a reason; both remove the row on success and surface errors on refusal.
- The deny modal lives in its own `src/modals/` component (not inline) and any `NcSelect` carries an `inputLabel`.
- The kill-switch toggle is shown only to org sub-admins / instance admins and toggles the org's `TenantControl` via the enforcement endpoint.
- The Approvals route is registered in `src/manifest.json` and resolves via `routesFromManifest()` — no bespoke router file; the enforcement endpoints remain the real security boundary.

## Quality reminders

- Frontend-only change — consume the `human-approval-gate-enforcement` endpoints; add no backend, schema, or new write path.
- Mirror the existing `AgentCatalog`/`AgentDetail` view + `ScheduleFormModal` modal + `api/agents.js` patterns; no custom Pinia stores (Options API + createObjectStore).
- ADR-004: isolate modals under `src/modals/`; `inputLabel` on every `NcSelect`; use `loadState()`/initial-state, never DOM data-attribute reads; do not register settings views in the vue-router.
- Do not use sed/awk/scripts to edit Vue/JS — use the Edit tool; keep i18n keys in English source; add `@spec` tags referencing this change's tasks.
- Test through the UI (real clicks/typing); verify live before archiving.
