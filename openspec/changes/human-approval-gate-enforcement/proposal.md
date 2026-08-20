---
kind: code
depends_on: [human-approval-gate-schema]
---

# Proposal: human-approval-gate-enforcement

## Why

Declaring the `Approval` and `TenantControl` schemas plus the
`Schedule.requiresApproval` flag (upstream `human-approval-gate-schema`) creates the
durable state but nothing enforces it. EU AI Act Article 14 requires the human
oversight and stop mechanism to be **real** — the run loop must hard-block on the
approval and kill-switch state (advisory-only fails Art. 14, per ADR-004). This
change adds the dispatcher enforcement and the owner/admin-guarded endpoints that
make the gate and the kill-switch actually stop runs.

## What Changes

- **Dispatcher kill-switch check (synchronous, ADR-004).** In
  `Hermiq\Service\ScheduleService`, before firing any due schedule, check whether the
  schedule's organisation has an engaged `TenantControl` object. When engaged, the
  dispatcher MUST NOT run the agent — it skips the schedule and records the skip
  (`lastStatus='skipped_killswitch'`) and a redacted `AuditTrail` entry
  (`action='skipped'`). Engaged controls are loaded once per tick (a
  `findAll(TenantControl, engaged=true)`) and matched by organisation, so the check is
  synchronous within `run()`/`dispatch()`.
- **Dispatcher approval gate + reviewer routing (synchronous).** When a due schedule
  (or a `runNow` call) has `requiresApproval=true`, the dispatcher MUST NOT invoke the
  agent. Instead it creates a pending `Approval` OR object (carrying `status=pending`,
  `scheduleId`, `agentId`, `prompt`, `requestedAt`, and inheriting the schedule
  `owner`/`organisation` via `ObjectService`), resolves the reviewer from the
  schedule's `reviewer`/`reviewerType` (defaulting to the owner when empty) and copies
  it onto the `Approval`, records the gate, and returns without running. The existing
  at-most-once advance still applies so the schedule does not re-queue an approval
  every tick.
- **Notify the reviewer.** On creating a pending `Approval`, reuse `DeliveryService`
  (Talk with Notifications fallback — Talk is not guaranteed installed, ADR-001) to
  notify the resolved reviewer (the designated user, or each member of the reviewer
  group) with a deep link to the pending approval — not the owner unless owner ==
  reviewer.
- **Approve / deny endpoints (reviewer/admin-guarded, IDOR-safe).** Add an
  `ApprovalController` mirroring `RunHistoryController`/`RunNowController`
  (`@NoAdminRequired` + explicit guard, 404 for non-reviewers). The guard admits the
  resolved reviewer (the `Approval.reviewer` user, or any member of the reviewer group
  via `IGroupManager`) or an instance admin — NOT the schedule owner unless owner ==
  reviewer (separation of duties, Art. 14):
  - `approve` → transition the `Approval` to `approved` (set `decidedAt`/`decidedBy`)
    and execute the run by reusing `ScheduleService::runNow` for the bound schedule.
  - `deny` → transition to `denied` (set `decidedAt`/`decidedBy`/`reason`) and record
    it; the gated run MUST NOT execute.
- **Kill-switch toggle endpoint (org-subadmin/instance-admin-guarded).** Add a
  `TenantControlController` (or a method on `ApprovalController`) that lets a
  **sub-admin of the organisation's NC group** or a **Nextcloud instance admin**
  engage/disengage that organisation's `TenantControl` via `ObjectService`
  (auditable). The guard uses `IGroupManager` (`isAdmin` for instance admin;
  group-sub-admin check for the org group); a plain owner or any other user MUST NOT
  toggle it, and a cross-tenant toggle is refused.
- **Register routes** for the new endpoints in `appinfo/routes.php` with explicit
  auth attributes.

This is the **second change in the ADR-032 three-change chain** and `depends_on`
`human-approval-gate-schema` — it consumes the `Approval`, `TenantControl`,
`Schedule.requiresApproval`, and `reviewer`/`reviewerType` declarations that change
adds. The thin Vue UI is the downstream `human-approval-gate-ui` change (split out to
keep this change within the ≤20-task cap).

## Capabilities

### New Capabilities
- `human-approval-gate-enforcement`: the synchronous dispatcher gate (create-pending-
  Approval routed to the resolved reviewer instead of running; skip-on-engaged-kill-
  switch), the reviewer/admin-guarded approve/deny endpoints, the org-subadmin/
  instance-admin-guarded kill-switch-toggle endpoint, and the reviewer notification
  hook.

### Modified Capabilities
<!-- None as a spec delta — the dispatcher's existing run/dispatch behavior is
     extended with new gated branches, specified as ADDED requirements under this
     capability rather than a MODIFIED delta against the coarse agent-schedule MVP. -->
- <!-- none -->

## Impact

- **Code:** `lib/Service/ScheduleService.php` (gate + reviewer-resolution +
  kill-switch checks in `run()`/`dispatch()`/`runNow`), a new
  `Hermiq\Service\ApprovalService` (create pending / apply decision through
  `ObjectService`, reviewer-group resolution via `IGroupManager`),
  `lib/Controller/ApprovalController.php`, and a kill-switch toggle surface
  (controller or method) guarded via `IGroupManager`.
- **Config:** `appinfo/routes.php` gains the approve/deny/kill-switch routes with
  explicit auth attributes.
- **Runtime dependency on OpenRegister:** all Approval/TenantControl reads and writes
  go through `ObjectService` (single write-path, ADR-004); decisions and skips are
  recorded in the hash-chained `AuditTrail` after redaction. Reviewer notification
  reuses the existing `DeliveryService`.
- **Security:** approve/deny are reviewer/instance-admin-guarded (404 for
  non-reviewers) mirroring `RunHistoryController`; the kill-switch toggle is
  org-subadmin/instance-admin-guarded via `IGroupManager`.
- **Upstream dependency:** requires `human-approval-gate-schema` to have landed.
- **Downstream:** the approval-inbox + kill-switch-toggle **Vue UI** is the dependent
  `human-approval-gate-ui` change (built now, split out to respect the ≤20-task cap
  and ADR-032) — this change ships the enforced backend + endpoints.
