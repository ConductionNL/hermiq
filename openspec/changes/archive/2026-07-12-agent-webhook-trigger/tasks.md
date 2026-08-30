# Tasks: agent-webhook-trigger

## Implementation Tasks

### Task 1: AgentWebhook schema + secret lifecycle service
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/WebhookSecretService.php`
- **acceptance_criteria**:
  - GIVEN the new `AgentWebhook` schema (agentId, secretHash, secretPrefix, enabled, requiresApproval, reviewer, reviewerType, createdAt, rotatedAt, lastUsedAt) WHEN `hermiq_register.json` is imported THEN the schema validates and the register import succeeds
  - GIVEN `WebhookSecretService::generateSecret()` WHEN called THEN it returns a `hwh_`-prefixed secret with >= 32 bytes of entropy, and `hash()`/`verify()` operate on its SHA-256 digest only (plaintext never persisted)
  - GIVEN `WebhookSecretService::rotate()` on an existing webhook WHEN called THEN the previous secret's hash no longer verifies and a new secret/hash/prefix/rotatedAt are persisted
- [x] Implement
- [x] Test

### Task 2: AgentWebhookController (session-authenticated, owner-guarded CRUD)
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked`
- **files**: `lib/Controller/AgentWebhookController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an agent owner WHEN they POST/PATCH/GET/revoke their own agent's webhook-secret routes THEN the request succeeds per `WebhookSecretService`
  - GIVEN a non-owner WHEN they call any webhook-secret route for another user's agent THEN the response is 404 (never 403), mirroring `RunNowController::loadOwnedSchedule()`
  - GIVEN a create request when a webhook already exists WHEN called THEN the response is 409 instructing the caller to rotate instead
- [x] Implement
- [x] Test

### Task 3: WebhookTriggerController (public, secret-authenticated, enumeration-safe)
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-public-trigger-endpoint-authenticates-by-secret-not-session`
- **files**: `lib/Controller/WebhookTriggerController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `POST /api/agents/{id}/webhook` with `#[PublicPage] #[NoCSRFRequired] #[AnonRateLimit(limit: 30, period: 60)]` WHEN a correct secret is sent THEN the response is 202 with a generated correlationId and a background job is enqueued
  - GIVEN a wrong secret, a disabled webhook, or an unknown agent id WHEN each is tried in turn THEN all three produce the byte-identical 401 response body
  - GIVEN a body over 64 KiB (via Content-Length or actual byte count) WHEN posted THEN the response is 413 and no job is enqueued
- [x] Implement
- [x] Test

### Task 4: WebhookAgentRunJob + WebhookAgentRunService (governed dispatch)
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails`
- **files**: `lib/Cron/WebhookAgentRunJob.php`, `lib/Service/WebhookAgentRunService.php`
- **acceptance_criteria**:
  - GIVEN an engaged kill-switch for the agent's organisation WHEN the enqueued job runs THEN `ScheduleService::isOrganisationEngaged()` halts it before the agent is invoked and an `agent-run` audit entry with `status: "skipped_killswitch"` is recorded against the resolved Agent ObjectEntity
  - GIVEN an ungated, resolvable request WHEN the job runs THEN it calls `ScheduleService::runAgentAsOwner(owner, agentId, prompt)` — the resolved agent's own `owner`, never a re-implemented engine call
  - GIVEN a run failure WHEN the agent turn throws THEN `WebhookAgentRunService::run()` returns false, never throws, and records `status: "error"`
- [x] Implement
- [x] Test

### Task 5: ApprovalService sourceType: "webhook" generalisation
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails`
- **files**: `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN an `AgentWebhook` with `requiresApproval: true` WHEN a valid trigger request is processed THEN `ensurePendingApprovalForWebhookRun()` creates exactly one pending `Approval` (idempotent by correlationId) with `sourceType: "webhook"` and stored `webhookContext`
  - GIVEN a pending `sourceType: "webhook"` Approval WHEN a reviewer approves it THEN `approve()` resumes via `WebhookAgentRunService::run(payload: webhookContext, bypassApprovalGate: true)` without touching `ScheduleService::runNow()` or `FlowAgentRunService::run()`
  - GIVEN the existing `"schedule"`/`"flow"` branches WHEN this change ships THEN their existing tests pass unmodified
- [x] Implement
- [x] Test

### Task 6: DeliveryService webhook-approval notification + shared reviewer-notify helper
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-the-webhook-payload-becomes-run-input-redacted-before-persistence`
- **files**: `lib/Service/DeliveryService.php`
- **acceptance_criteria**:
  - GIVEN a pending `sourceType: "webhook"` Approval WHEN it is created THEN `deliverApprovalRequestForWebhookRun()` notifies each resolved reviewer via Talk/Notifications, reusing a shared private helper with the existing `deliverApprovalRequest`/`deliverApprovalRequestForFlowRun` reviewer-loop (no third copy-pasted loop)
  - GIVEN a run's `agent-run` audit context or a stored `webhookContext` WHEN persisted THEN the payload is passed through `RedactionService::redact()` first, while the agent's own run input remains unredacted
- [x] Implement
- [x] Test

### Task 7: AgentDetail webhook panel + copy-once secret dialog
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp`
- **files**: `src/views/AgentDetail.vue`, `src/dialogs/WebhookSecretDialog.vue`
- **acceptance_criteria**:
  - GIVEN an agent detail view for an agent with no webhook WHEN the owner clicks "Create webhook" THEN the secret is created and shown once in `WebhookSecretDialog`, and the panel afterward shows only the masked prefix
  - GIVEN an agent detail view showing an enabled webhook WHEN the owner rotates or revokes it THEN the panel reflects the new state (new prefix + rotatedAt, or disabled) without a full page navigation
  - GIVEN the new panel strings WHEN added THEN both `nl_NL` and `en_US` translations exist (ADR-005)
- [x] Implement
- [x] Test — live browser coverage deferred to the playwright-regression-coverage change (compile-level verified: eslint 0 errors, hydra gate-13 modal-isolation PASS, gate-12 nc-input-labels PASS)

### Task 8: Test suite completion and full-suite regression check
- **spec_ref**: `openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-triggered-run-reuses-the-existing-governed-dispatch-rails`
- **files**: `tests/Unit/Service/WebhookSecretServiceTest.php`, `tests/Unit/Service/WebhookAgentRunServiceTest.php`, `tests/Unit/Controller/WebhookTriggerControllerTest.php`, `tests/Unit/Service/ApprovalServiceTest.php`, `tests/Unit/Service/DeliveryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the full PHPUnit suite WHEN run after this change THEN all pre-existing tests still pass unmodified and the new test files cover every scenario in `specs/agent-webhook-trigger/spec.md` and the new `agent-management-ui` requirement
  - GIVEN the enumeration-safety requirement WHEN tested THEN a dedicated test asserts byte-identical 401 responses for wrong-secret / disabled / unknown-agent cases
- [x] Implement
- [x] Test

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`),
  including the enumeration-safety (identical 401 across wrong-secret/disabled/
  unknown-agent), size-cap (413), and async-enqueue-not-inline-run assertions
- New/changed API endpoints (webhook-secret CRUD + public trigger) covered by
  Newman/Postman tests
- The AgentDetail webhook panel covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` (user-facing)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the new
  webhook panel strings (ADR-007)
- `openspec validate` passes
