# Design: delivery-channels

## Context

`DeliveryService::deliver(channel, output, schedule)` is the sole seam
`ScheduleService::runDue()` calls after an agent turn completes
(`lib/Service/ScheduleService.php`, `runDue()` → `deliver()` →
`appendDeliveryStep()`). It is a coordinator of Talk/Notification alerts today
(`talk`/`notification`/`none`), returns a `DeliveryResult` that is NEVER
allowed to make a run fail, and its outcome feeds two things: the schedule's
`lastDeliveryError` field and exactly one `delivery` step in the run's trace
(`run-trace-observability`). Both new channels must fit that exact contract —
no new background job, no new failure mode that can abort a run.

The hardest constraint in this change is **not** email (a mechanical
`IMailer` call) — it is where the outbound webhook's HMAC signing secret
lives. The workspace's architecture law says "apps hold no secrets"
(OpenRegister's credential broker, or Doriath, custody everything). Both were
read at HEAD before writing this design, and neither fits:

- **OpenRegister's `CredentialBrokerService::request()`**
  (`openregister/lib/Service/Credential/CredentialBrokerService.php:162-202`)
  is a constrained HTTP-proxy: it takes `(credentialId, appId, method, path,
  headers, body, actingUserId)`, resolves the secret server-side, and makes
  the call ITSELF via `IClientService` — the caller never sees the secret,
  which is exactly right when Hermiq is calling a THIRD PARTY's fixed API
  (which is what `llm-keys-via-broker` does for OpenAI/Fireworks). But
  `ProviderCatalogue::resolveAndLockUrl()`
  (`openregister/lib/Service/Credential/ProviderCatalogue.php:460-472`) host-
  locks every call to a `baseUrl` in the admin-curated, runtime-immutable
  `credential-providers.json` — github/gitlab/mollie/stripe/openai/etc. An
  outbound webhook's whole point is an **arbitrary, user-configured URL**;
  that is structurally incompatible with a host-locked catalogue.
  `credential-providers.json`'s own `$fleetComment` says this explicitly:
  *"Self-hosted targets (xWiki, and OpenConnector's arbitrary Sources) cannot
  be host-locked from an immutable file at all — they need a per-install,
  admin-approved provider registration, which is a broker design change."*
  That is precisely Hermiq's shape here. The same comment also lists
  *"scholiq's tenant RSA key — needs a SIGN operation, it is never sent as a
  request header"* as a case the broker cannot express today — HMAC-signing
  is a sign operation, not a header-injection.
- **Doriath** (`apps-extra/doriath`) was investigated as the next candidate
  because OpenRegister itself uses it for non-brokerable secrets
  (`DoriathCredentialStore.php`). Doriath's real, shipped surface is exactly
  two stateless crypto services (`EncryptService::rsaEncrypt()`,
  `DecryptService::rsaDecrypt()`) plus an application-scoped ciphertext CRUD
  (`SecretService`) — a **zero-knowledge ciphertext custody** model: Doriath
  never computes anything on the caller's behalf; the caller RSA-decrypts in
  its OWN process using its own private key (which the caller itself keeps in
  `ICredentialsManager` — see `DoriathCredentialStore::PRIVATE_KEY_ID`). There
  is no `sign()`/`hmac()` operation anywhere in Doriath's source. Adopting
  this pattern for Hermiq would additionally mean: self-registering Hermiq as
  a Doriath "application", provisioning and custodying an RSA keypair, and
  reimplementing `DoriathCredentialStore`'s ~470 lines of lazy-resolution/
  migration/failure-closed logic — disproportionate infrastructure for one
  per-schedule signing secret, and still not a "sign without ever holding the
  secret" shape (the plaintext materializes in Hermiq's own process either
  way, exactly as it does in `DoriathCredentialStore::decryptRow()`).

Given neither path fits, this design stores the webhook signing secret via
`OCP\Security\ICredentialsManager` — Nextcloud's OWN sanctioned "store/
retrieve a secret an app must read back later" API
(`store(userId, identifier, credentials)` / `retrieve(userId, identifier)` /
`delete(userId, identifier)`). This is not "the credential broker", but it
directly fixes the actual failure mode the broker work was created to close:
`BrokerHttpClient.php`'s docblock describes Hermiq's old LLM keys as having
"sat in cleartext inside the `hermiq.llm` JSON blob in `oc_appconfig`,
readable by anything that could read the database, and printed verbatim by
`occ config:app:get hermiq llm`." `ICredentialsManager` entries are NOT
`oc_appconfig` rows and are NOT readable via `occ config:app:get` — they are
Nextcloud's dedicated, encrypted-at-rest credential store, the same API
`DoriathCredentialStore` itself uses to hold OpenRegister's own Doriath
private key. See Decision 1.

