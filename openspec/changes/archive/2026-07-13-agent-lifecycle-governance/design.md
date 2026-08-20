# Design: agent-lifecycle-governance

## Architecture Overview

Hermiq already governs a **run** (audit trail, approval gate, org kill-switch) but has no
governance around the **owning human's** lifecycle, no periodic review of the standing
agent/schedule inventory, and no incident-record trail. This change adds four thin surfaces, all
built on the same three seams the app already uses everywhere:

1. **A new NC event listener** (`lib/Listener/UserLifecycleListener.php`), registered in
   `Application.php` next to `AgentRunRequestedListener`/`DeepLinkRegistrationListener`, listening
   for `OCP\User\Events\UserDeletedEvent` and `OCP\User\Events\DisableUserEvent`. It delegates the
   actual pause mechanic to a new `ScheduleService::pauseForUser(string $uid): int` method that
   reuses the exact `enabled=false` + `persist()` + audit-write path `runDue()`/`recordGateSkip()`
   already use — no new mutation primitive.
2. **`TenantOpsService`/`TenantOpsController`/`TenantOps.vue`** gain three new read/write surfaces
   (access-review list + attestation, incident list + create, retention get/set), following the
   exact `loadObjects()` tenant-scoped-read pattern the quota/audit-export methods already use.
3. **One new OpenRegister schema, `Incident`**, declared in `lib/Settings/hermiq_register.json`
   alongside `Approval`/`TenantControl`, so it inherits owner/organisation scoping and an
   `AuditTrail` entry for free (ADR-004 single write-path) — no new store, no new table.
4. **Small field additions** to `Agent` (`reassignmentFlag`, `reviewedAt`, `reviewedBy`) and
   `TenantControl` (`retentionMonths`) rather than new schemas, because these are attributes of
   objects that already exist and are already tenant-scoped.

```
NC UserDeletedEvent / DisableUserEvent
        │
        ▼
UserLifecycleListener  ──▶  ScheduleService::pauseForUser()
        │                         │  (enabled=false on every Schedule
        │                         │   owned by / actingUser = uid,
        │                         │   audit-written, same as existing
        │                         │   gate-skip path)
        │                         ▼
        │                   Agent.reassignmentFlag = true
        ▼
TenantOps.vue (org admin)
  ├─ Access review table  ──▶ TenantOpsController::reviewList() / ::attestReview()
  ├─ Incident list         ──▶ TenantOpsController::incidents() / ::createIncident()
  └─ Retention setting      ──▶ TenantOpsController::retention() [GET/PUT]
        │
        ▼
TenantOpsService::exportAuditTrail() — extended to also walk Incident objects
```

## API Design

### `GET /api/tenant-ops/access-review`
Lists the caller's organisation's agents with owner, last-run timestamp, capability summary
(tools/actingUser/RAG scope), and review attestation state.

**Response:**
```json
{
  "agents": [
    {
      "uuid": "…",
      "name": "Permit drafting assistant",
      "owner": "j.doe",
      "actingUser": null,
      "lastRunAt": "2026-07-10T08:00:00Z",
      "tools": ["openregister.searchObjects"],
      "reassignmentFlag": false,
      "reviewedAt": "2026-06-01T09:00:00Z",
      "reviewedBy": "org.admin"
    }
  ]
}
```

### `POST /api/tenant-ops/access-review/{uuid}/attest`
Records a reviewed attestation for one agent (idempotent — re-attesting just bumps the timestamp).

**Request:** `{}` (no body — reviewer/timestamp are derived server-side from the session)

**Response:**
```json
{ "uuid": "…", "reviewedAt": "2026-07-12T10:00:00Z", "reviewedBy": "org.admin" }
```

### `GET /api/tenant-ops/incidents`
Lists the caller's organisation's incident records, newest first.

**Response:**
```json
{
  "incidents": [
    {
      "uuid": "…",
      "description": "Agent posted a duplicate reply to the same Talk thread three times.",
      "impact": "Minor — no data leak, user confusion only.",
      "actionsTaken": "Paused the schedule, added a dedup guard, re-enabled.",
      "linkedAgentId": "…",
      "linkedRunIds": ["…", "…"],
      "createdAt": "2026-07-11T14:00:00Z",
      "createdBy": "org.admin"
    }
  ]
}
```

### `POST /api/tenant-ops/incidents`
Opens a new incident record, optionally linked to an agent and/or one or more runs.

