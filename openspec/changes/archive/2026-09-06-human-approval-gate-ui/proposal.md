---
kind: code
depends_on: [human-approval-gate-enforcement]
---

# Proposal: human-approval-gate-ui

# Why

The enforcement change (`human-approval-gate-enforcement`) makes the approval gate and
kill-switch real on the backend — pending `Approval` objects are created and routed to
a reviewer, and the toggle/decision endpoints exist — but a reviewer has no way to see
or act on a pending approval, and an org admin has no way to hit the kill-switch,
without calling the API by hand. EU AI Act Art. 14 oversight is only usable if the
human has a surface. This change adds the thin Vue UI.

## What Changes

- **Approval inbox view.** Add `src/views/ApprovalInbox.vue` (mirroring
  `AgentCatalog.vue`) listing the pending `Approval` objects routed to the current
  user as reviewer — for each: the schedule name, agent, prompt, `requestedAt`, and
  **Approve** / **Deny** actions calling the enforcement endpoints. Deny opens an
  isolated modal to capture the reason.
- **Approvals route + nav.** Register an `ApprovalInbox` page in `src/manifest.json`
  (`route: /approvals`, `component: ApprovalInbox`) so `routesFromManifest()` in
  `src/main.js` exposes it, plus a top-nav entry — mirroring the existing
  `AgentCatalog`/`AgentDetail` manifest pattern.
- **Deny-reason modal.** Add `src/modals/ApprovalDenyModal.vue` (isolated `NcModal`
  component under `src/modals/`, per ADR-004 modal-isolation) with a reason field; any
  `NcSelect` uses an `inputLabel` (ADR-004 nc-input-labels).
- **Kill-switch toggle surface.** Add a kill-switch control (a
  `src/components/KillSwitchToggle.vue` used on the inbox or a small admin section)
  that reads and toggles the org's `TenantControl` via the enforcement endpoint, shown
  only to org sub-admins / instance admins.
- **API client.** Add `src/api/approvals.js` (mirroring `src/api/agents.js`) wrapping
  the approve/deny/list and kill-switch endpoints.

This is the **third change in the ADR-032 chain** and `depends_on`
`human-approval-gate-enforcement` — it consumes that change's endpoints. It was split
out of enforcement so each change stays within the ≤20-task cap and single-kind.

## Capabilities

### New Capabilities
- `human-approval-gate-ui`: the reviewer Approval inbox (list + approve/deny +
  deny-reason modal) and the org-admin kill-switch toggle surface, wired into the
  manifest-driven nav/router.

### Modified Capabilities
- <!-- none -->

## Impact

- **Code (frontend):** `src/views/ApprovalInbox.vue`, `src/modals/ApprovalDenyModal.vue`,
  `src/components/KillSwitchToggle.vue`, `src/api/approvals.js`, and a page + nav entry
  in `src/manifest.json` (consumed by `src/main.js` `routesFromManifest`).
- **No backend, no schema:** consumes the endpoints from
  `human-approval-gate-enforcement` and reads `Approval`/`TenantControl` objects via the
  OpenRegister API (frontend queries OR directly, per the app's thin-client pattern).
- **Accessibility (ADR-004):** modals isolated under `src/modals/`; every `NcSelect`
  carries an `inputLabel`; actions are keyboard-reachable.
- **Upstream dependency:** requires `human-approval-gate-enforcement` (endpoints) and
  transitively `human-approval-gate-schema` (objects) to have landed.
