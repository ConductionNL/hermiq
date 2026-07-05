# Design: human-approval-gate-enforcement

## Context

`ScheduleService` is Hermiq's dispatcher. Today `run()` selects due, enabled
schedules and `dispatch()` fires each one: it advances run-state before the agent
turn (at-most-once / crash safety, ADR-002), impersonates the owner, invokes
OpenRegister's `ChatService`, delivers via `DeliveryService`, and writes a redacted
`action='run'` `AuditTrail` entry. `runNow()` shares the same private `dispatch()`
path, so a manual run is indistinguishable from a scheduled one. Two IDOR-guarded
controllers already model the security pattern: `RunHistoryController` and
`RunNowController` both use `@NoAdminRequired` plus an explicit `loadOwnedSchedule()`
that fetches with RBAC and returns 404 for non-owners.

This change makes EU AI Act Art. 14 oversight real: the dispatcher must **hard-block**
on the approval and kill-switch state declared by `human-approval-gate-schema`
(`Approval`, `TenantControl`, `Schedule.requiresApproval`, `reviewer`/`reviewerType`).
Advisory-only would fail Art. 14 (ADR-004). Per ADR-032 this is the enforcement code
change of the three-change chain (`schema` → `enforcement` → `ui`) and `depends_on`
the config head; the thin Vue UI is the dependent `human-approval-gate-ui` change.

This is the ADR-031 imperative exception, exactly like the existing dispatcher and
`DeliveryService`: side-effecting enforcement and guarded endpoints, not a derived
value or declarative lifecycle. No new schema is declared here.

## Goals / Non-Goals

**Goals:**
- Synchronously gate a `requiresApproval=true` schedule in `dispatch()`/`runNow`:
  create a pending `Approval` routed to the resolved reviewer instead of running,
  notify that reviewer, return.
- Resolve the reviewer from the schedule's `reviewer`/`reviewerType` (user or group),
  defaulting to the owner when empty (separation of duties, Art. 14).
- Synchronously skip every run for an organisation whose `TenantControl` is engaged,
  recording the skip in run-state and `AuditTrail`.
- Reviewer/instance-admin-guarded approve/deny endpoints that transition the
  `Approval` and, on approve, execute the run via the existing `runNow` path; on deny,
  never run.
- Org-subadmin/instance-admin-guarded kill-switch toggle endpoint writing
  `TenantControl` via `ObjectService`.

**Non-Goals:**
- Declaring any schema (done upstream in `human-approval-gate-schema`).
- The approval-inbox / kill-switch-toggle **Vue UI** — built in the downstream
  `human-approval-gate-ui` change (split out for the ≤20-task cap); this change ships
  the enforced backend + endpoints only.
- Multi-stage / quorum approvals (N-of-M reviewers) — future; this change routes to a
  single designated reviewer user or group.

## Decisions

**Gate inside the shared `dispatch()` path.** Because `run()` (tick) and `runNow()`
both funnel through the private `dispatch()`, the approval gate and the kill-switch
skip are added there (with an early branch), so a scheduled run and a "Run now" are
gated identically with zero duplicated logic. The gate branch runs BEFORE
`runAgentAsOwner()`. The existing commit-before-run advance is preserved so a gated
schedule advances its `nextRun` and does not enqueue a fresh pending `Approval` every
tick (a second tick finds the schedule future-dated / the pending approval already
open). *Alternative considered:* gate in `run()` only — rejected, it would leave
`runNow` ungated.

**Load engaged kill-switches once per tick.** `run()` fetches
`findAll(TenantControl, filters:{engaged:true}, _rbac:false, _multitenancy:false)`
once and builds a set of engaged organisations, then `dispatch()` skips any schedule
whose organisation is in the set. This keeps the check synchronous and O(1) per
schedule rather than a per-schedule query. *Alternative considered:* per-schedule
`TenantControl` lookup — rejected as N extra queries per tick.

**Approval creation and decision go through `ObjectService` (single write-path,
ADR-004).** A small `ApprovalService` owns two operations: `createPending(schedule)`
(build the pending `Approval` payload and save via `ObjectService`, inheriting
`owner`/`organisation`) and `applyDecision(approval, decision, uid, reason)` (set
`status`/`decidedAt`/`decidedBy`/`reason` and save). Both leave OR's auto-audit trace;
the explicit decision `AuditTrail` entry is written after redaction, mirroring
`writeRunAudit()`. Keeping this in a service (not the controller) keeps the controller
thin like `RunNowController`.

**Reviewer resolution + routing (separation of duties, Art. 14).** At gate time the
dispatcher resolves the reviewer from the schedule's `reviewer`/`reviewerType`: a
`user` reviewer is that uid; a `group` reviewer means "any member of this NC group may
decide" (membership resolved via `IGroupManager`); an empty `reviewer` defaults to the
schedule owner (`reviewerType=user`) so a gated schedule with no reviewer still works.
The resolved `reviewer`/`reviewerType` are copied onto the pending `Approval` so the
decision record durably captures who was asked. *Alternative considered:* resolve the
reviewer only in the endpoint guard — rejected, the notification target and the
`Approval` record both need it at creation time.