## Goals / Non-Goals

**Goals**
- `deliver=email`: send the run's (redacted) output to the owner or a
  configured recipient via `OCP\Mail\IMailer`.
- `deliver=webhook`: POST the run's (redacted, size-capped) output to a
  configured URL, HMAC-signed, with a bounded retry.
- Both channels obey the identical never-throws `DeliveryResult` contract
  every existing channel already obeys.
- A per-schedule webhook secret lifecycle (mint/rotate/revoke), reveal-once,
  never re-displayed.

**Non-Goals**
- Any named chat platform (Slack/Matrix/Telegram/WhatsApp/Teams) — see
  proposal's Out of Scope; that is OpenConnector's catalogue, reached BY the
  webhook this change adds.
- A replay-window/timestamp signing scheme (Stripe-style `t=…,v1=…`) — a bare
  `sha256=<hex>` is what the evidence and brief ask for (see proposal's Open
  Questions).
- Fan-out to multiple webhook URLs per schedule.
- Changing the Talk/notification code paths in any way.
- A generic "custom credential provider" or "sign" capability for
  OpenRegister's broker or Doriath — that is a cross-app, multi-repo change
  or Doriath, filed as a follow-up (proposal's Open Questions), not built
  here.

## Decisions

### Decision 1: The webhook secret lives in `OCP\Security\ICredentialsManager`, not the credential broker or Doriath

See Context above for the full investigation. Concretely:
`WebhookSecretService::mint()` generates a random secret (`random_bytes(32)`,
hex-encoded — same entropy class as `WebhookSecretService::generateSecret()`
already uses for the INBOUND `agent-webhook-trigger` secret), stores it via
`$credentialsManager->store($owner, 'hermiq/webhook-secret/'.$scheduleUuid,
$secret)`, and returns the plaintext to the caller exactly once (never
persisted anywhere else, never logged). `deliverWebhook()` retrieves it via
`retrieve()` immediately before signing and lets it fall out of scope
immediately after. `rotate()` overwrites the same identifier (the previous
value is gone the instant `store()` succeeds — no grace window, mirroring
`agent-webhook-trigger`'s rotate semantics). `revoke()` calls `delete()` and
additionally flips `deliver` back to `none` server-side is NOT done
automatically (see Decision 5) — revoke only removes the ability to sign;
an owner who revokes without changing `deliver` away from `webhook` will see
every subsequent delivery attempt fail closed with a clear
`lastDeliveryError` ("no signing secret configured"), which is the same
fail-closed shape the broker itself uses for a missing credential.

Rejected: storing the secret as a new `hermiq_register.json` schema field
(like `AgentWebhook.secretHash`). Rejected because that field would sit in an
OpenRegister object, readable through the generic object API by anything
with RBAC read on the `schedule` schema — a materially wider exposure surface
than `ICredentialsManager`, which only the exact backend PHP code holding a
reference to the injected service can ever read. It would also need to be
retrievable (not hash-only, unlike `AgentWebhook.secretHash`), which makes
"just a schema field" the worst of both worlds: broader read exposure AND a
recoverable plaintext.

### Decision 2: Bounded retry with backoff — reuse the SHAPE, not `run-reliability`'s config fields

`run-reliability` established `retryMaxAttempts` (1-10, default 3) /
`retryBackoffBaseSeconds` (≥1, default 60) on `Schedule`, governing whether
the ENTIRE AGENT TURN is re-run (`ScheduleService::scheduleRetry()`,
`backoffBase * 2^(attempt-1)`, minutes-scale, resumed on a LATER dispatcher
tick). Webhook delivery retry is a different concern operating on a different
timescale: retrying one already-completed run's OUTBOUND HTTP POST,
synchronously, within the SAME tick, seconds-scale. Reusing the identical
config fields would force one number to mean two incompatible things
("retry the whole agent run in minutes" vs. "retry one HTTP POST in
seconds"). Instead, `deliverWebhook()` mirrors the exact FORMULA
`run-reliability`'s `scheduleRetry()` established
(`backoffBase * 2^(attempt-1)`) with its own small, hard-bounded constants:
`deliverWebhookMaxAttempts` (1-5, default 3) and
`deliverWebhookBackoffBaseSeconds` (1-30, default 2) on `Schedule`, retried
synchronously in-process (no new background job, no new `IJobList` entry —
consistent with `run-reliability`'s own design.md precedent of extending the
existing poll rather than adding infrastructure). Worst case (5 attempts,
base 30s): `30+60+120+240+480` ≈ 15 minutes — deliberately still bounded
below by defaults (3 attempts, base 2s: `2+4` = 6s) that make the common case
cheap; an owner who wants a more patient retry can raise the bounds, capped
so a misconfigured schedule cannot hang a tick indefinitely (mirrors
`retryMaxAttempts`/`retryBackoffBaseSeconds`'s own schema-level min/max
rationale).

Each HTTP attempt also carries its own short timeout
(`deliverWebhookTimeoutSeconds`-free — hard-coded 10s via
`IClientService`'s `timeout` option, not user-configurable, so a hung
endpoint cannot itself defeat the attempt budget).

### Decision 3: `X-Hermiq-Signature: sha256=<hex>` over the exact bytes sent, GitHub-style

The brief specifies `X-Hermiq-Signature`, sha256 HMAC over the body. This
mirrors GitHub's `X-Hub-Signature-256` shape (`sha256=<hex>`) rather than
Stripe's `t=<unix>,v1=<hex>` scheme `openconnector`'s `WebhookSignatureService`
already uses — chosen because that scheme exists in this workspace to VERIFY
a THIRD PARTY's inbound signature (GitHub/Stripe/SendGrid choose their own
scheme); here Hermiq is the signer of its OWN outbound call, and the
brief/evidence ask for a signature, not a replay-window. The signature is
computed over the JSON body EXACTLY as sent (after redaction and truncation,
in that order — Decision 4), so a receiver's verification only ever needs
`hash_hmac('sha256', $rawBody, $secret)`.

### Decision 4: Redact, then truncate to the size cap, then sign — in that order

Redaction MUST run first: truncating before redacting could cut a secret in
half, leaving a still-recognisable fragment past the cap. The size cap
(`WEBHOOK_MAX_PAYLOAD_BYTES = 65536`, matching `agent-webhook-trigger`'s
inbound `WebhookTriggerController::MAX_PAYLOAD_BYTES` for a consistent,
already-precedented number, not a new one invented for the outbound
direction) truncates the `output` field only (never the envelope's
`scheduleId`/`agentId`/`status`/`deliveredAt` metadata) with a trailing
`"… [truncated]"` marker when the full JSON envelope would exceed it. The
signature is computed over the FINAL, truncated, redacted bytes — a receiver
never has to reconstruct a pre-truncation body to verify.

Email applies the same redact-before-send rule (the brief calls this out
explicitly for email) but no size cap — SMTP/`IMailer` already handles large
bodies, and there's no signature to keep byte-exact.

### Decision 5: `deliverTarget` keeps its existing per-channel meaning; no new "target" field

`deliverTarget` already means "a Talk room token, or empty for the owner's
Note-to-self" depending on `deliver=talk`. This change extends the SAME
field's meaning: `deliver=email` → an email address (empty → the owner's own
Nextcloud account email, resolved via `IUserManager::get($owner)->
getEMailAddress()`, mirroring how an empty Talk target already falls back to
an owner-scoped default); `deliver=webhook` → the destination URL (required —
an empty webhook target is a hard misconfiguration, recorded as a
`lastDeliveryError`, never attempted). Rejected: a new `deliverEmailTarget`/
`deliverWebhookUrl` field pair — `deliverTarget` is already documented in the
schema as channel-dependent, and splitting it would mean carrying three
mutually-exclusive-but-simultaneously-present fields on every `Schedule`
object for no behavioural gain.

### Decision 6: Generalise the `delivery` trace step's `name`, not its `type`

`ScheduleService::appendDeliveryStep()` hard-codes `'name' => 'Talk delivery'`
today. `run-audit-log`'s spec already describes the run trace's step
categories generically ("context, tool, LLM, delivery") — the literal string
`"Talk delivery"` is an implementation label, not a spec-pinned value. This
change makes the label reflect `DeliveryResult::getChannel()` (`'Talk
delivery'` / `'Notification delivery'` / `'Email delivery'` / `'Webhook
delivery'` / `'No delivery'` for `none`) instead of a single hard-coded
string. The step `type` stays `'delivery'` — unchanged, so nothing consuming
`toolStepsAvailable`/step `type` filtering is affected.

## API Design

### `POST /api/schedules/{id}/webhook-secret` (mint)
**Auth**: Nextcloud session; owner-guarded (404, not 403, for a non-owner —
mirrors `RunNowController`/`AgentWebhookController`).
**Response (201):** `{ "secret": "hws_…(shown once)…", "createdAt": "…" }`
409 when a secret already exists for this schedule (use rotate instead).

### `POST /api/schedules/{id}/webhook-secret/rotate`
Same response shape; invalidates the previous secret immediately (no grace
window — mirrors `agent-webhook-trigger`'s rotate).

### `POST /api/schedules/{id}/webhook-secret/revoke`
Deletes the stored secret. Response: `{ "configured": false }`.

### `GET /api/schedules/{id}/webhook-secret`
**Response (200):** `{ "configured": true, "createdAt": "…", "rotatedAt":
null }` or `{ "configured": false }`. Never includes the secret.

## Database Changes

No Nextcloud migration (Hermiq owns no DB tables). `lib/Settings/
hermiq_register.json`'s `Schedule` schema (MODIFIED, additive only):

| property | type | notes |
|---|---|---|
| `deliver` | enum | `talk`\|`notification`\|`none`\|`email`\|`webhook` (was missing the last two) |
| `deliverWebhookMaxAttempts` | integer, 1-5, default 3 | webhook-delivery retry budget (Decision 2) |
| `deliverWebhookBackoffBaseSeconds` | integer, 1-30, default 2 | webhook-delivery backoff base (Decision 2) |
| `deliverWebhookSecretConfigured` | boolean, derived, default `false` | UI hint only — never the secret itself |
| `deliverWebhookSecretRotatedAt` | date-time \| null, derived | mirrors `AgentWebhook.rotatedAt` |

`deliverTarget`'s description is updated to document its three per-channel
meanings (Decision 5) — no type/shape change.

The actual secret is NOT a schema field — it lives in `ICredentialsManager`
keyed by `hermiq/webhook-secret/<scheduleUuid>` (Decision 1).

`appinfo/info.xml`: patch version bump (`0.1.52` → `0.1.53`) — the register
re-import is version-gated.

## Nextcloud Integration

- **Controllers**: `ScheduleWebhookSecretController` (new) — session-auth,
  owner-guarded mint/rotate/revoke/status, mirroring
  `AgentWebhookController`'s shape exactly (404-not-403 for a non-owner).
- **Services**:
  - `DeliveryService` (modified) — `+ deliverEmail()`, `+ deliverWebhook()`,
    both returning `DeliveryResult` exactly like every existing method;
    constructor gains `IMailer`, `IClientService`, and `RedactionService`.
  - `WebhookSecretService` (new) — mint/rotate/revoke/status via
    `ICredentialsManager`; no OpenRegister object, no schema of its own (the
    three derived `Schedule` fields above are written by `ScheduleService`/
    this service directly onto the `Schedule` object it already owns).
- **Mappers/Entities**: none new.
- **Events/Hooks**: none new — `deliver()` is still called from the same
  `ScheduleService::runDue()` site.

## Security Considerations

- **Secret custody**: `ICredentialsManager`, never `oc_appconfig`, never an
  OpenRegister object field, never logged (mirrors the exact discipline
  `BrokerHttpClient`/`WebhookSecretService` (inbound) already apply — "never
  log the body", "never persist the plaintext").
- **IDOR**: `ScheduleWebhookSecretController` loads the schedule
  RBAC/ownership-checked and returns 404 (not 403) for a non-owner, identical
  guard shape to `RunNowController::loadOwnedSchedule()`.
- **SSRF surface**: the webhook URL is owner-supplied and can name any host,
  including internal/private addresses — this is the SAME trust model
  `openconnector`'s arbitrary Sources already accept (an owner configuring
  their own instance can already reach internal hosts through many other NC
  features); no new SSRF guard is introduced beyond NC's own `IClientService`
  defaults, which is a explicitly accepted, not overlooked, scope boundary
  (flagged for the security reviewer).
- **Redaction before crossing the instance boundary**: both new channels
  redact via the existing `RedactionService::redact()` before the output
  leaves the process — Talk/notification remain unredacted (unchanged;
  they never leave the instance).
- **Size cap**: 64 KiB, checked before signing, so a receiver can never be
  handed an unbounded body (Decision 4).
- **Timeout**: a hard 10s per-attempt HTTP timeout so a hostile/hung endpoint
  cannot stall a dispatcher tick beyond the bounded retry budget.
- **Constant-output-shape errors**: any failure (missing secret, DNS
  failure, non-2xx response, timeout) records a `lastDeliveryError` and NEVER
  throws — identical contract to every existing `DeliveryService` method.

## NL Design System

- `ScheduleFormModal.vue`'s existing `deliverOptions` NcSelect gains two more
  entries (`Email`, `Webhook`) — no new component.
- An `NcTextField` for the email recipient / webhook URL, shown conditionally
  exactly like the existing Talk-room-token field
  (`v-if="form.deliver === 'talk'"` becomes an `'email'`/`'webhook'` sibling
  condition).
- A small webhook-secret control (mint/rotate/revoke + reveal-once display),
  following `agent-webhook-trigger`'s reveal-once dialog pattern
  (`WebhookSecretDialog.vue`) — a NEW, isolated dialog file per ADR-004
  modal-isolation (own file under `src/dialogs/`, not inlined into
  `ScheduleFormModal.vue`).
- No new color/spacing tokens.

## File Structure

```
lib/
  Settings/
    hermiq_register.json                    # MODIFIED — Schedule.deliver enum
                                             #   + 4 new derived/config fields
  Service/
    DeliveryService.php                     # MODIFIED — + deliverEmail(),
                                             #   deliverWebhook(), generalised
                                             #   trace-step name source
    WebhookSecretService.php                # new — ICredentialsManager custody
    ScheduleService.php                     # MODIFIED — appendDeliveryStep()
                                             #   reads the channel-specific name
  Controller/
    ScheduleWebhookSecretController.php      # new — owner-guarded CRUD
appinfo/
  routes.php                                # MODIFIED — 4 new routes
  info.xml                                  # MODIFIED — patch bump
src/
  modals/
    ScheduleFormModal.vue                   # MODIFIED — 2 new deliver options
                                             #   + conditional fields
  dialogs/
    ScheduleWebhookSecretDialog.vue          # new — reveal-once secret UI
  api/
    schedules.js                            # new (or extended) — webhook-
                                             #   secret mint/rotate/revoke calls
l10n/
  en.json / nl.json                         # MODIFIED — new strings
tests/
  Unit/Service/DeliveryServiceTest.php       # MODIFIED
  Unit/Service/WebhookSecretServiceTest.php  # new
  Unit/Controller/ScheduleWebhookSecretControllerTest.php  # new
```

## Seed Data

No new schema (additive fields on the existing `Schedule` schema only) — one
of the existing seed `Schedule` objects is given `deliver: "webhook"` with a
placeholder `deliverTarget` (an `https://example.com/…` sink, clearly
non-functional out of the box) so the new channel is visible on a fresh
install; the seed step does NOT mint a real `ICredentialsManager` secret
(there is nothing meaningful to sign against a placeholder URL) — the
schedule's `deliverWebhookSecretConfigured` stays `false` until an owner
mints one for a real target, which is itself the intended, visible "no
secret configured yet" UI state.

## Trade-offs

- **`ICredentialsManager` vs. building real broker/Doriath sign-custody**:
  the latter is the architecturally cleaner long-term answer but is a
  multi-repo, cross-app capability change, wildly disproportionate to a
  bounded, one-PR gap-closing change. Chosen: the sanctioned NC API today,
  with a follow-up filed (proposal's Open Questions) rather than silently
  reinventing encryption or, worse, quietly putting the secret in a schema
  field.
- **Synchronous in-tick retry vs. a new background job/queue**: a new
  `IJobList` entry per webhook attempt would add real infrastructure
  (dead-letter handling, at-most-once semantics) for what is, worst case, a
  15-minute bounded loop already running inside a background `TimedJob` tick
  — not a user-facing HTTP request. Rejected as unnecessary complexity,
  consistent with `run-reliability`'s own design.md precedent of extending
  the existing poll instead of adding a second execution path.
