# agent-webhook-trigger Specification

## Purpose
TBD - created by archiving change agent-webhook-trigger. Update Purpose after archive.
## Requirements
### Requirement: A per-agent webhook secret can be created, rotated, and revoked

The system MUST let the agent's owner create a webhook secret for an agent
that has none, rotate an existing secret (invalidating the previous one
immediately), and revoke a secret (disabling the webhook without deleting its
configuration). The plaintext secret MUST be returned only in the create/
rotate response body and MUST NOT be persisted or retrievable afterward — the
system stores only a SHA-256 hash of the secret.

#### Scenario: Creating a webhook secret for the first time

- **GIVEN** an agent with no `AgentWebhook` configured
- **WHEN** the agent's owner requests a new webhook secret
- **THEN** the system MUST generate a secret, persist only its SHA-256 hash,
  and return the plaintext secret exactly once in the response

#### Scenario: Rotating an existing webhook secret

- **GIVEN** an agent with an active webhook secret
- **WHEN** the agent's owner rotates the secret
- **THEN** the system MUST generate and store a new secret hash, return the
  new plaintext secret once, and immediately reject any request authenticated
  with the previous secret

#### Scenario: Revoking a webhook secret

- **GIVEN** an agent with an active, enabled webhook secret
- **WHEN** the agent's owner revokes it
- **THEN** the system MUST mark the webhook `enabled: false`
- **AND** subsequent requests to the trigger endpoint for that agent MUST be
  rejected until a new secret is created or the webhook is re-enabled

#### Scenario: A non-owner cannot manage another user's agent webhook

- **GIVEN** an agent owned by user A and a webhook secret request from user B
- **WHEN** user B calls create/rotate/revoke/status for user A's agent
- **THEN** the system MUST respond as if the agent does not exist (404), not
  with a 403, so user B cannot confirm the agent's existence

### Requirement: The public trigger endpoint authenticates by secret, not session

The system MUST expose `POST /api/agents/{id}/webhook` without requiring a
Nextcloud session, authenticating solely via a per-webhook secret sent in a
request header, compared using a constant-time algorithm against the stored
hash. Every authentication failure mode (unknown agent, no webhook
configured, a disabled webhook, or a wrong secret) MUST produce the identical
response, so the endpoint cannot be used to enumerate valid agent ids.

#### Scenario: A valid secret is accepted

- **GIVEN** an agent with an enabled webhook secret
- **WHEN** a request is sent to its trigger endpoint with the correct secret
  in the `X-Hermiq-Webhook-Secret` header
- **THEN** the system MUST accept the request and respond `202 Accepted`
  with a generated `correlationId`

#### Scenario: An invalid secret is rejected

- **GIVEN** an agent with an enabled webhook secret
- **WHEN** a request is sent with an incorrect or missing secret
- **THEN** the system MUST respond `401 Unauthorized` with a generic error
- **AND** MUST NOT enqueue any run

#### Scenario: A disabled webhook is rejected identically to a wrong secret

- **GIVEN** an agent whose webhook has been revoked (`enabled: false`)
- **WHEN** a request is sent to its trigger endpoint with the secret that was
  valid before revocation
- **THEN** the system MUST respond with the SAME `401` shape used for an
  invalid secret on an enabled webhook, so a caller cannot distinguish
  "revoked" from "never valid"

#### Scenario: An unknown agent id is rejected identically to a wrong secret

- **GIVEN** an agent id that does not exist
- **WHEN** a request is sent to its trigger endpoint with any secret
- **THEN** the system MUST respond with the SAME `401` shape used for a wrong
  secret on a real agent, so the endpoint cannot be used to enumerate valid
  agent ids

### Requirement: The payload is size-capped before it is processed

The system MUST reject a webhook request body larger than 64 KiB with
`413 Request Entity Too Large`, checked both from the `Content-Length` header
before reading the body and from the actual byte count after reading it, so
a request without (or lying about) `Content-Length` cannot bypass the cap.

#### Scenario: An oversized payload is rejected

