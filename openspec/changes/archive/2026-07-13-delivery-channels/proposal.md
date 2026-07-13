# Proposal: delivery-channels

## Summary

Hermiq delivers a scheduled run's output to exactly one place today: Nextcloud
Talk, with a Note-to-self → Notification fallback chain (`talk-delivery`,
`DeliveryService::deliver()`). That is the sovereign default and stays the
primary channel. This change adds exactly two first-party delivery channels —
**email** (via Nextcloud's own `OCP\Mail\IMailer`) and an **outbound signed
webhook** (HMAC-SHA256, bounded retry, size-capped) — so a run's output can
also reach a mailbox or an external system, closing the gap Hermes covers with
a 22-platform gateway daemon and Manus/Khoj cover with Slack/WhatsApp/Telegram
delivery. Hermiq does not, and will not, grow per-platform chat adapters —
that gap is closed by OpenConnector's connector catalogue calling Hermiq's new
outbound webhook, not by Hermiq itself speaking Slack/Matrix/Telegram/WhatsApp/
Teams.

## Motivation

The intelligence sweep (Spectr `competitor_features`, `resolved_by LIKE
'%delivery-channels%'`) classifies five rival capabilities against Hermiq as
genuine gaps: Hermes' "single daemon routes 22+ platforms" gateway, Microsoft
Copilot Studio's "multi-channel deployment" (Teams/web/M365 Copilot Chat),
Manus' Slack/WhatsApp/Telegram result delivery, and Khoj's WhatsApp delivery
and multi-platform clients. Today, a schedule owner who wants a run's output
in their inbox, or wired into an external system (an incident tracker, a
Slack-via-Zapier hook, an n8n workflow), has no way to get it there — the
`Schedule.deliver` enum is `talk | notification | none` and stops at the
instance boundary. Two additive channels close the reachable slice of this
gap without rebuilding what OpenConnector, n8n-nextcloud, or Nextcloud itself
already own.

## Affected Projects

- [ ] Project: `hermiq` — `Schedule.deliver` enum gains `email` and `webhook`;
  `DeliveryService` gains `deliverEmail()`/`deliverWebhook()`; a new
  webhook-secret lifecycle service and controller; `ScheduleFormModal.vue`
  gains the two new channel options and webhook-secret management UI.

## Scope

### In Scope

- **Email delivery** (`deliver=email`): post a run's output as an email to
  the schedule owner (default) or an explicit recipient
  (`deliverTarget` holds the address when the channel is `email`), sent via
  Nextcloud's own `OCP\Mail\IMailer` — never a bespoke SMTP/mail-library
  client. The body is redacted (`RedactionService`) before it is handed to
  the mailer, because email leaves the Nextcloud instance.
- **Outbound webhook delivery** (`deliver=webhook`): POST a JSON envelope of
  the run's (redacted) output to a configured URL (`deliverTarget`), signed
  with `X-Hermiq-Signature: sha256=<hex hmac>` computed over the exact request
  body using a per-schedule secret. Includes a bounded-retry-with-backoff
  shape mirroring `run-reliability`'s formula (not its config fields — see
  design.md) and a hard payload size cap.
- A per-schedule webhook signing secret: mint-once, rotate, revoke — the
  plaintext is shown exactly once and never re-displayed, mirroring
  `agent-webhook-trigger`'s `AgentWebhook` secret lifecycle UX.
- Extending `Schedule.deliver`'s enum and the `deliverTarget` field's meaning
  per channel; every delivery — talk, notification, email, or webhook — still
  records exactly one `delivery` run-trace step (`run-trace-observability`)
  and is covered by the existing per-run `AuditTrail` entry
  (`run-audit-log`), unchanged in shape.
- Generalising the run-trace `delivery` step's recorded name away from the
  hard-coded `"Talk delivery"` so it reflects the channel actually used.

### Out of Scope

- **Slack, Matrix, Telegram, WhatsApp, Teams, or any other named chat
  platform.** Per the workspace architecture law, Hermiq is a thin app and
  must not grow a 22-platform gateway the way Hermes does. The outbound
  webhook this change adds, together with **OpenConnector's** connector
  catalogue (400–7000 connectors, including these platforms), is the
  ADR-compliant answer: point Hermiq's webhook at an OpenConnector Source (or
  an n8n webhook trigger) and every one of those platforms is reachable
  without Hermiq ever holding a platform SDK or a platform credential.
- A generic low-code "wire this output anywhere" canvas — that is
  `n8n-nextcloud`'s scope, not Hermiq's.
- Signing the webhook secret through OpenRegister's credential broker
  (`CredentialBrokerService::request()`) exactly like `llm-keys-via-broker`
  does. Investigated and rejected for this shape — see design.md's
  Decisions: the broker is a constrained HTTP-proxy, host-locked to an
  admin-curated, runtime-immutable provider catalogue
  (`credential-providers.json`); it injects one secret into one header
  template for a pre-registered host, and cannot reach an arbitrary,
  user-configured webhook URL, nor perform a signing operation. This is a
  documented, deliberate departure, not an oversight.
- Multiple webhooks per schedule, or a webhook fan-out to several URLs — one
  webhook target per schedule, mirroring `agent-webhook-trigger`'s "one
  webhook per agent" precedent.
- Any change to the Talk/notification delivery path's behaviour — both are
  reused byte-for-byte.

