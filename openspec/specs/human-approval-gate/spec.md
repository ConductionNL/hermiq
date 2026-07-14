# Human Approval Gate Specification

**Status**: active (all three changes merged to `development` + live-verified; tenant model = OR org UUID)

**Feature tier**: V1

**OpenSpec changes:**
- `openspec/changes/human-approval-gate-schema/` — Approval + TenantControl schemas + Schedule.requiresApproval/reviewer/reviewerType (kind: config) — **done**
- `openspec/changes/human-approval-gate-enforcement/` — dispatcher gate + kill-switch + approve/deny/kill-switch endpoints (kind: code) — **done, live-verified**
- `openspec/changes/human-approval-gate-ui/` — approval inbox + kill-switch toggle + reviewer picker (kind: code) — **done, Playwright-verified** (kill-switch keys on the OpenRegister organisation UUID, not an NC group)
- `openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/` — routes an un-granted destructive tool invocation through the approval gate (ADR-063 consumer side; kind: code) — **DONE** (adds `sourceType: "tool"` to the Approval state machine)

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
`Approval` object reaches `approved`. An agent configured with `requiresApproval` MUST NOT be
reachable as a `sub-agent-delegation` target: a delegation attempt targeting such an agent MUST be
refused outright (no `Approval` object is created and no synchronous wait occurs), while the target
agent MUST remain runnable through its own schedule or flow trigger, which retain the full
pending/approved/denied gate.
<!-- Previous behavior: the gate applied to scheduled and flow/webhook-triggered runs, which support
     pausing and resuming via a pending Approval; delegation (a synchronous, in-turn tool call with
     no pause/resume mechanism) did not exist. -->

#### Scenario: An agent run reaches a gated action awaiting approval
- GIVEN an agent's run reaches a step configured to require human approval
- WHEN the dispatch loop creates an `Approval` object in `pending` state
- THEN the system MUST NOT execute the gated action
- AND execution MUST remain blocked until the `Approval` object's state changes to `approved`

#### Scenario: A delegation targets an approval-gated agent
- **GIVEN** target agent B has `requiresApproval` set to true
- **WHEN** any allowed agent calls `hermiq.delegateAgent` targeting agent B
- **THEN** the system MUST refuse the delegation with a `delegation_requires_approval` error
- **AND** the system MUST NOT create a pending `Approval` object for this delegation attempt
- **AND** agent B MUST remain reachable via its own schedule's or flow trigger's existing
  approval gate

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
dispatch of all pending and new agent runs for that organisation until deactivated, including a
delegated sub-agent run (`sub-agent-delegation`) attempted by an already-running agent in that
organisation.
<!-- Previous behavior: the kill-switch halted scheduled, Run-now, and flow/webhook-triggered runs;
     delegated sub-agent runs did not exist. -->

#### Scenario: An org admin activates the kill-switch
- GIVEN organisation A has one or more agent runs in progress or scheduled
- WHEN an org admin activates the kill-switch for organisation A
- THEN the system MUST halt dispatch of all in-progress and newly scheduled runs for organisation A
- AND runs for other organisations MUST continue unaffected

#### Scenario: An already-running agent attempts to delegate while the kill-switch is engaged
- **GIVEN** organisation A's kill-switch is engaged while agent A (organisation A) is already
  mid-turn
- **WHEN** agent A's turn calls `hermiq.delegateAgent` targeting an allowed agent
- **THEN** the system MUST refuse the delegation with a `delegation_killswitch` error before
  invoking the target agent
- **AND** agent A's already-in-progress turn MUST be allowed to finish uninterrupted

### Requirement: Un-granted destructive tool invocation routes through the approval gate

The system MUST route an agent's attempted invocation of a write/destructive tool that is NOT
covered by an explicit per-agent grant (see `agent-tool-governance`) through this same human-approval
gate: it MUST create a pending `Approval` object (`sourceType: "tool"`, idempotent on the
`agentId` + `toolId` pair) and MUST NOT execute the invocation until that `Approval` reaches
`approved`. A destructive invocation the agent HAS been explicitly granted proceeds without a new
approval (subject to OpenRegister RBAC at invoke time).

Unlike the `schedule` and `flow` source types, a `tool` approval resumes NOTHING on decision — the
chat turn that attempted the call has already returned. Flipping the status IS the effect: the next
invocation attempt of that `(agentId, toolId)` pair finds the decided `Approval` and proceeds, or is
blocked permanently by a `denied` one.

#### Scenario: An agent attempts an un-granted destructive tool call
- GIVEN an agent whose grants do not explicitly include a destructive tool
- WHEN the agent's run attempts to invoke that tool
- THEN the system MUST create an `Approval` object in `pending` state for the responsible reviewer
- AND the system MUST NOT invoke the tool until the `Approval` reaches `approved`
- AND a denied `Approval` MUST prevent the invocation from ever executing

#### Scenario: An explicitly-granted destructive tool call is not re-gated
- GIVEN an agent whose grants explicitly include a destructive tool (by exact id or write modifier)
- WHEN the agent's run invokes that tool
- THEN the system MUST NOT require a new `Approval` solely because the tool is destructive
- AND OpenRegister RBAC MUST still authorize the invocation at invoke time

## User Stories

- As a compliance officer, I want gated agent actions to require explicit human approval so that we meet EU AI Act Article 14 obligations.
- As a reviewer, I want to be notified in Talk or Notifications when my approval is needed so that I don't have to poll a dashboard.
- As an org admin, I want a single kill-switch to halt all autonomous runs for my organisation so that I can respond quickly to an incident.
- As an auditor, I want every approval decision recorded durably so that I can reconstruct who approved what and when.
- As a compliance officer, I want an agent that reaches for a destructive tool it was never granted to be STOPPED and escalated to a human, so that default-deny has teeth at invoke time and not just in the UI.

## Acceptance Criteria

- [ ] `Approval` OR object schema exists with states pending/approved/denied
- [ ] Dispatch loop synchronously blocks gated actions until `Approval` reaches `approved`
- [ ] Denied approvals prevent the gated action from ever executing
- [ ] Pending approvals trigger a Talk or Notifications message to the reviewer
- [ ] Org-level kill-switch halts all runs for that tenant only, without affecting other tenants
- [ ] Approval decisions are recorded in OR AuditTrail
- [x] An un-granted write/destructive tool invocation creates a pending `Approval` (`sourceType: "tool"`, idempotent on agentId+toolId) and is NOT executed; a denied one blocks it permanently; an explicitly-granted one is never re-gated

## Notes

Depends on OpenRegister's `AuditTrail` (hash-chain, GDPR) for recording decisions and on Nextcloud
Talk/Notifications for delivery (ADR-005). Related: ADR-004 (governance via OR AuditTrail), ADR-001
(Option C+ boundary). Nextcloud Talk (spreed) is not installed on the current dev instance per
ADR-001 — Notifications fallback must not be optional.
