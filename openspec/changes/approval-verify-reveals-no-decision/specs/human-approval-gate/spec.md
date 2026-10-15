# human-approval-gate Specification Delta

## MODIFIED Requirements

### Requirement: Hermiq answers a signed verdict on a toolcall approval (REQ-APVER-001)

The system MUST answer `POST /api/approvals/verify` with `{approvalId, toolId, binding, actingAgent, nonce}` by HTTP 200 and `{verdict, signature}`, where the verdict echoes the five fields, carries `approved`, `reason`, `decidedBy`, `decidedAt`, `expiresAt` and `issuedAt`, and the signature is a base64 Ed25519 detached signature over the verdict's canonical JSON with the key published as the app value `approval_verdict_public_key`. `approved` MUST be true only when the approval is an approved, unexpired `toolcall` approval for that tool, binding and agent, decided by a person who is neither the acting agent nor its principal. When the reason is `unknown` or `binding-mismatch`, `decidedBy`, `decidedAt` and `expiresAt` MUST be null, because the route is public and an approval id alone must not reveal who decided it, or when.

#### Scenario: A mismatched verdict reveals no decision
- GIVEN the approval "a1" for `integriq.replayDeadLetters`, binding "b1" and agent "g1", approved by "anna"
- WHEN a caller posts approvalId "a1" with binding "b2"
- THEN the verdict says `approved: false` with reason `binding-mismatch`
- AND `decidedBy`, `decidedAt` and `expiresAt` are null
- @e2e exclude public JSON contract, covered by PHPUnit ApprovalVerdictServiceTest::testAnIdentityRefusalRevealsNoDecision
