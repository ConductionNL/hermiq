# human-approval-gate Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- approval-verification-contract

## Purpose

Let another app trust an approval made in Hermiq without reading Hermiq's approval objects (hermiq#1045).

## ADDED Requirements

### Requirement: Hermiq answers a signed verdict on a toolcall approval (REQ-APVER-001)

The system MUST answer `POST /api/approvals/verify` with `{approvalId, toolId, binding, actingAgent, nonce}` by HTTP 200 and `{verdict, signature}`, where the verdict echoes the five fields, carries `approved`, `reason`, `decidedBy`, `decidedAt`, `expiresAt` and `issuedAt`, and the signature is a base64 Ed25519 detached signature over the verdict's canonical JSON with the key published as the app value `approval_verdict_public_key`. `approved` MUST be true only when the approval is an approved, unexpired `toolcall` approval for that tool, binding and agent, decided by a person who is neither the acting agent nor its principal.

#### Scenario: An approved batch is confirmed
- GIVEN the approval "a1" for `integriq.replayDeadLetters`, binding "b1" and agent "g1", approved by "anna" five minutes ago
- WHEN integriq posts approvalId "a1", toolId `integriq.replayDeadLetters`, binding "b1", actingAgent "g1" and a nonce
- THEN the verdict says `approved: true`, reason `approved`, decidedBy "anna", echoes the nonce
- AND the signature verifies against the published public key

#### Scenario: A verdict for another batch is refused
- GIVEN the same approval
- WHEN integriq posts binding "b2"
- THEN the verdict says `approved: false` with reason `binding-mismatch`

#### Scenario: The agent approved its own batch
- GIVEN the approval was decided by the agent's acting user
- WHEN integriq asks
- THEN the verdict says `approved: false` with reason `approver-is-agent`

### Requirement: A staged batch raises an approval that keeps its binding (REQ-APVER-002)

When an `integriq.*` tool answers `status: staged` with a `proposal` and a `binding`, the system MUST raise one pending `toolcall` approval for that agent and tool that stores the binding, and MUST return its id to the agent as `approvalId` in the tool result.

#### Scenario: An agent stages a dead-letter replay
- GIVEN an agent calls `integriq.replayDeadLetters` for 14 ids
- WHEN integriq answers `status: staged` with binding "b1"
- THEN a pending approval with binding "b1" waits for the reviewer
- AND the agent's tool result carries its `approvalId`

### Requirement: Hermiq passes the acting agent to integriq's agent tools (REQ-APVER-003)

The system MUST set `agentId` to the running agent's id in the arguments of the six integriq agent tools, overwriting any value the model supplies.

#### Scenario: The model names another agent
- GIVEN agent "g1" calls `integriq.runSynchronization` with `agentId: "g2"`
- WHEN Hermiq dispatches the call
- THEN integriq receives `agentId: "g1"`