- **GIVEN** the webhook trigger endpoint's 64 KiB payload cap
- **WHEN** a request arrives with a body larger than 64 KiB
- **THEN** the system MUST respond `413 Request Entity Too Large`
- **AND** MUST NOT enqueue a run or invoke the agent

### Requirement: The trigger endpoint is rate-limited

The system MUST rate-limit the public trigger endpoint to prevent abuse,
using Nextcloud's built-in per-IP request limiting.

#### Scenario: Excessive requests are throttled

- **GIVEN** the trigger endpoint's configured rate limit
- **WHEN** a caller exceeds it within the configured window
- **THEN** the system MUST respond `429 Too Many Requests` for subsequent
  requests until the window resets

### Requirement: A triggered run reuses the existing governed dispatch rails

A webhook-triggered run MUST pass through GATE 1 (organisation kill-switch,
via `ScheduleService::isOrganisationEngaged()`) and, when the webhook is
configured with `requiresApproval: true`, GATE 2 (human approval, via a
`sourceType: "webhook"` `Approval`) before the agent is ever invoked — the
identical data sources and services a scheduled run and a flow-triggered run
already use. It MUST NOT run synchronously within the HTTP request; the
request is enqueued as a background job and the endpoint returns
`202 Accepted` immediately.

#### Scenario: An engaged kill-switch halts a webhook-triggered run

- **GIVEN** the triggered agent's organisation has an engaged `TenantControl`
  kill-switch
- **WHEN** the enqueued webhook run is processed
- **THEN** the agent MUST NOT be invoked
- **AND** an `agent-run` AuditTrail entry with `status: "skipped_killswitch"`
  MUST be recorded against the agent object

#### Scenario: A webhook configured to require approval gates the run

- **GIVEN** an `AgentWebhook` with `requiresApproval: true`
- **WHEN** a valid, unthrottled, correctly-sized request triggers it
- **THEN** the agent MUST NOT be invoked
- **AND** exactly one pending `Approval` with `sourceType: "webhook"` MUST
  exist, carrying the run's `webhookContext` (correlationId + payload +
  agentId)
- **AND** an `agent-run` AuditTrail entry with `status: "awaiting_approval"`
  MUST be recorded

#### Scenario: Approving a webhook-sourced Approval resumes the run

- **GIVEN** a pending `Approval` with `sourceType: "webhook"` and a stored
  `webhookContext`
- **WHEN** a reviewer approves it
- **THEN** `ApprovalService::approve()` MUST resume the run via
  `WebhookAgentRunService::run(payload: webhookContext, bypassApprovalGate: true)`
- **AND** it MUST NOT touch `ScheduleService::runNow()` or
  `FlowAgentRunService::run()`

#### Scenario: The agent turn reuses ScheduleService's engine-routed dispatch

- **GIVEN** an ungated, resolvable webhook-triggered request
- **WHEN** `WebhookAgentRunService` runs the agent turn
- **THEN** it MUST call `ScheduleService::runAgentAsOwner(owner, agentId, prompt)`
  with the resolved agent's own `owner` as the acting user
- **AND** it MUST NOT invoke `ChatService`/the in-app `Engine` directly nor
  re-implement impersonation or engine-selection logic

### Requirement: The webhook payload becomes run input, redacted before persistence

The system MUST fold the webhook request body into the agent's run prompt as
additional context, and MUST redact secrets/PII from it (via the existing
redaction path) before it is written to any persisted record — the
`agent-run` AuditTrail entry, or a pending Approval's stored `webhookContext`
— while leaving the raw payload unredacted when it is handed to the agent
itself (redacting the agent's own input would defeat the endpoint's purpose).

#### Scenario: A successful run's audit entry is redacted

- **GIVEN** a webhook payload containing an API-key-shaped token
- **WHEN** the run completes and its `agent-run` AuditTrail entry is written
- **THEN** the persisted entry MUST show the token masked
- **AND** the agent's actual run MUST have received the unredacted payload as
  input

