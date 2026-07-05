# Tasks: human-approval-gate-enforcement

## 1. ApprovalService (create pending + apply decision)

- [x] 1.1 Create `lib/Service/ApprovalService.php` (SPDX docblock) with `ensurePendingApproval(ObjectEntity $schedule)` (idempotent; implements the `createPending` intent) that builds a `status=pending` `Approval` payload (`scheduleId`, `agentId`, `prompt`, `requestedAt`) plus the resolved `reviewer`/`reviewerType`, and saves it via `ObjectService`, impersonating the owner so `owner`/`organisation` are inherited.
- [x] 1.2 Add a `resolveReviewer(ObjectEntity $schedule)` helper: use the schedule's `reviewer`/`reviewerType`; default to the owner (`reviewerType=user`) when `reviewer` is empty; expose an `isReviewer($approval, string $uid)` check that admits the reviewer user, a member of the reviewer group (via `IGroupManager`), or an instance admin.
- [x] 1.3 Add `approve()` / `deny($reason)` (implementing the `applyDecision` intent) that set `status` (`approved`|`denied`), `decidedAt`, `decidedBy`, `reason` and save via `ObjectService`; write a redacted decision `AuditTrail` entry (mirror `writeRunAudit()`).
- [x] 1.4 Guard against duplicate pending approvals: `ensurePendingApproval` checks `findPendingApprovalForSchedule(scheduleId)` first and no-ops (no create, no re-notify) when one is already open.

## 2. Dispatcher approval gate (ScheduleService)

- [x] 2.1 In `dispatch()`, before `runAgentAsOwner()`, branch when the schedule's `requiresApproval=true`: call `ApprovalService::ensurePendingApproval()` (which resolves + copies the reviewer), set `lastStatus='awaiting_approval'`, write a redacted audit entry, and return without running (advance `nextRun` so no perpetual re-enqueue; repeat/enabled untouched).
- [x] 2.2 Notify the resolved reviewer (designated user, or each member of the reviewer group — not the owner unless owner==reviewer) of the new pending `Approval` via `DeliveryService::deliverApprovalRequest` (per-reviewer notification + best-effort Talk note-to-self, deep-linked); a notification failure is a warning, never fatal to the tick.

## 3. Dispatcher kill-switch (ScheduleService)

- [x] 3.1 In `run()`, load engaged controls once per tick (`loadEngagedOrganisations()` → `findAll(tenantcontrol, filters:{engaged:true}, _rbac:false, _multitenancy:false)`) into a set of engaged organisations (also loaded in `runNow`).
- [x] 3.2 In `dispatch()`, before the approval gate, skip any schedule whose organisation is engaged: set `lastStatus='skipped_killswitch'`, write a redacted audit entry, and return without running (priority over the approval bypass).

## 4. Approve / deny endpoints (reviewer/admin-guarded)

- [x] 4.1 Create `lib/Controller/ApprovalController.php` mirroring `RunNowController`: `@NoAdminRequired` + `@NoCSRFRequired`, an `ensureDecidableApproval()` guard that loads the Approval RBAC-off and returns 404 unless the caller passes `ApprovalService::isReviewer()` (reviewer user / reviewer-group member / instance admin) — NOT the owner unless owner==reviewer — plus 409 when already decided.
- [x] 4.2 `approve($approvalId)` → `ApprovalService::approve()` (sets approved) then executes the gated run via `ScheduleService::runNow(schedule, bypassApprovalGate:true)` (resolved lazily from the container); returns the outcome.
- [x] 4.3 `deny($approvalId)` → `ApprovalService::deny(reason)` and record it; the gated run MUST NOT execute.

## 5. Kill-switch toggle endpoint (subadmin/instance-admin-guarded)

- [x] 5.1 Add `TenantControlService` (read/toggle `TenantControl` via `ObjectService`: `engaged`, `engagedBy`, `engagedAt`) + `TenantControlController` (show/toggle) guarded with `IGroupManager`/`ISubAdmin` so only an instance admin (`isAdmin`) or a sub-admin of the organisation's NC group may toggle it (org==group id per the design assumption); refuse a plain owner and any cross-tenant toggle.

## 6. Routes and verification

- [x] 6.1 Register the approve/deny/kill-switch routes in `appinfo/routes.php` with explicit auth attributes (route-auth gate); each route resolves to an existing controller method (route-reachability gate PASS).
- [x] 6.2 Verified live on NC 34 + OR 0.2.17: a `requiresApproval=true` schedule (reviewer = a distinct user) run → `awaiting_approval`, no agent run, exactly ONE pending `Approval` routed to the reviewer, `nextRun` advanced; a second run created NO duplicate (idempotent); an unauthorized user (neither owner/admin/reviewer) got HTTP 404 on approve; the reviewer approved → agent ran (`ran:true`, schedule `lastStatus=ok`); the instance admin can also approve; deny → `status=denied`, no run (schedule stays `awaiting_approval`); engaging the org's `TenantControl` (as instance admin) → a normal run returned `skipped_killswitch` (no agent, `nextRun` advanced); a non-subadmin/non-admin got HTTP 403 on toggle; disengage OK. (Group-reviewer path is unit-tested; verified the user-reviewer path live.)

## Acceptance criteria

- A gated schedule (or Run-now on it) creates a pending `Approval` routed to the resolved reviewer and does NOT run the agent until approved; approve runs it via the shared `runNow` path, deny blocks it permanently.
- The reviewer is resolved from the schedule's `reviewer`/`reviewerType` (user or group), defaulting to the owner when empty; the pending `Approval` carries the resolved reviewer.
- A pending `Approval` notifies the resolved reviewer (user or group members, not the owner unless owner==reviewer) via Talk-or-Notifications with a deep link; a delivery failure never fails the tick.
- An engaged `TenantControl` synchronously halts every run for that organisation (recorded as `skipped_killswitch` + audit) while other organisations are unaffected.
- Approve/deny are guarded to the reviewer (or reviewer-group member) or instance admin (404 otherwise, owner not admitted unless owner==reviewer); the kill-switch toggle is guarded to an org sub-admin or instance admin via `IGroupManager` (no cross-tenant toggle).
- All Approval/TenantControl reads and writes go through OpenRegister `ObjectService` (single write-path); decisions and skips are recorded in the hash-chained `AuditTrail` after redaction.

## Quality reminders

- SPDX `@license`/`@copyright` tags inside each PHP file docblock; pass `composer check:strict`.
- No stub bodies, no `var_dump`/`error_log`/`die`; do not edit shared OR stubs to pass tests (no mock-based fixes).
- Do not use sed/awk/scripts to edit PHP — use the Edit tool; add `@spec` docblock tags referencing this change's tasks.
- Redaction-before-persist: mask the summary/output before any `AuditTrail` write (ADR-004).
- Run PHPUnit the CI way (php:8.3-cli + OCP stubs, no live NC/OR); verify the gate/approve/deny/kill-switch flows LIVE before archiving.
