# Tasks: human-approval-gate-ui

## 1. API client

- [ ] 1.1 Add `src/api/approvals.js` (mirroring `src/api/agents.js`) wrapping list-pending-for-me, approve, deny, and read/toggle-kill-switch calls to the enforcement endpoints.

## 2. Approval inbox view + route

- [ ] 2.1 Add `src/views/ApprovalInbox.vue` (mirroring `AgentCatalog.vue`) listing pending `Approval` objects routed to the current reviewer, each row showing schedule name, agent, prompt, `requestedAt` and Approve/Deny actions.
- [ ] 2.2 Register the Approvals page in `src/manifest.json` (`route: /approvals`, `component: ApprovalInbox`) plus a nav entry so `routesFromManifest()` in `src/main.js` exposes it (mirror the `AgentCatalog`/`AgentDetail` pages) — no bespoke router file.
- [ ] 2.3 Wire the Approve action to the approve endpoint; on success reflect the run outcome and remove the row; surface a refusal/error without falsely marking the approval decided.
- [ ] 2.4 Extend `src/modals/ScheduleFormModal.vue` with a "Requires approval" toggle and, when on, a reviewer picker (`reviewer` user/group + `reviewerType` `user`|`group`, `inputLabel` set); empty reviewer ⇒ owner. Persists onto the Schedule object so reviewer routing is fully settable from the UI.

## 3. Deny-reason modal (isolated + accessible)

- [ ] 3.1 Add `src/modals/ApprovalDenyModal.vue` — an isolated `NcModal` component (ADR-004 modal-isolation) with a reason field; on submit call the deny endpoint with the reason and remove the row.
- [ ] 3.2 Ensure any `NcSelect` in the approval UI carries an `inputLabel` (ADR-004 nc-input-labels, WCAG 2.1 AA); approve/deny are keyboard-reachable buttons.

## 4. Kill-switch toggle surface

- [ ] 4.1 Add `src/components/KillSwitchToggle.vue` reading + toggling the org's `TenantControl` via the enforcement endpoint; render it only when a backend-provided capability flag marks the user an org sub-admin or instance admin (ADR-004 initial-state — no DOM data-attribute reads).

## 5. Verify

- [ ] 5.1 Verify live in the browser: a reviewer sees their pending approvals; Approve runs the agent and clears the row; Deny with a reason clears the row and blocks the run; an approval routed to another reviewer is not actionable; the kill-switch toggle appears only for an org sub-admin/instance admin and engages/disengages the org's runs.

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
