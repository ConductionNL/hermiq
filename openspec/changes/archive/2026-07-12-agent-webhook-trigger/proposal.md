---
kind: code
depends_on: []
---

# Proposal: agent-webhook-trigger

## Summary

Give every Hermiq agent a per-agent, secret-authenticated inbound webhook
(`POST /api/agents/{id}/webhook`) so an external system (n8n, a CI pipeline, a
third-party SaaS event) can trigger a governed agent run without a Nextcloud
session. The endpoint validates a per-webhook shared secret (constant-time
compare, size-capped payload, rate-limited), then enqueues the run through the
SAME governed dispatch architecture `flow-agent-listener` already built for
OpenRegister's declarative flow trigger: kill-switch, human-approval gate, and
redacted audit — never a second, parallel "just run the agent" code path. A
webhook management panel on `AgentDetail` lets the agent owner create, rotate,
and revoke the secret.

## Motivation

Spectr evidence (domain 264, `tech-recommendation`) flags webhook/event
triggers as a first-class integration expectation — the "n8n handshake" and a
recurring "trigger-agent-from-webhook" journey story. Today Hermiq has exactly
two ways to start an agent run: a schedule (cron/interval/once) and the
session-authenticated "Run now" button. Neither fits an external system that
wants to fire an agent the moment something happens elsewhere (a form
submission, a CI job finishing, a webhook relay from n8n). Without this,
integrators either poll Hermiq (wasteful, laggy) or route through n8n's own
agent/LLM nodes instead of Hermiq's governed one — defeating the entire
point of running agents inside Hermiq's oversight rails (kill-switch,
approval gate, audit).

This is explicitly a small, additive change: reuse every governance rail that
already exists (`ScheduleService`, `ApprovalService`, `AuditTrailMapper`,
`RedactionService`) rather than inventing a new execution path, mirroring how
`flow-agent-listener` reused those same rails for OpenRegister's flow trigger.

## Affected Projects

- [x] Project: `hermiq` — new per-agent webhook trigger endpoint, webhook
  secret lifecycle (create/rotate/revoke), governed dispatch service reusing
  the existing kill-switch/approval/audit rails, and a webhook management
  panel on `AgentDetail`.

## Scope

### In Scope

- A new hermiq-register OpenRegister schema, `AgentWebhook` (one per agent),
  storing a hashed secret (never plaintext at rest), enabled/requiresApproval/
  reviewer/reviewerType fields (mirroring `Schedule`'s existing approval-gate
  fields), and lifecycle timestamps (`createdAt`/`rotatedAt`/`lastUsedAt`).
- Session-authenticated management endpoints (owner-guarded, mirroring
  `RunNowController`'s IDOR pattern) to create, rotate, revoke, and read the
  status of an agent's webhook secret. The plaintext secret is returned ONLY
  once, at creation/rotation time, and is never persisted or re-displayable.
- One new `#[PublicPage]` endpoint, `POST /api/agents/{id}/webhook`,
  authenticated by the per-webhook secret (constant-time comparison against a
  stored hash — never a Nextcloud session), rate-limited via NC's built-in
  `#[AnonRateLimit]`, and enforcing a hard payload-size cap (413 beyond it).
  Invalid secret and unknown/disabled agent both return the SAME generic 401
  so the endpoint cannot be used to enumerate valid agent ids.
- A governed dispatch service (`WebhookAgentRunService`, structurally the
  webhook-triggered sibling of `FlowAgentRunService`) that: enqueues the
  actual run as a background job (never runs synchronously in the HTTP
  request — the same `mode: async`-only discipline `flow-agent-listener`
  uses, so a slow LLM call never blocks the webhook response or risks the
  caller's own webhook-delivery timeout); applies GATE 1 (kill-switch, via
  the existing `ScheduleService::isOrganisationEngaged()`) and GATE 2 (human
  approval, via a THIRD `ApprovalService` `sourceType: "webhook"` generalisation
  alongside the existing `"schedule"`/`"flow"`); runs the agent turn via the
  existing `ScheduleService::runAgentAsOwner()`; and writes a redacted
  `agent-run` AuditTrail entry against the Agent object itself (a webhook
  trigger has no "triggering OR object" to write a result back to, unlike a
  flow-triggered run).
- The inbound webhook payload becomes part of the agent's run input (appended
  to its configured prompt), size-capped before it is ever read into memory,
  and redaction-applied before any of it is PERSISTED (audit entry, or a
  pending Approval's stored context) — not before it reaches the model, which
  would defeat the endpoint's purpose.
- A webhook management panel on `AgentDetail` (`agent-management-ui` MODIFIED
  delta): shows whether a webhook is configured/enabled, its secret prefix
  (never the full secret after the create/rotate moment), last-used time, and
  create/rotate/revoke actions with a copy-once secret reveal.

### Out of Scope

- Extending the existing `RunHistoryController`/"Run history" section (which
  is `scheduleId`-scoped) to also list webhook-triggered runs. That is a
  genuine gap this change deliberately does not close — see Open Questions.
- HMAC request-signing (à la GitHub/Stripe/OpenConnector's
  `WebhookSignatureService`). The evidence asks for a "webhook/event trigger"
  with "per-webhook secret token, constant-time compare" — a shared-secret
  header, not a signature scheme. HMAC signing is a reasonable future
  enhancement, not required for v1.
- Any new runtime quota/budget gate. Hermiq's only quota enforcement today is
  create-time (agent/schedule counts, `multi-tenant-ops`) — there is no
  per-run budget gate to reuse yet. `cost-guardrails` (a separate, higher-
  priority change) is where a runtime budget gate would land; this change
  does not invent one just for the webhook path.
- n8n as a first-class node/credential type, or any n8n-specific handshake
  beyond "an HTTP endpoint n8n's generic Webhook/HTTP Request nodes can call."
  That belongs in the `n8n-nextcloud` repo, not here.

## Approach

Structurally mirror `flow-agent-listener`: a thin, fast entry point that never
runs the agent inline, an enqueued background job, and a governed-dispatch
service that calls the SAME `ScheduleService`/`ApprovalService` methods a
scheduled run and a flow-triggered run already call. The only genuinely new
logic is (a) the webhook secret's lifecycle and constant-time verification,
and (b) a third `ApprovalService` `sourceType`, following the exact
generalisation pattern already used to add `"flow"` alongside `"schedule"`.
Full technical design, the `AgentWebhook` schema, and the auth-failure/kill-
switch scenarios are in `design.md`.

## New Dependencies

None. Reuses `hash_equals()`/`hash()`/`random_bytes()` (PHP core, already used
by `WebhookSignatureService` and others in the workspace) and Nextcloud's
built-in `#[AnonRateLimit]`/`#[PublicPage]`/`#[NoCSRFRequired]` attributes
(already used by several sibling apps — `pipelinq`, `decidesk`, `openbuild` —
for identically-shaped "public endpoint, secret-authenticated" controllers).

## Impact

- New route: `POST /api/agents/{id}/webhook` (public, secret-authenticated).
- New management routes under `/api/agents/{id}/webhook-secret`
  (session-authenticated, owner-guarded).
- New OpenRegister schema `AgentWebhook` in `hermiq_register.json` (v1.0.0,
  net-new — not a modification of an existing schema, so no back-compat
  concern).
- `ApprovalService`/`DeliveryService` generalised with a third `sourceType`
  (`"webhook"`), following the existing `"schedule"` → `"flow"` precedent.
  Existing `"schedule"`/`"flow"` behaviour is unchanged (verified: existing
  tests for both pass unmodified).
- `AgentDetail.vue` gains a new webhook panel section.

## Cross-Project Dependencies

None. The webhook's consumer is an external system (n8n, or any generic
HTTP-webhook-capable tool) — not another `apps-extra` project — so no
`contract.md` is needed (see Open Questions for the explicit skip rationale).