**Reviewer notification reuses `DeliveryService`.** `DeliveryService.deliver()` already
implements the Talk→Note-to-self→Notification fallback chain and never throws for a
delivery problem. The gate reuses it (or its notification path) to alert the resolved
reviewer — the designated user, or each member of the reviewer group — with a deep
link, so Talk-not-installed (ADR-001) degrades to a Nextcloud notification. A
notification failure is a warning, never fatal to the tick.

**Approve/deny guard = reviewer or instance admin (NOT owner).** `ApprovalController`
copies `RunNowController`'s shape (`@NoAdminRequired` + `@NoCSRFRequired`) but its
`loadDecidableApproval()` guard admits the caller only when they are the
`Approval.reviewer` user, or a member of the `Approval.reviewer` group (via
`IGroupManager`) when `reviewerType=group`, or a Nextcloud instance admin — and
returns 404 otherwise. The schedule owner is NOT admitted unless owner == reviewer,
enforcing separation of duties. `approve` reuses `ScheduleService::runNow` for the
bound schedule after the transition; `deny` records only.

**Kill-switch toggle guard = org sub-admin or instance admin (`IGroupManager`).** The
toggle is NOT owner-guarded. It admits a Nextcloud instance admin
(`IGroupManager::isAdmin($uid)`) or a **sub-admin of the NC group that maps to the
organisation** (the group sub-admin API). **Org→group mapping assumption:** the tenant
`organisation` value on `ObjectEntity` corresponds to an NC group id (OpenRegister's
multi-tenancy is built on NC groups per ADR-001); the guard resolves the target
organisation's group and checks sub-admin membership. If OR exposes a distinct
org→group resolver, use it; otherwise the documented assumption is
`organisation == group id`. A cross-tenant toggle (admin of a different org) is
refused. Routes are registered in `appinfo/routes.php` with explicit auth attributes
(route-auth gate).

**Skip status vocabulary.** A kill-switch skip sets `lastStatus='skipped_killswitch'`
and writes `action='skipped'` to the trail; a gate sets `lastStatus='awaiting_approval'`
and writes `action='gated'`. These are additive to the existing status vocabulary and
do not require a schema change (the `Schedule.lastStatus` property is a free string).

## Note on ADR-031 (imperative exception)

This change is a recognised ADR-031 imperative exception: it is side-effecting
dispatcher enforcement (hard-block on durable state) and guarded API endpoints — not a
derived value, aggregation, or declarative lifecycle transition. All persistence still
flows through OpenRegister `ObjectService` (single write-path), so tenancy and the
hash-chained `AuditTrail` are inherited; no new schema is introduced.

## Risks / Trade-offs

- **Gate must not re-enqueue.** [A gated schedule could create a new pending `Approval`
  every tick] → Preserve the commit-before-run advance so the schedule is future-dated
  after gating, and/or check for an already-open pending `Approval` for the schedule
  before creating another.
- **Approve triggers a real run.** [Approving executes the agent, so approve is not a
  read-only endpoint] → It reuses the proven `runNow` path (owner-impersonated, audited,
  crash-safe) and stays IDOR-guarded; agent-turn errors are caught inside `dispatch()`.
- **Kill-switch race within a tick.** [A control engaged mid-tick may not stop
  already-selected schedules] → The engaged set is read at tick start; worst case one
  in-flight schedule completes before the next tick honors the switch. Acceptable for a
  poll-based dispatcher (ADR-002); documented for operators.
- **CI env has no live NC/OR.** [PHPUnit in CI is php:8.3-cli + OCP stubs, no
  OpenRegister] → Unit-test the gate/skip branching and the IDOR guard against stubs;
  verify the create-pending / approve-runs / deny-blocks / kill-switch-skip flows live
  on NC + OpenRegister before archiving.

## Open Questions

- **RESOLVED — Reviewer routing.** Approver ≠ owner via a `reviewer`/`reviewerType`
  designation on the `Schedule` (user or group), copied onto the `Approval`; empty
  defaults to the owner. Approve/deny guarded to the reviewer (or group member) or
  instance admin.
- **RESOLVED — Kill-switch auth.** Toggle admits an NC sub-admin of the organisation's
  group or an instance admin (via `IGroupManager`), not the owner. See the org→group
  mapping assumption in Decisions.
- **RESOLVED — UI.** Built now as the dependent `human-approval-gate-ui` change (split
  out to respect the ≤20-task cap and keep this change single-kind).
- **Open — org→group resolver.** The guard assumes `organisation == NC group id`
  (ADR-001 multi-tenancy on NC groups). If OpenRegister ships an explicit org→group
  resolver, the implementer should prefer it over the assumption.
