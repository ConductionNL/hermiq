# Proposal: agent-lifecycle-governance

## Summary

Hermiq governs an agent's **runs** (audit trail, approval gate, tenant kill-switch) but has no
lifecycle governance around the **owning human**. Today, deleting or disabling a Nextcloud user
leaves that user's agents and schedules running unattended; there is no periodic review of who
owns which agent and what it can do; and there is no durable incident record when something goes
wrong. This change adds the missing offboarding, access-review, incident-record, and retention
layer on top of the existing tenant/audit/approval foundation, closing the EU AI Act Art. 12
(record-keeping) and Art. 14 (human oversight) gaps that apply across an agent's whole lifecycle,
not just its individual runs.

## Motivation

Hermiq agents can run unattended on a schedule, impersonating a Nextcloud user
(`Agent.actingUser`, falling back to the schedule owner). Nothing today reacts when that person
leaves the organisation or is disabled: `ScheduleService::findDueSchedules()` only checks the
`enabled` flag on the Schedule object and is otherwise indifferent to the owning user's NC account
state, so a deleted/disabled user's agents keep firing. This is exactly the "unattributable /
orphaned agent" risk industry guidance (Gartner agent-sprawl, CSA non-human-identity governance)
calls out, and it is a live gap against EU AI Act Art. 14 (a human must be able to oversee/stop the
system) once that human no longer exists.

Separately, `human-approval-gate` and `multi-tenant-ops` give point-in-time controls (approve one
run, halt one org) but no **periodic** review surface — an org admin cannot today see "who owns
which agent, what can it do, when did it last run" in one place and attest that they checked it.
EU AI Act Art. 12 requires record-keeping across the AI system's lifecycle, not just individual
generations; a dated, auditable review record is part of demonstrating that oversight actually
happened, not just that it was theoretically possible.

Finally, `run-audit-log`/`multi-tenant-ops` capture what an agent *did*, but nothing lets an org
admin record what *humans did about it* when an agent misbehaved — an incident record linking the
narrative (what happened, impact, remedial action) to the runs/agent involved. Without this, the
Art. 12 audit export is a raw event stream with no human-authored account of incident response, and
it under-supports Art. 14's operator obligation to be able to intervene and document that
intervention. Retention (Art. 12 record-keeping duration) is also unconfigured today — audit
records are kept indefinitely by OpenRegister's `AuditTrail` with no stated policy surfaced to the
tenant.

Now is the right time because `multi-tenant-ops`, `human-approval-gate`, and `run-audit-log` are
all shipped and live-verified, giving this change stable seams to attach to (`TenantOpsService`/
`TenantOps.vue`, `ScheduleService`, the `Approval`/`AuditTrail` model) rather than needing to
invent new infrastructure.

## Affected Projects

- [ ] Project: `hermiq` — new NC user-lifecycle listener (offboarding), a periodic access-review
  surface (list + reviewed attestation), an incident-record object + list UI, and a retention
  setting surfaced in `TenantOps.vue`.

## Scope

### In Scope

- **Offboarding**: on Nextcloud `UserDeletedEvent`/`BeforeUserDeletedEvent` and disabled-user
  transitions, auto-pause (`enabled=false`) every Schedule owned by (or whose Agent's
  `actingUser` resolves to) the affected user, flag the affected Agent(s) for reassignment, and
  give an org admin a reassignment flow (assign a new owner/`actingUser` and re-enable, or leave
  paused).
- **Periodic access review**: an org-admin view listing the org's agents with owner, last-run
  timestamp, and a capability-profile summary (tools, `actingUser`, RAG scope), plus a per-agent
  "reviewed" attestation (timestamp + reviewer uid) that is itself an auditable record.
- **Incident records**: an org admin can open an incident record (description, impact, actions
  taken) from a run or an agent, linking it to the relevant run(s)/agent, stored as an OpenRegister
  object, and included in the existing Art. 12 audit export (`TenantOpsService::exportAuditTrail`).
- **Retention statement**: a configured retention period (default ≥ 6 months per Art. 12), surfaced
  read/write in `TenantOps.vue` alongside the existing quota/export sections.

### Out of Scope

- Actually purging/rotating audit data at the retention boundary (this change surfaces and stores
  the *stated policy*; automated enforcement of that policy is deferred — OpenRegister's
  `AuditTrail` retains records regardless, and building an enforcement job is a separate, larger
  change).
