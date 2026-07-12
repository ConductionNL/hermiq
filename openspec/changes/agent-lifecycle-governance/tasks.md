# Tasks: agent-lifecycle-governance

## Implementation Tasks

### Task 1: Declarative schema changes — Incident, Agent, TenantControl
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the register is (re-)imported WHEN the schema is loaded THEN a new `Incident` schema exists with `description`, `impact`, `actionsTaken`, `linkedAgentId` (`$ref: Agent`), `linkedRunIds` (array), inheriting owner/organisation from `ObjectEntity`
  - GIVEN the `Agent` schema WHEN it is loaded THEN it carries `reassignmentFlag` (boolean, default false), `reviewedAt` (date-time), `reviewedBy` (string)
  - GIVEN the `TenantControl` schema WHEN it is loaded THEN it carries `retentionMonths` (integer, default 6)
  - GIVEN 3 seeded `Incident` objects WHEN the repair step runs THEN each links to an existing seeded Agent
- [ ] Implement
- [ ] Test

### Task 2: ScheduleService — pauseForUser()
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable`
- **files**: `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN a user owns one enabled Schedule WHEN `pauseForUser($uid)` runs THEN that Schedule's `enabled` is set `false`, persisted, and audit-written (same path as `recordGateSkip()`)
  - GIVEN an Agent's `actingUser` resolves to `$uid` (owner differs) WHEN `pauseForUser($uid)` runs THEN Schedules firing that Agent are also paused
  - GIVEN a Schedule belonging to a different user WHEN `pauseForUser($uid)` runs THEN it is left untouched
  - GIVEN a run is already in progress WHEN `pauseForUser($uid)` runs concurrently THEN the in-progress run is not interrupted (only `nextRun`/`enabled` state is affected)
- [ ] Implement
- [ ] Test

### Task 3: UserLifecycleListener — offboarding on NC user delete/disable
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable`
- **files**: `lib/Listener/UserLifecycleListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `OCP\User\Events\UserDeletedEvent` fires for uid U WHEN the listener handles it THEN `ScheduleService::pauseForUser(U)` is called and every Agent it paused a schedule for is flagged `reassignmentFlag=true`
  - GIVEN `OCP\User\Events\DisableUserEvent` fires for uid U WHEN the listener handles it THEN the same pause + flag behaviour applies
  - GIVEN the listener throws WHEN NC dispatches the event THEN the error is logged and does not abort the underlying user deletion/disable
- [ ] Implement
- [ ] Test

### Task 4: TenantOpsService — access review + reassignment
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-periodic-access-review-with-capability-summary`
- **files**: `lib/Service/TenantOpsService.php`
- **acceptance_criteria**:
  - GIVEN the caller's organisation has Agents WHEN `accessReviewList()` runs THEN it returns each Agent's owner, `actingUser`, last-run timestamp, tool/RAG summary, `reassignmentFlag`, `reviewedAt`/`reviewedBy`, scoped to the caller's org only
  - GIVEN an org admin attests an Agent WHEN `attestAgentReviewed($uuid, $uid)` runs THEN `reviewedAt`/`reviewedBy` are updated (idempotent — re-attesting updates, does not duplicate)
  - GIVEN a flagged Agent and an existing, active target user WHEN `reassignAgent($uuid, $newActingUser)` runs THEN `Agent.actingUser` is updated and `reassignmentFlag` cleared, without re-enabling any paused Schedule
  - GIVEN a non-existent or disabled target user WHEN `reassignAgent()` runs THEN it is rejected and the Agent remains flagged
- [ ] Implement
- [ ] Test

### Task 5: TenantOpsController — access-review + reassign endpoints
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-org-admin-reassignment-flow-for-flagged-agents`
- **files**: `lib/Controller/TenantOpsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated org member WHEN `GET /api/tenant-ops/access-review` is called THEN it returns that org's `accessReviewList()` payload
  - GIVEN an org admin WHEN `POST /api/tenant-ops/access-review/{uuid}/attest` is called THEN `attestAgentReviewed()` runs; a non-admin caller is rejected via `ActionAuthService`
  - GIVEN an org admin WHEN `POST /api/tenant-ops/access-review/{uuid}/reassign` is called with a target user THEN `reassignAgent()` runs; a non-admin caller is rejected
- [ ] Implement
- [ ] Test

### Task 6: TenantOpsService — incidents + audit export extension
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-are-included-in-the-art-12-audit-export`
- **files**: `lib/Service/TenantOpsService.php`
- **acceptance_criteria**:
  - GIVEN an org admin opens an incident WHEN `createIncident()` runs with description/impact/actionsTaken and optional linked agent/runs THEN an `Incident` object is persisted scoped to the caller's organisation
  - GIVEN the caller's organisation has incidents WHEN `listIncidents()` runs THEN they are returned newest-first, scoped to that organisation only
  - GIVEN incidents exist WHEN `exportAuditTrail()` runs THEN the export includes each incident's description/impact/actionsTaken and linked run/agent references alongside existing run/approval entries
- [ ] Implement
- [ ] Test

### Task 7: TenantOpsController — incidents + retention endpoints
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration`
- **files**: `lib/Controller/TenantOpsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated org member WHEN `GET /api/tenant-ops/incidents` is called THEN the org's incident list is returned
  - GIVEN an org admin WHEN `POST /api/tenant-ops/incidents` is called with valid fields THEN `createIncident()` runs; a non-admin caller is rejected via `ActionAuthService`
  - GIVEN an authenticated org member WHEN `GET /api/tenant-ops/retention` is called THEN the org's `retentionMonths` is returned (default 6)
  - GIVEN an org admin WHEN `PUT /api/tenant-ops/retention` is called with `retentionMonths < 6` THEN the request is rejected and the stored value is unchanged; `>= 6` is accepted
- [ ] Implement
- [ ] Test

### Task 8: Frontend API module
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-reviewed-attestation-is-recorded-and-auditable`
- **files**: `src/api/tenantOps.js`
- **acceptance_criteria**:
  - GIVEN the module WHEN `getAccessReview()`, `attestReviewed(uuid)`, `reassignAgent(uuid, uid)`, `getIncidents()`, `createIncident(payload)`, `getRetention()`, `setRetention(months)` are called THEN each hits its corresponding `/api/tenant-ops/*` endpoint and returns `response.data`
- [ ] Implement
- [ ] Test

### Task 9: TenantOps.vue — access review, incidents, retention UI
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-periodic-access-review-with-capability-summary`
- **files**: `src/views/TenantOps.vue`, `src/dialogs/CreateIncidentDialog.vue`
- **acceptance_criteria**:
  - GIVEN an org admin opens Tenant ops WHEN the page loads THEN an access-review `CnDataTable` lists agents (owner, actingUser, last run, capability summary, reassignment flag, reviewed state) with a "Mark reviewed" action and a "Reassign" action for flagged agents
  - GIVEN an org admin clicks "Open incident" WHEN `CreateIncidentDialog.vue` (NcDialog, own file per ADR-004 modal isolation) is submitted THEN the incident is created and appears in an incident list section
  - GIVEN an org admin views the retention section WHEN they raise the value THEN it saves via `setRetention()`; a rejected (`< 6`) value shows an inline error and does not update the displayed value
  - GIVEN a non-admin user WHEN they open Tenant ops THEN none of the new sections are shown (existing `canManage` gate covers them)
- [ ] Implement
- [ ] Test

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
