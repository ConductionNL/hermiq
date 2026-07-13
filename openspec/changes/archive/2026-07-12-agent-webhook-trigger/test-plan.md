# Test Plan: agent-webhook-trigger

## Test Cases

### TC-1: Create a webhook secret (shown once)
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked`
- **type**: api
- **persona**: n/a
- **preconditions**: an agent owned by the caller with no `AgentWebhook` configured
- **steps**: `POST /api/agents/{id}/webhook-secret`
- **expected result**: 201 with a plaintext secret + prefix; a repeat `GET` on the same webhook never returns the plaintext again
- **test command**: `/test-api`

### TC-2: Rotate invalidates the previous secret immediately
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked`
- **type**: api
- **preconditions**: an agent with an active webhook secret S1
- **steps**: `POST /api/agents/{id}/webhook-secret/rotate`, then call the trigger endpoint with S1
- **expected result**: rotate returns a new secret S2; the trigger call with S1 returns 401
- **test command**: `/test-api`

### TC-3: Revoke disables the webhook
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked`
- **type**: api
- **preconditions**: an agent with an active, enabled webhook secret
- **steps**: `POST /api/agents/{id}/webhook-secret/revoke`, then call the trigger endpoint with the previously-valid secret
- **expected result**: revoke returns `enabled: false`; the subsequent trigger call returns 401 (the same shape as TC-6)
- **test command**: `/test-api`

### TC-4: Non-owner cannot manage another user's webhook
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked`
- **type**: security
- **preconditions**: agent A owned by user 1; user 2 authenticated
- **steps**: user 2 calls create/rotate/revoke/status for agent A's webhook
- **expected result**: 404 for every call (never 403 or 200)
- **test command**: `/test-security`

### TC-5: Valid secret is accepted and enqueues a run
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session`
- **type**: api
- **preconditions**: an agent with an enabled webhook secret, kill-switch not engaged
- **steps**: `POST /api/agents/{id}/webhook` with `X-Hermiq-Webhook-Secret: <valid>`
- **expected result**: 202 with a `correlationId`; the background job runs and an `agent-run` audit entry with `status: "ok"` appears
- **test command**: `/test-api`

### TC-6: Auth-failure enumeration safety
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session`
- **type**: security
- **preconditions**: one enabled webhook, one revoked webhook, one nonexistent agent id
- **steps**: call the trigger endpoint on all three with an arbitrary wrong secret, and on the enabled one with a missing header
- **expected result**: all four calls return the byte-identical `401 {"error": "unauthorized"}` body
- **test command**: `/test-security`

### TC-7: Oversized payload is rejected before processing
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-payload-is-size-capped-before-it-is-processed`
- **type**: api
- **preconditions**: an agent with an enabled webhook secret
- **steps**: POST a body > 64 KiB, once with an honest `Content-Length` and once with `Content-Length` omitted/understated
- **expected result**: both requests return 413 and no background job is enqueued in either case
- **test command**: `/test-api`

### TC-8: Rate limiting throttles excessive requests
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-trigger-endpoint-is-rate-limited`
- **type**: security
- **preconditions**: an agent with an enabled webhook secret
- **steps**: call the trigger endpoint more than the configured limit within the window from one IP
- **expected result**: requests beyond the limit return 429
- **test command**: `/test-security`

### TC-9: Kill-switch halts a webhook-triggered run
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails`
- **type**: functional
- **preconditions**: the triggered agent's organisation has an engaged `TenantControl` kill-switch
- **steps**: trigger the webhook with a valid secret; wait for the enqueued job to process
- **expected result**: the agent is never invoked; an `agent-run` audit entry with `status: "skipped_killswitch"` is recorded against the Agent object
- **test command**: `/test-functional`

### TC-10: Approval gate creates a pending webhook-sourced Approval
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails`
- **type**: functional
- **preconditions**: an `AgentWebhook` with `requiresApproval: true`
- **steps**: trigger the webhook with a valid secret; inspect the approval inbox
- **expected result**: exactly one pending `Approval` with `sourceType: "webhook"` and a stored `webhookContext` exists; the agent has not run; an `agent-run` audit entry with `status: "awaiting_approval"` is recorded
- **test command**: `/test-functional`

### TC-11: Approving a webhook-sourced Approval resumes the run
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails`
- **type**: functional
- **preconditions**: the pending Approval from TC-10
- **steps**: the reviewer approves it via the existing approval inbox
- **expected result**: the agent turn runs via `ScheduleService::runAgentAsOwner()`; `ScheduleService::runNow()`/`FlowAgentRunService::run()` are never called for this Approval
- **test command**: `/test-functional`

### TC-12: Audit redaction vs. unredacted agent input
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-webhook-payload-becomes-run-input-redacted-before-persistence`
- **type**: functional
- **preconditions**: a webhook payload containing an API-key-shaped token
- **steps**: trigger the webhook with that payload; inspect the resulting `agent-run` audit entry and (via a test double) the prompt actually sent to the agent
- **expected result**: the persisted audit entry shows the token masked; the agent's actual input contains the unredacted token
- **test command**: `/test-functional`

### TC-13: Webhook panel — create, rotate, revoke
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp`
- **type**: functional
- **persona**: Priya (ZZP developer/integrator) — the persona most likely to wire up an external webhook caller
- **preconditions**: agent detail view open for an owned agent
- **steps**: click "Create webhook", copy the shown secret, dismiss the dialog, then rotate, then revoke
- **expected result**: the secret is shown exactly once per create/rotate; the panel reflects enabled/disabled state and prefix without a full page navigation; the dialog cannot be reopened to re-reveal a past secret
- **test command**: `/test-persona-priya`

### TC-14: Accessibility of the webhook panel and copy-once dialog
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp`
- **type**: accessibility
- **preconditions**: the webhook panel rendered with and without a configured webhook
- **steps**: keyboard-navigate the panel and the copy-once dialog; run an automated WCAG scan
- **expected result**: WCAG 2.1 AA compliant — labeled controls, focus management on the dialog, no color-only state indication for enabled/disabled
- **test command**: `/test-accessibility`

### TC-15: Regression — existing schedule and flow-run approval paths unaffected
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails`
- **type**: regression
- **preconditions**: existing `ScheduleServiceTest`/`ApprovalServiceTest`/`DeliveryServiceTest` suites
- **steps**: run the full suite after this change lands
- **expected result**: all pre-existing `sourceType: "schedule"` and `sourceType: "flow"` test cases pass unmodified
- **test command**: `/test-regression`

## Coverage Summary

- Secret lifecycle (create/rotate/revoke/owner-guard): TC-1, TC-2, TC-3, TC-4 — covered
- Public trigger auth + enumeration safety: TC-5, TC-6 — covered
- Payload size cap: TC-7 — covered
- Rate limiting: TC-8 — covered
- Governed dispatch (kill-switch, approval gate, engine reuse): TC-9, TC-10, TC-11 — covered
- Redaction-before-persistence vs. unredacted agent input: TC-12 — covered
- AgentDetail webhook panel (create/rotate/revoke UI, copy-once reveal): TC-13 — covered
- Accessibility: TC-14 — covered
- No regression to existing schedule/flow approval paths: TC-15 — covered

## Out of Scope

- Webhook-triggered runs appearing in the existing `scheduleId`-scoped Run
  History section — deliberately not built (see proposal's Open Questions);
  no test case exists for it because the behaviour does not exist.
- HMAC signature verification — not part of this change (shared-secret only).
- Any runtime budget/quota gate on the webhook path — `cost-guardrails`'
  scope, not tested here.