## Approach

`DeliveryService::deliver()` gains two new channel branches
(`deliverEmail()`, `deliverWebhook()`) alongside the existing `talk`/
`notification`/`none` branches, all behind the same never-throws
`DeliveryResult` contract. A new `WebhookSecretService` mints, rotates, and
revokes a per-schedule signing secret, stored via Nextcloud's own
`OCP\Security\ICredentialsManager` (not a bespoke encryption scheme, not
plaintext in `oc_appconfig`/an OpenRegister object field) — the sanctioned NC
API for exactly this "an app must retrieve a secret later, but must never
hold it in cleartext config" shape. A small `HmacSigner` helper computes the
signature and drives the bounded-retry loop. `ScheduleService` is otherwise
unchanged: it already calls `deliver($channel, $output, $schedule)` and
appends one `delivery` trace step per run — both are extended, not replaced.

## New Dependencies

None. `OCP\Mail\IMailer`, `OCP\Http\Client\IClientService`, and
`OCP\Security\ICredentialsManager` are all Nextcloud core APIs already
available to every app.

## Impact

- `lib/Service/DeliveryService.php` — two new delivery methods.
- `lib/Service/WebhookSecretService.php` (new) — secret mint/rotate/revoke via
  `ICredentialsManager`.
- `lib/Service/HmacSigner.php` (new, or a private helper on `DeliveryService`
  — see design.md) — signature + bounded-retry loop.
- `lib/Controller/ScheduleWebhookSecretController.php` (new) — owner-guarded
  secret lifecycle endpoints, mirroring `AgentWebhookController`.
- `lib/Service/ScheduleService.php` — generalise the `delivery` trace step's
  recorded name.
- `lib/Settings/hermiq_register.json` — `Schedule.deliver` enum + new derived
  fields; `appinfo/info.xml` patch bump.
- `src/modals/ScheduleFormModal.vue` — two new `deliverOptions`, an email
  recipient field, a webhook URL field, and a secret mint/rotate/revoke
  control.
- `l10n/en.json`, `l10n/nl.json` — new user-facing strings.

## Cross-Project Dependencies

None for this change itself. The Out-of-Scope section documents that reaching
Slack/Matrix/Telegram/WhatsApp/Teams is a job for **OpenConnector** (consuming
Hermiq's new webhook), not a dependency Hermiq takes on here.

## Risks

### Risk 1: The webhook secret is not custodied by the credential broker, contrary to the literal ask
**Severity:** Medium — **Mitigation:** Documented at length in design.md's
Decisions with the concrete evidence read from OpenRegister's own source
(`ProviderCatalogue`'s host-lock, `credential-providers.json`'s own
`$fleetComment` naming this exact "self-hosted, arbitrary target" case as
unbrokerable today) and from Doriath's actual capabilities (ciphertext
custody with local decrypt, no sign operation — investigated and also does
not fit without disproportionate new infrastructure). `ICredentialsManager`
is chosen as the closest sanctioned NC API that still avoids the original
sin (`BrokerHttpClient`'s docblock: keys "sat in cleartext inside the
`hermiq.llm` JSON blob in `oc_appconfig`, readable by anything that could
read the database, and printed verbatim by `occ config:app:get`"). A
follow-up issue is filed (see Open Questions) for OpenRegister/Doriath to
grow a real per-install custom-provider or sign-custody operation; this
change does not block on it.

### Risk 2: An external webhook endpoint could be slow or hostile, stalling the dispatcher tick
**Severity:** Medium — **Mitigation:** A short per-request HTTP timeout, a
hard-capped attempt count (max 5) with exponential backoff, and a payload
size cap bound the worst-case delay to tens of seconds — the same order of
magnitude an agent's own LLM call can already take inline in the same tick.
Delivery never throws, so a fully exhausted webhook retry degrades to a
recorded warning (`lastDeliveryError`), never a failed run.

### Risk 3: A misconfigured recipient/URL silently fails delivery every run
**Severity:** Low — **Mitigation:** Unchanged from the existing Talk/
notification contract: every delivery outcome (including every failure
reason) is recorded on `lastDeliveryError` and in the run's `delivery` trace
step, visible in run history.

## Rollback Strategy

Both channels are additive `Schedule.deliver` enum values and new
`DeliveryService` methods; nothing existing is removed or altered in shape.
Reverting the code change leaves `talk`/`notification`/`none` fully
functional. Any schedule already set to `email`/`webhook` would need a manual
`deliver` reset (a single OpenRegister object field edit) after a rollback,
since the enum value itself would no longer validate against the reverted
schema on the next save.

## Open Questions

- Should a future change teach OpenRegister's credential broker (or Doriath)
  a genuine per-install, admin-approved "custom webhook target" or "sign"
  operation, so this and similar self-hosted-target secrets stop being an app
   -custodied exception? Filed as a follow-up; out of scope here.
- Should the webhook envelope eventually gain a timestamp + replay-window
  scheme (Stripe-style `t=<unix>,v1=<hex>`, as `openconnector`'s
  `WebhookSignatureService` already does for verifying inbound third-party
  signatures) rather than a bare `sha256=<hex>`? Deferred — the evidence and
  brief ask for a signature, not replay protection; a receiver that wants
  replay protection can layer its own nonce in the payload today.