**Request:**
```json
{
  "description": "…",
  "impact": "…",
  "actionsTaken": "…",
  "linkedAgentId": "…",
  "linkedRunIds": ["…"]
}
```

**Response:** the created incident (same shape as the list entry above).

### `GET /api/tenant-ops/retention`
**Response:** `{ "retentionMonths": 6 }`

### `PUT /api/tenant-ops/retention`
**Request:** `{ "retentionMonths": 12 }` (MUST be `>= 6`; rejected otherwise)

**Response:** `{ "retentionMonths": 12 }`

All six endpoints follow the existing `TenantOpsController` pattern: `@NoAdminRequired` +
`@NoCSRFRequired` (tenancy is the guard on reads), but the three **mutating** endpoints
(`attest`, `createIncident`, retention `PUT`) additionally gate through `ActionAuthService`
(ADR-023) restricted to org owners/instance admins — mirroring how `human-approval-gate-ui`'s
kill-switch toggle and `ai-feature-governance-register`'s acknowledge/enable/disable are gated,
since these are governance actions, not ordinary CRUD (OWASP A01 / ADR-005 Rule 3).

## Database Changes

None — no new NC database tables. All new data lives as OpenRegister objects (Postgres via
OpenRegister's own schema), declared in `lib/Settings/hermiq_register.json` and imported through
the existing `ConfigurationService::importFromApp()` repair step.

## Nextcloud Integration

- **Controllers:** `TenantOpsController` gains `reviewList()`, `attestReview($uuid)`,
  `incidents()`, `createIncident()`, `retention()` (GET), `updateRetention()` (PUT). Routes added
  to `appinfo/routes.php`.
- **Services:** `TenantOpsService` gains `accessReviewList()`, `attestAgentReviewed()`,
  `listIncidents()`, `createIncident()`, `getRetentionMonths()`, `setRetentionMonths()`;
  `exportAuditTrail()` extended to also walk `Incident` objects (same `appendRecords()` helper,
  new `objectType: 'incident'`). `ScheduleService` gains `pauseForUser(string $uid): int`.
- **Mappers/Entities:** none new — reuses `ObjectService`/`AuditTrailMapper` exactly as
  `TenantOpsService` already does.
- **Events/Hooks:** new `lib/Listener/UserLifecycleListener.php` implementing
  `IEventListener<UserDeletedEvent|DisableUserEvent>`, registered via
  `IEventDispatcher::registerEventListener()` in `Application::register()`, following the same
  registration shape as `AgentRunRequestedListener`.

## Security Considerations

- The three mutating endpoints (attest, createIncident, retention PUT) are gated through
  `ActionAuthService::requireAction()` restricted to org owners/instance admins, not any
  authenticated user — the read endpoints stay `@NoAdminRequired` with tenancy as the guard
  (identical posture to the existing `quota`/`audit-export` endpoints), consistent with
  `hydra-gate-no-admin-idor` / ADR-005 Rule 3.
- `pauseForUser()` runs system-wide (`_rbac:false`, `_multitenancy:false`) inside the listener —
  the same posture `ScheduleService::findDueSchedules()` already uses for the tick loop — because
  the listener fires outside any user session and must be able to see every tenant's schedules to
  find the ones owned by the deleted/disabled user.
- Incident records and the access-review attestation are themselves written through
  `ObjectService` (ADR-004 single write-path), so they land in `AuditTrail` automatically — no
  separate audit code path to keep in sync.
- Retention is a **stated policy value** only (surfaced, validated `>= 6` months); this change
  does not implement automated purge, so there is no risk of accidentally deleting audit data
  through this surface.

## NL Design System

`TenantOps.vue`'s new sections reuse the existing `NcButton`/`NcNoteCard`/`NcLoadingIcon`/
`NcEmptyContent` set already imported there. The access-review table and incident list use
`CnDataTable` (nc-vue) for consistency with other Hermiq list surfaces, rather than hand-rolled
markup. No new CSS variables — inherits the page's existing `--color-*` tokens.

## File Structure

```
lib/
  Listener/
    UserLifecycleListener.php        (new)
  Service/
    ScheduleService.php              (modified: + pauseForUser())
    TenantOpsService.php             (modified: + accessReviewList(), attestAgentReviewed(),
                                                   listIncidents(), createIncident(),
                                                   getRetentionMonths(), setRetentionMonths();
                                                   exportAuditTrail() walks Incident too)
  Controller/
    TenantOpsController.php          (modified: + 6 new endpoints)
  Settings/
    hermiq_register.json             (modified: + Incident schema; Agent + reassignmentFlag/
                                                   reviewedAt/reviewedBy; TenantControl +
                                                   retentionMonths)
  AppInfo/
    Application.php                  (modified: register UserLifecycleListener)
src/
  views/
    TenantOps.vue                    (modified: + access-review table, incident list/create,
                                                   retention field)
  api/
    tenantOps.js                     (modified: + 6 new API functions)
```

## Seed Data

### Schema: `incident`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug (`@self`) | `incident-duplicate-reply` | `incident-stale-credential` | `incident-runaway-schedule` |
| description | "Agent posted a duplicate reply to the same Talk thread three times." | "A shared API credential expired mid-run, causing three consecutive failed runs." | "A misconfigured cron schedule fired every minute instead of daily for six hours." |
| impact | "Minor — no data leak, user confusion only." | "Moderate — daily briefing missed for one team for a day." | "Moderate — elevated LLM token spend, no data exposure." |
| actionsTaken | "Paused the schedule, added a dedup guard, re-enabled." | "Rotated the credential, notified the schedule owner, re-enabled." | "Engaged the kill-switch, fixed the cron expression, disengaged." |
| linkedAgentId | (seeded Agent 1 uuid) | (seeded Agent 2 uuid) | (seeded Agent 3 uuid) |
| createdBy | `org.admin` | `org.admin` | `org.admin` |

**Related items per object:** none (incidents are self-contained narrative records; they link to
existing seeded Agent/Schedule objects by uuid, no Files/Notes/Tasks/Contacts).

`Agent`/`TenantControl` field additions (`reassignmentFlag`, `reviewedAt`, `reviewedBy`,
`retentionMonths`) are additive fields on existing seeded objects — no new seed objects required;
existing Agent seeds default `reassignmentFlag: false`, and `TenantControl` seeds default
`retentionMonths: 6`.

## Trade-offs

- **Reuse `TenantOps.vue` vs. a new dedicated view.** Chose to extend the existing page (three new
  sections) over a new `AgentLifecycleGovernance.vue`, because all three new surfaces are
  org-admin operational controls — exactly `TenantOps.vue`'s existing purpose — and splitting them
  out would duplicate the `canManage`/loading/error scaffolding for no benefit. If the page grows
  too large during apply, splitting the incident list into its own view is a reasonable follow-up,
  not a blocker.
- **`Incident` as its own schema vs. folding into `Approval`.** `Approval` is a state machine
  (pending/approved/denied) tied to a *gate*; an incident is a free-form narrative record with no
  state machine, opened *after the fact*. Reusing `Approval` would overload its meaning and force
  awkward null fields. A small dedicated schema is clearer and matches the `TenantControl`
  precedent (one small schema per distinct governance concern).
- **`retentionMonths` on `TenantControl` vs. a new schema.** `TenantControl` is already the
  per-organisation governance-config object (kill-switch); adding one more per-org policy field
  there avoids a schema for a single integer. Resolves proposal Open Question 1.
- **Reassignment reassigns `Agent.actingUser` (or clears it back to schedule-owner default), not
  `Schedule.owner`.** `ScheduleService::resolveActingUser()` already treats `actingUser` as the
  primary "who does this run as" field with schedule-owner fallback; reassignment should target
  the same field so the existing resolution logic requires no changes. Reassigning
  `Schedule.owner` (a different tenancy-relevant field on a different object) is out of scope for
  MVP — the reassignment flow sets `Agent.actingUser` to the new user and clears
  `reassignmentFlag`; an org admin must still manually re-enable each paused Schedule (explicit,
  auditable action rather than an automatic mass re-enable). Resolves proposal Open Question 2.
- **Review attestation is a bare timestamp + reviewer for MVP, no staleness/expiry flag.** Keeps
  the schema minimal; a "due for review" indicator (e.g. flag agents not reviewed in 90 days) is a
  natural follow-up once real review cadence data exists, but is not required to satisfy Art. 12's
  record-keeping duty (the record that a review happened, and when, is what matters). Resolves
  proposal Open Question 3.

## Open Questions

None outstanding — all three proposal open questions are resolved above under Trade-offs.
