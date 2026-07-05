# Human Approval Gate Specification

**Status**: active (all three changes merged to `development` + live-verified; tenant model = OR org UUID)

**Feature tier**: V1

**OpenSpec changes:**
- `openspec/changes/human-approval-gate-schema/` — Approval + TenantControl schemas + Schedule.requiresApproval/reviewer/reviewerType (kind: config) — **done**
- `openspec/changes/human-approval-gate-enforcement/` — dispatcher gate + kill-switch + approve/deny/kill-switch endpoints (kind: code) — **done, live-verified**
- `openspec/changes/human-approval-gate-ui/` — approval inbox + kill-switch toggle + reviewer picker (kind: code) — **done, Playwright-verified** (kill-switch keys on the OpenRegister organisation UUID, not an NC group)

## Purpose

Provides a durable human-approval gate and a tenant-wide kill-switch for autonomous agent runs, in
line with the EU AI Act Article 14 human-oversight obligation. Approval is modeled as an OpenRegister
object state machine (pending → approved/denied) enforced synchronously in the dispatch loop, so no
gated action can execute without a recorded human decision, and an org admin can halt all runs for
their tenant instantly.

## Requirements

### Requirement: Approval object state machine enforced before execution
The system MUST model each gated action as an `Approval` OpenRegister object with state `pending`,
`approved`, or `denied`, and the dispatch loop MUST block execution of the gated action until the
`Approval` object reaches `approved`.

#### Scenario: An agent run reaches a gated action awaiting approval
- GIVEN an agent's run reaches a step configured to require human approval
- WHEN the dispatch loop creates an `Approval` object in `pending` state
- THEN the system MUST NOT execute the gated action
- AND execution MUST remain blocked until the `Approval` object's state changes to `approved`

### Requirement: Human notified via Talk/Notifications
The system MUST notify the responsible human reviewer through Nextcloud Talk or Nextcloud
Notifications whenever a new `Approval` object enters `pending` state.

#### Scenario: A pending approval is created
- GIVEN a new `Approval` object is created in `pending` state for reviewer R
- WHEN the object is persisted
- THEN the system MUST send R a notification via Nextcloud Talk or Notifications
- AND the notification MUST link to the pending approval for review

### Requirement: Org-level kill-switch halts all runs
The system MUST provide a per-organisation kill-switch that, when activated, immediately halts
dispatch of all pending and new agent runs for that organisation until deactivated.

#### Scenario: An org admin activates the kill-switch
- GIVEN organisation A has one or more agent runs in progress or scheduled
- WHEN an org admin activates the kill-switch for organisation A
- THEN the system MUST halt dispatch of all in-progress and newly scheduled runs for organisation A
- AND runs for other organisations MUST continue unaffected

## User Stories

- As a compliance officer, I want gated agent actions to require explicit human approval so that we meet EU AI Act Article 14 obligations.
- As a reviewer, I want to be notified in Talk or Notifications when my approval is needed so that I don't have to poll a dashboard.
- As an org admin, I want a single kill-switch to halt all autonomous runs for my organisation so that I can respond quickly to an incident.
- As an auditor, I want every approval decision recorded durably so that I can reconstruct who approved what and when.

## Acceptance Criteria

- [ ] `Approval` OR object schema exists with states pending/approved/denied
- [ ] Dispatch loop synchronously blocks gated actions until `Approval` reaches `approved`
- [ ] Denied approvals prevent the gated action from ever executing
- [ ] Pending approvals trigger a Talk or Notifications message to the reviewer
- [ ] Org-level kill-switch halts all runs for that tenant only, without affecting other tenants
- [ ] Approval decisions are recorded in OR AuditTrail

## Notes

Depends on OpenRegister's `AuditTrail` (hash-chain, GDPR) for recording decisions and on Nextcloud
Talk/Notifications for delivery (ADR-005). Related: ADR-004 (governance via OR AuditTrail), ADR-001
(Option C+ boundary). Nextcloud Talk (spreed) is not installed on the current dev instance per
ADR-001 — Notifications fallback must not be optional.