- Agent-versioning/config diff-and-rollback, dry-run/preview execution, and drift-detection —
  explicitly deferred to the roadmap (not spec'd here).
- Cross-app RPC into OpenRegister for any new lifecycle-triggered behaviour (e.g. a hard reject at
  object-create) — any such seam discovered during apply is filed as an OR issue, not
  hand-implemented here.
- Bulk/mass offboarding tooling (e.g. CSV import of a leaver list) — this change covers the
  single-user NC lifecycle event path only.

## Approach

Extend the existing `IEventDispatcher` listener pattern (`lib/Listener/AgentRunRequestedListener`,
`DeepLinkRegistrationListener`) with a new listener on Nextcloud's `OCP\User\Events` (user
deleted/disabled), which delegates to `ScheduleService` for the pause mechanic (same `enabled`
field flip `runDue()`/`recordGateSkip()` already use) and flags the owning `Agent` for
reassignment. Reuse `TenantOpsService`/`TenantOps.vue` as the home for the access-review list,
retention setting, and incident-record entry points, following the same tenant-scoped
`ObjectService` read pattern already used for quota/audit-export. Model the incident record as a
new declarative OpenRegister schema (alongside `Approval`/`TenantControl`) so it inherits
owner/organisation scoping and shows up in `AuditTrail` automatically (ADR-004 single write-path).
The "reviewed" attestation is a small field set on the `Agent` object (or a lightweight companion
record) written through the same `ObjectService` path — no new store.

## New Dependencies

None.

## Impact

- `lib/Listener/` — new `UserOffboardingListener` (or similarly named) registered in
  `lib/AppInfo/Application.php`.
- `lib/Service/ScheduleService.php` — a new method to pause all schedules for a given owner/
  `actingUser` (reusing the existing `enabled`-flip + `persist()` + audit-write pattern).
- `lib/Settings/hermiq_register.json` — new `Incident` schema; `Agent` schema gains
  reassignment-flag + review-attestation fields; `TenantControl` or a new small config schema gains
  the retention-period field (needs a design-time decision — see Open Questions).
- `lib/Service/TenantOpsService.php` / `lib/Controller/TenantOpsController.php` — new methods for
  the access-review list, review attestation, incident CRUD (list/create), and retention read/write;
  `exportAuditTrail()` extended to include incident records.
- `src/views/TenantOps.vue` / `src/api/tenantOps.js` — new sections (access review table,
  incident list + "open incident" action, retention setting).
- Possibly a new `src/views/` component if the access-review table and incident list are too large
  to fit as `TenantOps.vue` sections (final layout is a design.md decision).

## Cross-Project Dependencies

None. This is a self-contained hermiq change; it references but does not modify OpenRegister
(reuses `ObjectService`/`AuditTrail` exactly as `multi-tenant-ops` and `run-audit-log` already do).

## Risks

### Risk 1: Auto-pause races with an in-flight run
**Severity:** Medium — **Mitigation:** the pause only flips `Schedule.enabled=false` for *future*
occurrences (identical semantics to the existing kill-switch/approval gate skip in
`ScheduleService::dispatch()`); an already-dispatched run completes normally and is unaffected,
matching the "gate skip doesn't disable, pause does" distinction already documented in
`recordGateSkip()`.

### Risk 2: NC user-deleted event fires with no reliable "which agents does this user own" index
**Severity:** Medium — **Mitigation:** reuse the existing tenant-scoped `findAll()` read pattern
(`TenantOpsService::loadObjects()`) filtered by `owner`/`actingUser`, run system-wide
(`_rbac:false`) the same way `ScheduleService::findDueSchedules()` already does for the tick loop,
so the listener does not depend on a new index.

### Risk 3: Retention "statement" could be mistaken for retention "enforcement"
**Severity:** Low — **Mitigation:** the UI and spec language are explicit that this is a
configured/stated policy, not an automated purge; enforcement is called out as out of scope both
here and in the spec's Notes section.

## Rollback Strategy

Each piece is additive (new listener, new schema, new service methods, new UI sections) with no
migration of existing data. Rollback is: disable/remove the new listener registration in
`Application.php` (schedules stop being auto-paused but nothing already paused reverts
automatically — an admin would need to manually re-enable any schedules paused during the rollout
window), and hide the new `TenantOps.vue` sections. No existing schema fields are altered, only
added, so no backward-incompatible data change occurs.

## Open Questions

- Where does the retention-period field live: a new field on `TenantControl` (already
  per-organisation), or a new small config schema? — resolve in design.md.
- Does the "reassignment flow" reassign `Schedule.agentId`'s underlying `Agent.actingUser`, the
  `Schedule`'s owner, or both — and does reassignment require the new owner to already exist as an
  active NC user? — resolve in design.md.
- Should the periodic-review "reviewed" attestation expire (e.g. considered stale after 90 days)
  and surface as a due-for-review flag, or is a bare timestamp sufficient for MVP? — resolve in
  design.md.
