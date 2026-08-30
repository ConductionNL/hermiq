# Design: agent-webhook-trigger

## Architecture Overview

Structurally identical to `flow-agent-listener`, with the trigger source
swapped from an OpenRegister event to an authenticated HTTP POST:

```
External caller (n8n / CI / SaaS webhook)
   -> POST /api/agents/{id}/webhook            [WebhookTriggerController — PublicPage]
        -> WebhookSecretService::verify()        [constant-time; size cap; enumeration-safe]
             -> IJobList::add(WebhookAgentRunJob, payload)   [202 Accepted, correlationId]
                  -> WebhookAgentRunJob::run($payload)         [thin wrapper, ADR-002]
                       -> WebhookAgentRunService::run($payload)  [all governed logic]
                            - GATE 1: ScheduleService::isOrganisationEngaged()   (REUSED)
                            - GATE 2: ApprovalService (sourceType: "webhook")    (REUSED, generalised)
                            - Agent turn: ScheduleService::runAgentAsOwner()     (REUSED)
                            - Audit: AuditTrailMapper::createAuditTrailEntry()   (REUSED)
```

Management (session-authenticated, owner-guarded, mirrors `RunNowController`):

```
AgentDetail.vue webhook panel
   -> POST/GET/DELETE /api/agents/{id}/webhook-secret[...]   [AgentWebhookController]
        -> WebhookSecretService (create/rotate/revoke/status)
             -> ObjectService (AgentWebhook OpenRegister object)
```

Nothing in `ScheduleService`/`ApprovalService`/`AuditTrailMapper`/
`RedactionService` changes shape for this change beyond the additive
`sourceType: "webhook"` branch (§ Decisions) — the webhook path is a new
*caller* of existing governance, not a new governance implementation.

## Goals / Non-Goals

**Goals**
- A per-agent inbound webhook that an external system can call to trigger a
  governed run, with no Nextcloud session.
- Identical kill-switch/approval/audit governance to a scheduled or
  flow-triggered run — zero duplicated gate logic.
- Secret lifecycle (create/rotate/revoke) manageable from `AgentDetail`.

**Non-Goals**
- HMAC request signing (see proposal's Out of Scope — a shared-secret header
  is what the evidence asks for).
