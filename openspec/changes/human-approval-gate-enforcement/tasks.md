# Tasks: human-approval-gate-enforcement

## 1. ApprovalService (create pending + apply decision)

- [ ] 1.1 Create `lib/Service/ApprovalService.php` (SPDX docblock) with `createPending(ObjectEntity $schedule)` that builds a `status=pending` `Approval` payload (`scheduleId`, `agentId`, `prompt`, `requestedAt`) plus the resolved `reviewer`/`reviewerType`, and saves it via `ObjectService`, inheriting the schedule `owner`/`organisation`.
- [ ] 1.2 Add a `resolveReviewer(ObjectEntity $schedule)` helper: use the schedule's `reviewer`/`reviewerType`; default to the owner (`reviewerType=user`) when `reviewer` is empty; expose an `isReviewer($approval, string $uid)` check that admits the reviewer user, a member of the reviewer group (via `IGroupManager`), or an instance admin.
- [ ] 1.3 Add `applyDecision($approval, string $decision, string $uid, ?string $reason)` that sets `status` (`approved`|`denied`), `decidedAt`, `decidedBy`, `reason` and saves via `ObjectService`; write a redacted decision `AuditTrail` entry (mirror `writeRunAudit()`).
- [ ] 1.4 Guard against duplicate pending approvals: do not create a second pending `Approval` for a schedule that already has one open.

## 2. Dispatcher approval gate (ScheduleService)

- [ ] 2.1 In `dispatch()`, before `runAgentAsOwner()`, branch when the schedule's `requiresApproval=true`: call `ApprovalService::createPending()` (which resolves + copies the reviewer), set `lastStatus='awaiting_approval'`, write an `action='gated'` audit entry, and return without running (keep the commit-before-run advance so no re-enqueue).
- [ ] 2.2 Notify the resolved reviewer (designated user, or each member of the reviewer group — not the owner unless owner==reviewer) of the new pending `Approval` by reusing `DeliveryService` (Talk with Notifications fallback, deep-linked); a notification failure is a warning, never fatal to the tick.

## 3. Dispatcher kill-switch (ScheduleService)

- [ ] 3.1 In `run()`, load engaged controls once per tick (`findAll(TenantControl, filters:{engaged:true}, _rbac:false, _multitenancy:false)`) into a set of engaged organisations.
- [ ] 3.2 In `dispatch()`, before firing (and before the approval gate), skip any schedule whose organisation is engaged: set `lastStatus='skipped_killswitch'`, write an `action='skipped'` redacted audit entry, and return without running.

## 4. Approve / deny endpoints (reviewer/admin-guarded)

- [ ] 4.1 Create `lib/Controller/ApprovalController.php` mirroring `RunNowController`: `@NoAdminRequired` + `@NoCSRFRequired`, a `loadDecidableApproval()` that fetches with RBAC and returns 404 unless the caller passes `ApprovalService::isReviewer()` (reviewer user / reviewer-group member / instance admin) — NOT the owner unless owner==reviewer.
- [ ] 4.2 `approve($approvalId)` → `ApprovalService::applyDecision(...,'approved',...)` then execute the gated run via `ScheduleService::runNow` for the bound schedule; return the outcome.
- [ ] 4.3 `deny($approvalId)` → `ApprovalService::applyDecision(...,'denied', reason)` and record it; the gated run MUST NOT execute.

## 5. Kill-switch toggle endpoint (subadmin/instance-admin-guarded)

- [ ] 5.1 Add a toggle (controller or method) that engages/disengages an organisation's `TenantControl` via `ObjectService` (`engaged`, `engagedBy`, `engagedAt`); guard with `IGroupManager` so only an instance admin (`isAdmin`) or a sub-admin of the organisation's NC group may toggle it (org→group per the design assumption); refuse a plain owner and any cross-tenant toggle.

## 6. Routes and verification

- [ ] 6.1 Register the approve/deny/kill-switch routes in `appinfo/routes.php` with explicit auth attributes (route-auth gate); confirm each route resolves to an existing controller method (route-reachability).
- [ ] 6.2 Verify live on NC + OpenRegister: a `requiresApproval=true` schedule with a group reviewer creates a pending `Approval` routed to + notifying the reviewer (no agent run, owner not notified); a reviewer-group member approves → agent runs; deny blocks it; the owner (≠ reviewer) gets 404 on approve/deny; an engaged `TenantControl` skips all of that org's runs while other orgs run; a non-subadmin gets refused on toggle.

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
