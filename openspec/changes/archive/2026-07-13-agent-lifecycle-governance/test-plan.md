# Test Plan: agent-lifecycle-governance

## Test Cases

### TC-1: Deleting a schedule owner's NC account pauses their schedule
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: A user owns one enabled Schedule firing a daily briefing agent
- **steps**: Delete that user's Nextcloud account (`occ user:delete`)
- **expected result**: The Schedule's `enabled` field is `false`; it does not fire on the next tick; the owning Agent is flagged for reassignment
- **test command**: `/test-functional`

### TC-2: Disabling an agent's acting user pauses only that user's schedules
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable`
- **type**: functional
- **preconditions**: An Agent declares `actingUser` = U; a second Schedule in the same org is owned by a different, unaffected user
- **steps**: Disable Nextcloud user U (`occ user:disable`)
- **expected result**: Schedules resolving to U are paused and their Agent flagged; the unrelated Schedule is untouched
- **test command**: `/test-functional`

### TC-3: In-progress run completes despite an offboarding pause
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-automatic-offboarding-pause-on-nextcloud-user-deletion-or-disable`
- **type**: regression
- **preconditions**: A Schedule's run is in progress
- **steps**: Delete the owner's account while the run executes
- **expected result**: The in-progress run completes normally; only future occurrences are prevented
- **test command**: `/test-regression`

### TC-4: Org admin reassigns a flagged agent to an active user
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-org-admin-reassignment-flow-for-flagged-agents`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: An Agent is flagged for reassignment after offboarding
- **steps**: Org admin opens Tenant ops, selects the flagged Agent, assigns a new active user
- **expected result**: `Agent.actingUser` updates to the new user; `reassignmentFlag` clears; the previously paused Schedule remains disabled until separately re-enabled
- **test command**: `/test-functional`

### TC-5: Reassignment to a non-existent/disabled user is rejected
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-org-admin-reassignment-flow-for-flagged-agents`
- **type**: api
- **preconditions**: An Agent is flagged for reassignment
- **steps**: `POST /api/tenant-ops/access-review/{uuid}/reassign` with a non-existent uid, then with a disabled uid
- **expected result**: Both requests are rejected; the Agent remains flagged
- **test command**: `/test-api`

### TC-6: Access review list shows owner, actingUser, last run, capability summary
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-periodic-access-review-with-capability-summary`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: An organisation has several Agents with differing tool allowlists and owners
- **steps**: Org admin opens the Tenant ops access-review section
- **expected result**: Each Agent row shows owner, `actingUser`, last-run timestamp, and a tool/RAG summary; no other organisation's Agents appear
- **test command**: `/test-functional`

### TC-7: Cross-tenant access-review isolation
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-periodic-access-review-with-capability-summary`
- **type**: security
- **preconditions**: Organisations A and B each have Agents
- **steps**: A user in organisation A requests `GET /api/tenant-ops/access-review`
- **expected result**: Only organisation A's Agents are returned; organisation B's Agents are absent
- **test command**: `/test-security`

### TC-8: Reviewed attestation records reviewer + timestamp, idempotently
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-reviewed-attestation-is-recorded-and-auditable`
- **type**: functional
- **preconditions**: An org admin is viewing the access-review list
- **steps**: Mark one Agent as reviewed; repeat the attestation a second time
- **expected result**: `reviewedAt`/`reviewedBy` are set after the first attestation and updated (not duplicated) after the second; the attestation is visible in the audit export
- **test command**: `/test-functional`

### TC-9: Org admin opens an incident linked to a run and agent
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin)
- **preconditions**: A run produced an unexpected result
- **steps**: Org admin opens `CreateIncidentDialog.vue` from that run, fills description/impact/actionsTaken, submits
- **expected result**: The incident is persisted, scoped to the caller's organisation, referencing the linked run and Agent, and appears in the incident list newest-first
- **test command**: `/test-functional`

### TC-10: Cross-tenant incident list isolation
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-linked-to-runs-and-agents`
- **type**: security
- **preconditions**: Organisations A and B each have incident records
- **steps**: A user in organisation A requests `GET /api/tenant-ops/incidents`
- **expected result**: Only organisation A's incidents are returned
- **test command**: `/test-security`

### TC-11: Incidents appear in the Art. 12 audit export
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#requirement-incident-records-are-included-in-the-art-12-audit-export`
- **type**: api
- **preconditions**: An organisation has one or more incident records
- **steps**: `GET /api/tenant-ops/audit-export`
- **expected result**: The export includes each incident's description/impact/actionsTaken and linked run/agent references, scoped to the caller's organisation
- **test command**: `/test-api`

### TC-12: Default retention period meets the Art. 12 minimum
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration`
- **type**: functional
- **preconditions**: An organisation has no retention period explicitly configured
- **steps**: Org admin views the retention setting in Tenant ops
- **expected result**: The displayed default retention period is at least 6 months
- **test command**: `/test-functional`

### TC-13: Org admin raises the retention period
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration`
- **type**: functional
- **preconditions**: An organisation's retention period is the 6-month default
- **steps**: Org admin sets it to 12 months and reloads the page
- **expected result**: 12 months persists and is shown on reload
- **test command**: `/test-functional`

### TC-14: Sub-6-month retention value is rejected
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/multi-tenant-ops/spec.md#requirement-per-organisation-retention-period-configuration`
- **type**: api
- **preconditions**: An organisation's retention period is 6 months
- **steps**: `PUT /api/tenant-ops/retention` with `retentionMonths: 3`
- **expected result**: The request is rejected; the stored value remains 6
- **test command**: `/test-api`

### TC-15: New Tenant ops sections meet WCAG AA
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/specs/agent-lifecycle-governance/spec.md#non-functional-requirements`
- **type**: accessibility
- **preconditions**: Tenant ops page loaded with access-review, incidents, and retention sections populated
- **steps**: Run automated + manual WCAG AA checks against the new sections
- **expected result**: No WCAG AA violations (labels, focus order, contrast on the new table/dialog/form controls)
- **test command**: `/test-accessibility`

### TC-16: Mutating governance endpoints are admin-only (IDOR check)
- **spec_ref**: `openspec/changes/agent-lifecycle-governance/design.md#security-considerations`
- **type**: security
- **preconditions**: A non-admin, non-org-owner authenticated user
- **steps**: Call `attest`, `reassign`, `createIncident`, and retention `PUT` as that user
- **expected result**: Each mutating endpoint rejects the request via `ActionAuthService`; read endpoints remain accessible but tenant-scoped
- **test command**: `/test-security`

## Coverage Summary

- Automatic offboarding pause — covered (TC-1, TC-2, TC-3)
- Org-admin reassignment flow — covered (TC-4, TC-5)
- Periodic access review with capability summary — covered (TC-6, TC-7)
- Reviewed attestation recorded and auditable — covered (TC-8)
- Incident records linked to runs/agents — covered (TC-9, TC-10)
- Incidents included in the Art. 12 audit export — covered (TC-11)
- Per-organisation retention period configuration — covered (TC-12, TC-13, TC-14)
- Non-functional (accessibility) — covered (TC-15)
- Security posture of mutating endpoints — covered (TC-16)

## Out of Scope

- Automated retention-boundary enforcement (purge/archive) — not implemented in this change, so
  not tested here (see proposal.md Out of Scope).
- Bulk/mass offboarding (e.g. CSV import of a leaver list) — single-user NC lifecycle event path
  only is in scope.
- Performance load-testing of the access-review/incident reads under very large per-tenant object
  counts — both reuse the existing `loadObjects()` pattern already exercised by `multi-tenant-ops`;
  no new performance risk is introduced.