## Risks

### Risk 1: A leaked webhook secret triggers unwanted agent runs

**Severity:** Medium — **Mitigation:** the secret is never displayed after
creation/rotation (copy-once UX); revoke is instant and irreversible (a new
secret must be created); every triggered run still passes through the
kill-switch and (when configured) the approval gate, so a leaked secret
cannot bypass human oversight — it can only cause unwanted-but-governed runs,
never ungoverned ones.

### Risk 2: NC's built-in `#[AnonRateLimit]` is per-IP, not per-secret

**Severity:** Low — **Mitigation:** matches the existing rate-limiting
pattern used by every comparable public webhook endpoint in this workspace
(`pipelinq`, `decidesk`, `openbuild`); a distributed abuser rotating source
IPs is a known, accepted limitation of that primitive workspace-wide, not a
regression introduced by this change. Revoke-and-rotate remains the actual
abuse remedy.

### Risk 3: Agent-id enumeration via the public trigger endpoint

**Severity:** Low — **Mitigation:** the endpoint returns the identical
generic 401 for "agent does not exist," "agent has no webhook configured,"
"webhook disabled," and "wrong secret" — collapsing every failure mode into
one response shape so an attacker cannot distinguish a valid-but-locked agent
id from a nonexistent one.

## Rollback Strategy

Purely additive: a new schema, new routes, and one new UI panel. Reverting
means removing the new routes/controllers/services/schema and the
`AgentDetail` panel; nothing existing is modified except the additive
`ApprovalService`/`DeliveryService` `sourceType: "webhook"` branch, which can
be deleted without touching the `"schedule"`/`"flow"` branches it sits
alongside.

## Open Questions

- Should webhook-triggered runs eventually appear in `AgentDetail`'s existing
  "Run history" section (today `scheduleId`-scoped, per `run-audit-log`)? This
  change leaves that gap open rather than widening `RunHistoryController`'s
  scope as a side effect — a deliberate, small-blast-radius choice. A natural
  home for closing it is `run-trace-observability` (priority 3, per-run trace
  view), which will need agent-scoped (not just schedule-scoped) run reads
  anyway.
- `contract.md` is deliberately skipped: its purpose is cross-project API
  alignment within `apps-extra`, but this endpoint's consumers are external
  (n8n, third-party tools) with no `apps-extra` repo to align against.
  `discovery.md` and `migration.md` are also skipped — the feasibility of
  `#[PublicPage]`/`#[AnonRateLimit]` plus secret-in-body auth is already
  proven by `pipelinq`/`decidesk`/`openbuild` at HEAD, and there is no NC
  database migration (OpenRegister schemas are JSON config, not migrations).
