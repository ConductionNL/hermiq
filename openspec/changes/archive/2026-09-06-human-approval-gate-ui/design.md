# Design: human-approval-gate-ui

## Context

Hermiq's frontend is a thin Vue 2.7 client that queries OpenRegister directly and is
manifest-driven: `src/manifest.json` declares pages (`id`/`route`/`component`) and
`routesFromManifest()` in `src/main.js` turns each into a vue-router 3 route; views
live in `src/views/` (e.g. `AgentCatalog.vue`, `AgentDetail.vue`) and modals in
`src/modals/` (e.g. `ScheduleFormModal.vue`), with API wrappers in `src/api/`
(e.g. `agents.js`). There is no bespoke router file — routes come from the manifest.

The enforcement change (`human-approval-gate-enforcement`) already ships the backend:
the dispatcher creates pending `Approval` objects routed to a resolved reviewer, and
the reviewer/admin-guarded approve/deny endpoints plus the org-subadmin/instance-admin
kill-switch toggle endpoint exist. This change adds the thin human surface. It was
split out of enforcement (ADR-032) so each change stays single-kind and within the
≤20-task cap.

## Goals / Non-Goals

**Goals:**
- An Approvals inbox view listing pending `Approval` objects routed to the current
  reviewer, with Approve/Deny actions.
- An isolated deny-reason modal (ADR-004 modal-isolation) and accessible inputs
  (ADR-004 nc-input-labels).
- A kill-switch toggle surface visible only to org sub-admins / instance admins.
- Manifest-driven route + nav entry, mirroring `AgentCatalog`/`AgentDetail`.

**Non-Goals:**
- Any backend, schema, or endpoint work — all consumed from
  `human-approval-gate-enforcement`.
- A schedule-editor field for `reviewer`/`reviewerType` — the schedule create/edit UI
  is `ScheduleFormModal`; adding the reviewer picker there is a small follow-up flagged
  as an Open Question (this change focuses on the inbox + kill-switch).
- Real-time push — the inbox refreshes on load/action; live notification is the
  Talk/Notification hook from enforcement.

## Decisions

**Mirror the existing manifest/view/modal patterns.** `ApprovalInbox.vue` follows
`AgentCatalog.vue` (list a resource, per-row actions, a create/action modal);
`ApprovalDenyModal.vue` follows `ScheduleFormModal.vue` shape under `src/modals/`;
`src/api/approvals.js` follows `src/api/agents.js`. The Approvals page is added to
`src/manifest.json` (`route: /approvals`, `component: ApprovalInbox`) plus a nav entry,
so `routesFromManifest()` wires it with no new router file. *Alternative considered:* a
standalone router entry — rejected, it would break the manifest-driven convention
(and the hydra admin-router gate forbids ad-hoc router registration of settings views).

**Reviewer scoping is server-authoritative.** The inbox lists only approvals the
current user may decide; the actionability is ultimately enforced by the endpoint guard
(reviewer/group-member/instance-admin). The UI filters the OR `Approval` query to
pending + routed-to-me for display, but never relies on client-side filtering for
security — a denied action returns an error the UI surfaces.

**Kill-switch visibility is capability-gated.** The toggle is rendered only when the
current user is an org sub-admin or instance admin; the UI derives this from an
initial-state / capability flag provided by the backend (per ADR-004 initial-state
pattern — no DOM data-attribute reads). The endpoint remains the real guard; hiding the
control is UX, not security.

**Accessibility (ADR-004).** The deny modal is a dedicated `src/modals/` component
(modal-isolation gate); any `NcSelect` (e.g. a future reviewer/filter select) carries
an `inputLabel`; approve/deny are real buttons, keyboard-reachable.

## Risks / Trade-offs

- **Client-side reviewer filter is not a security boundary.** [The list filters
  approvals to the current reviewer client-side] → Security is the endpoint guard; the
  UI surfaces a 404/refusal if a stale row is actioned. Documented.
- **Kill-switch admin flag source.** [The UI needs to know if the user is an org
  sub-admin] → Use a backend-provided initial-state/capability flag (ADR-004
  initial-state), not a DOM read; if enforcement does not yet expose it, add a tiny
  read endpoint or capability (flagged in Open Questions).
- **No real-time refresh.** [A newly-created approval will not appear until reload] →
  Acceptable for MVP; the Talk/Notification hook already alerts the reviewer, and the
  inbox refreshes on open and after each action.

## Open Questions

- **Reviewer picker in ScheduleFormModal.** Adding a `reviewer`/`reviewerType` picker
  to the schedule create/edit modal is a natural follow-up so users can set the
  reviewer from the UI (the field exists on the schema). Deferred to keep this change
  scoped to the inbox + kill-switch; flagged as a DEFERRED_QUESTION.
- **Admin-capability flag.** Whether the org-subadmin/instance-admin capability used to
  show the kill-switch toggle is provided by an enforcement initial-state value or a
  small dedicated read endpoint — to be confirmed with the enforcement implementer.