- Surfacing webhook-triggered runs in the existing `scheduleId`-scoped Run
  History section (see proposal's Open Questions).
- Any new runtime budget/quota gate (that is `cost-guardrails`' scope).
- n8n-specific credential/node integration (that is `n8n-nextcloud`'s scope).

## Decisions

### Decision 1: Shared-secret header, not HMAC signing

The evidence (Spectr domain 264) asks for "per-webhook secret token,
constant-time compare" — a bearer-style shared secret, not a signature
scheme. `openconnector`'s `WebhookSignatureService` (HMAC-SHA256,
Stripe-style `t=<unix>,v1=<hex>`) was considered and rejected for v1: it is
the right tool when Hermiq must verify a THIRD PARTY's signing scheme
(GitHub, Stripe, SendGrid — parties that sign the way THEY choose). Here
Hermiq is the one issuing the secret to a caller of its choosing (n8n's
generic Webhook/HTTP Request node, a curl script, etc.), so a simple shared
secret the caller echoes back in a header is both sufficient and far easier
for a non-technical integrator to wire up than computing an HMAC. The
secret is sent as `X-Hermiq-Webhook-Secret: <secret>` — a dedicated header
name (not `Authorization: Bearer`) so it is never confused with, or
accidentally overwritten by, a reverse proxy's own `Authorization` handling.

### Decision 2: Store a SHA-256 hash, never the plaintext secret

Mirrors the well-established API-key storage pattern (GitHub PATs, Stripe
restricted keys): `AgentWebhook.secretHash` stores `hash('sha256', $secret)`;
the plaintext is returned to the caller ONLY in the create/rotate HTTP
response body and is never persisted or re-displayable. Verification is
`hash_equals($storedHash, hash('sha256', $providedSecret))` — comparing two
fixed-length (64 hex char) digests is exactly what PHP's `hash_equals()` is
designed for, and it means a database read alone (without the original
plaintext) can never be replayed as a valid secret.

Rejected alternative: store the raw secret in `IAppConfig`/app-config the way
`pipelinq`'s `BlastWebhookController` stores its (shared, per-provider, not
per-agent) HMAC secrets. That pattern fits ONE secret per provider read from
config; this feature needs an unbounded number of secrets (one per agent),
which is what OpenRegister objects (not app-config) are for — consistent
with Hermiq's own `Memory`/`Session`/`TenantControl` schemas already being
per-agent/per-org OR objects rather than app-config entries.

### Decision 3: A third `ApprovalService` `sourceType`, not a new approval model

`ApprovalService` already generalised once, from a single implicit
`sourceType: "schedule"` shape to an explicit `"schedule"` | `"flow"`
discriminator (`flow-agent-listener`). Adding `"webhook"` follows the
identical, now-precedented pattern: `ensurePendingApprovalForWebhookRun()`
(mirrors `ensurePendingApprovalForFlowRun()`, storing `webhookContext` instead
of `flowContext`, keyed by a generated `correlationId` for idempotency), and
`approve()` gains a third `if ($sourceType === 'webhook')` branch resuming via
`WebhookAgentRunService::run(payload: $webhookContext, bypassApprovalGate: true)`.
Rejected: a parallel `WebhookApproval` object — would duplicate the reviewer-
resolution, notification, and decision-audit code `ApprovalService` already
owns, the exact duplication `flow-agent-listener`'s design already rejected
once for the same reason.

`DeliveryService::deliverApprovalRequestForFlowRun()` and the new
`deliverApprovalRequestForWebhookRun()` share the same reviewer-notification
loop; that loop is extracted into a private `notifyApprovalReviewers()`
helper so the THIRD near-identical copy (after `deliverApprovalRequest`
originally, then `…ForFlowRun`) does not get typed out a third time —
otherwise a future FOURTH source (or a fix) drifts across three copies.

### Decision 4: Audit against the Agent object itself, not a "subject" object

`FlowAgentRunService::writeRunAudit()` attaches its `AuditTrail` entry to the
triggering OR object (a flow run always has one — that is the object the
flow is declared on). A webhook trigger has no such object; the closest
analogue is the `Agent` itself. `WebhookAgentRunService` resolves the agent
TWICE, deliberately, for two different reasons:
- `AgentMapper::findByUuid()` — the strongly-typed `Agent` entity, for its
  `owner`/`organisation` getters (acting-user + kill-switch scoping),
  exactly like `FlowAgentRunService::resolveAgent()`.
- `ObjectService::find(id: $agentId, register: 'hermiq', schema: 'agent')` —
  the SAME resolution `ScheduleService::runAgentViaEngine()` already performs
  to get the agent as an `ObjectEntity`, because `AuditTrailMapper::
  createAuditTrailEntry()` requires an `ObjectEntity` and `Agent` (a plain
  Doctrine `Entity`) is not one.

There is no `writeResultField()` counterpart to `FlowAgentRunService`'s — a
webhook run's output has nowhere durable to write except the audit entry
(and, for now, the HTTP response is already gone by the time the run
completes, since it is enqueued). This asymmetry versus `FlowAgentRunService`
is intentional, not an oversight (see proposal's Out of Scope on Run History).

### Decision 5: Async-only, exactly like `flow-agent-listener`

`WebhookTriggerController::trigger()` never calls the agent inline. It
validates the secret + size cap, generates a `correlationId`, enqueues
`WebhookAgentRunJob`, and returns `202 Accepted` immediately. This is the
same reasoning `flow-agent-listener`'s design.md gives for `mode: "async"`
only: an LLM call can take many seconds, and a caller's own webhook-delivery
mechanism (n8n's HTTP node, a CI step) typically has its own timeout that a
synchronous LLM call risks tripping. It also means the webhook endpoint's
public attack surface (the part exposed with NO Nextcloud session) is as
small and fast as possible — auth-check-and-enqueue, nothing else.

### Decision 6: Auth-failure responses are enumeration-safe by construction

Every failure mode — unknown agent id, agent with no `AgentWebhook`
configured, `enabled: false`, or a wrong secret — returns the identical
`401 {"error": "unauthorized"}`. To also make the modes indistinguishable BY
TIMING (not just by body), `WebhookSecretService::verify()` ALWAYS computes a
`hash_equals()` comparison, even when no `AgentWebhook` record exists: it
compares against a fixed, process-local dummy hash (`hash('sha256', '')`) in
that case, so "no such webhook" and "wrong secret" take the same code path
shape rather than one short-circuiting before ever calling `hash_equals()`.
This is the same rationale as Rails' `secure_compare`-against-a-dummy pattern
for "user not found" vs "wrong password" — a cheap, standard mitigation, not
a claim of perfect timing-attack immunity over a network (network jitter
already dominates any such micro-timing difference; this is defense in
depth, not the sole control).

## API Design

### `POST /api/agents/{id}/webhook`
**Auth**: `X-Hermiq-Webhook-Secret` header (per-webhook secret; NOT a
Nextcloud session). `#[PublicPage]` `#[NoCSRFRequired]`
`#[AnonRateLimit(limit: 30, period: 60)]`.

**Request:**
```json
{ "any": "JSON body — becomes part of the agent's run input, size-capped at 64 KiB" }
```

**Response (202):**
```json
{ "status": "accepted", "correlationId": "…" }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | Missing/wrong secret, unknown agent, or a disabled webhook — all identical, enumeration-safe |
| 413  | Body exceeds the 64 KiB cap (checked via `Content-Length` before read AND actual byte count after read) |
| 400  | Body present but not valid JSON (only checked AFTER the secret verifies, so this can never leak agent existence) |
| 429  | NC's built-in `AnonRateLimit` throttling |

### `POST /api/agents/{id}/webhook-secret` (create)
**Auth**: Nextcloud session; owner-guarded (404, not 403, for a non-owner —
mirrors `RunNowController`).

**Response (201):**
```json
{ "secret": "hwh_…(shown once)…", "secretPrefix": "hwh_ab12", "createdAt": "…", "enabled": true }
```
409 when a webhook already exists for this agent (use rotate instead).

### `POST /api/agents/{id}/webhook-secret/rotate`
Same response shape as create; invalidates the previous secret immediately
(no rotation grace window — unlike `WebhookSignatureService`'s outbound
rotation grace, there is no "in-flight signed request" to keep accepting;
the caller simply needs to be updated with the new secret before its next
call).

### `POST /api/agents/{id}/webhook-secret/revoke`
Sets `enabled: false`. Returns the status shape (below), never a secret.

### `PATCH /api/agents/{id}/webhook-secret`
Updates `requiresApproval`/`reviewer`/`reviewerType` only (mirrors
`Schedule`'s identical fields) — does not touch the secret.

### `GET /api/agents/{id}/webhook-secret`
**Response (200):**
```json
{
  "configured": true,
  "enabled": true,
  "secretPrefix": "hwh_ab12",
  "createdAt": "…",
  "rotatedAt": null,
  "lastUsedAt": "…",
  "requiresApproval": false,
  "reviewer": "",
  "reviewerType": "user"
}
```
`{"configured": false}` when no `AgentWebhook` exists yet for this agent.
Never includes `secretHash` or the plaintext secret.

## Database Changes

No Nextcloud migration (Hermiq owns no DB tables). One new OpenRegister
schema, `AgentWebhook`, added to `lib/Settings/hermiq_register.json`
(imported via the existing `ConfigurationService::importFromApp()` repair
step — no new import mechanism):

| property | type | required | notes |
|---|---|---|---|
| `agentId` | string (uuid, `$ref: Agent`) | yes | one `AgentWebhook` per agent |
| `secretHash` | string | yes | `hash('sha256', $secret)`; never the plaintext |
| `secretPrefix` | string | yes | first 8 chars of the plaintext, for admin identification only (not sensitive on its own) |
| `enabled` | boolean | yes, default `true` | `false` after revoke |
| `requiresApproval` | boolean | default `false` | mirrors `Schedule.requiresApproval` |
| `reviewer` | string | no | mirrors `Schedule.reviewer` |
| `reviewerType` | enum `user`\|`group` | default `user` | mirrors `Schedule.reviewerType` |
| `createdAt` | date-time | yes | |
| `rotatedAt` | date-time \| null | derived | set on each rotate |
| `lastUsedAt` | date-time \| null | derived | set on each successful trigger (best-effort, non-fatal) |
| `owner` / `organisation` | string | yes | tenant scoping, from `ObjectEntity` |

`x-openregister: { publicRead: false, publicWrite: false }` — same posture as
`TenantControl`/`Memory`; the PUBLIC read path for this schema is
deliberately NOT via OpenRegister's generic object API at all (a
`PublicPage` NC controller endpoint, `WebhookTriggerController`, is the only
public-facing surface, and it only ever calls `WebhookSecretService::verify()`
— it never returns the `AgentWebhook` object itself).

## Nextcloud Integration

- **Controllers**: `AgentWebhookController` (session-auth, owner-guarded
  CRUD — create/rotate/revoke/patch/status) and `WebhookTriggerController`
  (PublicPage — the actual inbound trigger). Kept as two classes, never
  mixed, so a PublicPage method never sits in the same class as
  session-authenticated ones (mirrors the existing split between
  `RunNowController` and `ChatHealthController`/`BlastWebhookController`-style
  PublicPage controllers).
- **Services**: `WebhookSecretService` (generate/hash/verify/rotate/revoke,
  owns `AgentWebhook` persistence via `ObjectService`); `WebhookAgentRunService`
  (governed dispatch, the webhook-triggered sibling of `FlowAgentRunService`).
- **Jobs**: `WebhookAgentRunJob`, a one-shot `QueuedJob` (ADR-002 thin
  wrapper) delegating entirely to `WebhookAgentRunService::run()` — same
  shape as `AgentRunRequestedJob`.
- **Attributes**: `#[PublicPage]` `#[NoCSRFRequired]`
  `#[AnonRateLimit(limit: 30, period: 60)]` on `WebhookTriggerController::trigger()`
  — real PHP attributes (`OCP\AppFramework\Http\Attribute\*`), the same
  combination already used by `decidesk\ParticipationController::
  submitAnonymousReaction()` and `pipelinq\BlastWebhookController` for an
  identically-shaped "public endpoint, secret-authenticated, not session"
  case.
- **Reused, not modified in shape**: `ScheduleService::isOrganisationEngaged()`,
  `ScheduleService::runAgentAsOwner()`, `AuditTrailMapper::
  createAuditTrailEntry()`, `RedactionService::redact()`.
- **Extended (additive)**: `ApprovalService` (`sourceType: "webhook"`),
  `DeliveryService` (`deliverApprovalRequestForWebhookRun()`).

## Security Considerations

- **Authentication**: per-webhook shared secret via a dedicated header, never
  an NC session — `#[PublicPage]` is deliberate and safe here because the
  METHOD BODY is the real gate (verified against `WebhookSecretService`
  before any other work happens), exactly the pattern hydra's
  route-auth/semantic-auth gates expect for a secret-authenticated public
  endpoint (mirrors `decidesk`'s `submitAnonymousReaction`).
- **Constant-time comparison**: `hash_equals()` over SHA-256 digests, with a
  dummy-hash fallback so "no such webhook" and "wrong secret" are
  code-path-identical (Decision 6).
- **Rate limiting**: `#[AnonRateLimit(limit: 30, period: 60)]` — NC's
  built-in per-IP-per-route limiter, the same primitive every comparable
  public webhook endpoint in this workspace uses (see proposal's Risk 2 for
  the known per-IP-not-per-secret limitation).
- **Payload size cap**: 64 KiB (`WebhookTriggerController::MAX_PAYLOAD_BYTES`),
  enforced BEFORE the body is fully read where possible (`Content-Length`
  fast-reject) AND re-checked on the actual byte count after reading (a
  `Content-Length`-less or lying request cannot bypass the cap) — mirrors
  `launchpad\ResourceServeController`'s "413 before loading bytes" pattern.
- **Redaction**: the raw payload is NEVER redacted before being handed to the
  agent as run input (redacting it there would defeat the endpoint's
  purpose) — redaction applies only to what gets PERSISTED: the `agent-run`
  AuditTrail entry's `summary`/context (via `RedactionService`, exactly like
  `FlowAgentRunService`), and a pending Approval's stored `webhookContext`
  when GATE 2 fires.
- **IDOR**: the management endpoints (`AgentWebhookController`) load the
  agent WITH RBAC/ownership checked and return 404 (not 403) for a non-owner
  — identical guard shape to `RunNowController::loadOwnedSchedule()`.
- **Enumeration**: see Decision 6 — one generic 401 for every auth failure
  mode on the public trigger endpoint.
- **Secret at rest**: SHA-256 hash only (Decision 2); plaintext shown once.
- **Kill-switch / approval bypass**: impossible by construction — the
  webhook path calls the SAME `ScheduleService`/`ApprovalService` methods,
  it does not re-implement or parallel them.

## File Structure

```
lib/
  Controller/
    AgentWebhookController.php        (new — session-auth CRUD)
    WebhookTriggerController.php      (new — PublicPage trigger)
  Service/
    WebhookSecretService.php          (new)
    WebhookAgentRunService.php        (new)
    ApprovalService.php               (modified — sourceType: "webhook")
    DeliveryService.php               (modified — deliverApprovalRequestForWebhookRun + shared helper)
  Cron/
    WebhookAgentRunJob.php            (new — QueuedJob thin wrapper)
  Settings/
    hermiq_register.json              (modified — new AgentWebhook schema)
src/
  views/
    AgentDetail.vue                   (modified — webhook panel section)
  modals/ or dialogs/
    WebhookSecretDialog.vue           (new — copy-once secret reveal, isolated per ADR-004)
appinfo/
  routes.php                          (modified — 6 new routes)
tests/
  Unit/Service/WebhookSecretServiceTest.php       (new)
  Unit/Service/WebhookAgentRunServiceTest.php      (new)
  Unit/Controller/WebhookTriggerControllerTest.php (new)
  Unit/Service/ApprovalServiceTest.php              (modified)
  Unit/Service/DeliveryServiceTest.php              (modified)
```

## Seed Data

### Schema: `agentwebhook`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `agentwebhook-briefing` | `agentwebhook-triage` | `agentwebhook-disabled-demo` |
| agentId | (seeded "Morning briefing" agent uuid) | (seeded "Support triage" agent uuid) | (seeded third demo agent uuid) |
| secretHash | seed-time generated, discarded after hashing | seed-time generated, discarded after hashing | seed-time generated, discarded after hashing |
| secretPrefix | `hwh_demo1` | `hwh_demo2` | `hwh_demo3` |
| enabled | `true` | `true` | `false` |
| requiresApproval | `false` | `true` | `false` |
| reviewer | `` | `admin` | `` |
| reviewerType | `user` | `group` | `user` |
| createdAt | seed timestamp | seed timestamp | seed timestamp |

**Related items per object:** none (an `AgentWebhook` is a standalone
config object; it does not reference files/notes/tasks/contacts).

## Trade-offs

- **Async-only vs. a synchronous "run and return the output" variant**: a
  synchronous variant would be simpler for a caller wanting the agent's
  answer directly in the HTTP response, but breaks the "SAME path as
  flow-agent-listener" requirement and reintroduces the blocking-LLM-call
  risk that change deliberately designed away. Rejected for v1; a caller
  needing the output synchronously can poll `GET /api/agents/{id}/webhook-secret`... 
  no — actually has no polling surface today (see Open Questions in
  proposal re: Run History) and must instead consume the result via its own
  side effect (the agent's configured tools) or a future run-trace read.
- **One `AgentWebhook` per agent vs. multiple webhooks per agent**: multiple
  webhooks (e.g. one per external system) would need a webhook-level id in
  the URL, not just the agent id. Rejected for v1 as unnecessary complexity
  — the evidence describes a single "trigger this agent" webhook per agent,
  and rotate/revoke already cover the "compromised secret" case without
  needing multiple concurrent secrets.
