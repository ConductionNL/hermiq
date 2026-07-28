# Talk Delivery Specification

**Status**: in-progress (delivery shipped to `main` v0.1.10 — Talk room → note-to-self → notification fallback, live-verified; `talk-chat-bridge` now adds the room binding that makes a delivered report repliable)
**Standards**: Nextcloud Talk (spreed) OCS API + bot API, Nextcloud Notifications
**Feature tier**: MVP

**OpenSpec changes:**
- `openspec/changes/talk-delivery-schema/` — `deliverTarget` + `lastDeliveryError` Schedule fields (kind: config) — **done**
- `openspec/changes/talk-delivery/` — the delivery service + dispatcher wiring (kind: code, depends_on talk-delivery-schema)
- `openspec/changes/talk-chat-bridge/` — delivery records `talkRoomToken` on the conversation it delivered for, so a reply in that room continues that session (kind: code, depends_on talk-chat-bridge-schema)

**Related features:** `talk-chat-bridge` (the inbound half), `talk-shared-sessions`, `talk-room-grouping`

## Purpose

Deliver the output of a scheduled (or manual) agent run to the user inside Nextcloud —
primarily as a **Nextcloud Talk** message, with a **Notification** fallback. This replaces
Hermes' 22-platform chat gateway with a single Nextcloud-native channel, so there is no
separate gateway process to run and delivery inherits Nextcloud identity and permissions.
## Requirements
### Requirement: Deliver run output to Nextcloud Talk [MVP]
When a schedule's `deliver=talk`, the system MUST post the agent's run output as a message to a configured Talk room the owner is a member of.

#### Scenario: Daily briefing arrives in Talk
- GIVEN a schedule with `deliver=talk` and a target Talk room token
- WHEN the agent run completes with output
- THEN the system MUST post the output as a Talk message to that room authored by the Hermiq bot
- AND if the output is empty or explicitly silent, MUST post nothing

### Requirement: Notification fallback [MVP]
When `deliver=notification` (or Talk is unavailable), the system MUST raise a Nextcloud notification to the owner linking to the run record.

#### Scenario: Talk not installed → notification
- GIVEN Talk (spreed) is not installed on the instance
- WHEN a run completes for a schedule set to `deliver=talk`
- THEN the system SHOULD fall back to a Nextcloud notification and record a delivery warning on the run

### Requirement: Delivery failures are recorded, not fatal [MVP]
A delivery error MUST NOT fail the run; it MUST be recorded on the run/schedule (`lastDeliveryError`).

#### Scenario: Talk post fails
- GIVEN a run produced output
- WHEN the Talk post returns an error
- THEN the run MUST still be marked complete and audited, with the delivery error stored separately

#### Scenario: Email send fails

- GIVEN a run produced output and `deliver=email`
- WHEN `IMailer::send()` throws
- THEN the run MUST still be marked complete and audited, with the delivery
  error stored on `lastDeliveryError`

#### Scenario: Webhook delivery exhausts its retry budget

- GIVEN a run produced output and `deliver=webhook`
- WHEN every retry attempt fails
- THEN the run MUST still be marked complete and audited, with the delivery
  error stored on `lastDeliveryError`

### Requirement: Deliver a failure alert to the schedule owner [MVP]

The system MUST notify a schedule's owner — regardless of the schedule's own
`deliver` output-channel setting (including `deliver='none'`) — when a run
becomes dead-lettered or when a schedule is auto-paused by the circuit breaker,
using the same Talk (Note-to-self) → Notification fallback chain already used
for normal run-output delivery. A failure to deliver this alert MUST NOT alter
the run/schedule state that was already recorded and MUST NOT fail the tick.

#### Scenario: Dead-letter triggers an owner alert even when deliver=none

- GIVEN a schedule with `deliver='none'` and `retryEnabled=true`
- WHEN its retry budget is exhausted and the occurrence is marked
  `dead_letter`
- THEN the owner MUST still receive a Talk message (or, when Talk is
  unavailable, a Nextcloud notification) describing the failure and linking to
  the schedule/run

#### Scenario: Circuit-breaker trip triggers a distinct owner alert

- GIVEN a schedule reaches its `circuitBreakerThreshold` and is auto-paused
- WHEN the auto-pause is recorded
- THEN the owner MUST receive an alert distinct from the dead-letter alert,
  stating that the schedule was automatically paused after repeated failures,
  linking to the schedule

#### Scenario: A failed alert never fails the run or reverts recorded state

- GIVEN both the Talk and Notification channels throw
- WHEN a dead-letter or circuit-breaker alert attempt is made
- THEN the run/schedule state already recorded (`dead_letter` or
  `paused_circuit_breaker`) MUST remain unchanged
- AND the delivery failure MUST only be logged, never re-thrown to the
  dispatcher

### Requirement: Deliver run output via email [MVP]

The system MUST send a schedule's run output as an email when
`deliver=email`, using Nextcloud's own `OCP\Mail\IMailer` — never a bespoke
SMTP or third-party mail-library client. The recipient is `deliverTarget`
when set, or the schedule owner's own Nextcloud account email address when
`deliverTarget` is empty. The output MUST be passed through
`RedactionService::redact()` before it is placed in the email body, because
email leaves the Nextcloud instance.

#### Scenario: A run's output arrives by email to the owner

- GIVEN a schedule with `deliver=email` and an empty `deliverTarget`
- WHEN the agent run completes with output
- THEN the system MUST send an email to the schedule owner's own Nextcloud
  account email address via `IMailer`
- AND the email body MUST NOT contain any raw secret- or PII-shaped value
  that `RedactionService` would mask

#### Scenario: A run's output arrives by email to an explicit recipient

- GIVEN a schedule with `deliver=email` and `deliverTarget` set to a
  specific email address
- WHEN the agent run completes with output
- THEN the system MUST send the email to that address instead of the
  owner's own address

#### Scenario: The owner has no email address configured and no explicit recipient is set

- GIVEN a schedule with `deliver=email`, an empty `deliverTarget`, and an
  owner whose Nextcloud account has no email address configured
- WHEN the agent run completes with output
- THEN the system MUST NOT attempt to send an email
- AND the system MUST record a delivery warning explaining no recipient
  could be resolved, without failing the run

### Requirement: Deliver run output via a signed outbound webhook [MVP]

The system MUST POST a JSON envelope containing the run's (redacted) output
to the URL configured in `deliverTarget` when `deliver=webhook`, signing the
exact request body with `X-Hermiq-Signature: sha256=<hex>` — an HMAC-SHA256
digest computed with the schedule's own webhook signing secret.

#### Scenario: A run's output is posted to the configured webhook URL

- GIVEN a schedule with `deliver=webhook`, a configured `deliverTarget` URL,
  and a minted webhook signing secret
- WHEN the agent run completes with output
- THEN the system MUST POST a JSON body containing the (redacted) output to
  that URL
- AND the request MUST carry an `X-Hermiq-Signature` header whose value is
  `sha256=` followed by the lowercase-hex HMAC-SHA256 of the exact request
  body using the schedule's signing secret

#### Scenario: No signing secret is configured

- GIVEN a schedule with `deliver=webhook` and a configured `deliverTarget`
  URL, but no webhook signing secret has ever been minted for it
- WHEN the agent run completes with output
- THEN the system MUST NOT attempt the POST
- AND the system MUST record a delivery warning explaining no signing secret
  is configured, without failing the run

#### Scenario: No URL is configured

- GIVEN a schedule with `deliver=webhook` and an empty `deliverTarget`
- WHEN the agent run completes with output
- THEN the system MUST NOT attempt the POST
- AND the system MUST record a delivery warning explaining no destination
  URL is configured, without failing the run

### Requirement: Webhook delivery retries with bounded exponential backoff [MVP]

The system MUST retry a failed webhook delivery attempt (a non-2xx response,
a timeout, or a connection error) up to `deliverWebhookMaxAttempts` (integer
1-5, default 3) times, waiting at least
`deliverWebhookBackoffBaseSeconds * 2^(attempt-1)` seconds
(`deliverWebhookBackoffBaseSeconds`: integer 1-30, default 2) between
attempts, entirely within the same dispatch tick — no new background job or
scheduled retry is introduced. Each attempt MUST use a bounded per-request
timeout so a single unresponsive endpoint cannot stall the tick indefinitely.

#### Scenario: A transient webhook failure is retried and eventually succeeds

- GIVEN a schedule with `deliver=webhook`, `deliverWebhookMaxAttempts=3`,
  `deliverWebhookBackoffBaseSeconds=2`
- WHEN the first POST attempt returns a 500 response, the second attempt
  also fails, and the third attempt succeeds
- THEN the system MUST wait at least 2 seconds before the second attempt and
  at least 4 seconds before the third
- AND the delivery MUST be recorded as successful once the third attempt
  succeeds, with no warning

#### Scenario: A webhook retry budget is exhausted

- GIVEN a schedule with `deliver=webhook` and `deliverWebhookMaxAttempts=3`
- WHEN all three POST attempts fail
- THEN the system MUST NOT attempt a fourth
- AND the system MUST record a delivery warning describing the failure,
  without failing the run

### Requirement: Webhook payload is size-capped before it is signed and sent [MVP]

The system MUST cap the outbound webhook JSON envelope at 65536 bytes,
truncating only the `output` field (never the envelope's identifying
metadata) with a trailing truncation marker when the full envelope would
exceed the cap. The signature MUST be computed over the final, capped body —
never over a pre-truncation body.

#### Scenario: An oversized run output is truncated before signing

- GIVEN a schedule with `deliver=webhook` and a run whose output, once
  redacted, would produce an envelope larger than 65536 bytes
- WHEN the webhook delivery is attempted
- THEN the system MUST truncate the `output` field so the final envelope is
  at most 65536 bytes, with a trailing marker indicating truncation
- AND the `X-Hermiq-Signature` header MUST be the HMAC over that truncated
  body, so a receiver can verify it without reconstructing a larger one

### Requirement: A per-schedule webhook signing secret can be minted, rotated, and revoked [MVP]

The system MUST let a schedule's owner mint a webhook signing secret for a
schedule that has none, rotate an existing secret (invalidating the previous
one immediately), and revoke a secret (removing it so subsequent webhook
deliveries fail closed with a recorded warning rather than silently signing
with a stale value). The plaintext secret MUST be returned only in the
mint/rotate response body and MUST NOT be persisted anywhere retrievable
through the OpenRegister object API — it is stored via Nextcloud's own
`OCP\Security\ICredentialsManager`, never in a `Schedule` object field.

#### Scenario: Minting a webhook secret for the first time

- GIVEN a schedule with `deliver=webhook` and no webhook signing secret
- WHEN the schedule's owner mints a new secret
- THEN the system MUST generate a secret, store it via
  `ICredentialsManager`, and return the plaintext exactly once in the
  response
- AND the schedule's `deliverWebhookSecretConfigured` MUST read `true`
  afterwards

#### Scenario: Rotating an existing webhook secret

- GIVEN a schedule with an active webhook signing secret
- WHEN the owner rotates it
- THEN the system MUST generate and store a new secret, return it once, and
  the next webhook delivery MUST sign with the new secret (the previous
  secret can no longer be used to reproduce a valid signature)

#### Scenario: Revoking a webhook signing secret

- GIVEN a schedule with an active webhook signing secret and `deliver` still
  set to `webhook`
- WHEN the owner revokes the secret
- THEN the system MUST remove it from `ICredentialsManager`
- AND the schedule's `deliverWebhookSecretConfigured` MUST read `false`
- AND the next due run's webhook delivery attempt MUST fail closed with a
  recorded warning (per "No signing secret is configured" above) rather than
  sending an unsigned request

#### Scenario: A non-owner cannot manage another owner's schedule webhook secret

- GIVEN a schedule owned by user A and a webhook-secret mint/rotate/revoke/
  status request from user B
- WHEN user B calls any webhook-secret endpoint for user A's schedule
- THEN the system MUST respond as if the schedule does not exist (404), not
  with a 403, mirroring `RunNowController::loadOwnedSchedule()`

### Requirement: Output crossing the instance boundary is redacted before delivery [MVP]

The system MUST apply `RedactionService::redact()` to the run output before
it is placed into an email body or a webhook payload, because both channels
leave the Nextcloud instance. Talk and Notification delivery are unaffected
by this requirement — they remain unredacted because they never leave the
instance.

#### Scenario: A secret-shaped token in the output is masked before it leaves the instance

- GIVEN a run whose output contains an API-key-shaped token
- WHEN the output is delivered via `deliver=email` or `deliver=webhook`
- THEN the token MUST appear masked in the email body / webhook payload
- AND the same run delivered via `deliver=talk` MUST show the token
  unmasked, unchanged from existing behavior

## User Stories

- As a user, I want my agent's results to show up in a Talk chat so that I read them where I already work.
- As a user without Talk, I want a notification instead so that I still get results.
- As an admin, I want delivery failures logged so that I can see when a channel is misconfigured.

## Acceptance Criteria

- [ ] A Talk delivery adapter posts run output to a room via the spreed OCS chat API as a bot.
- [ ] A Notification fallback delivers to the owner and links to the run record.
- [ ] Empty/silent output produces no message.
- [ ] Delivery errors are stored on the run and never fail the run itself.
- [ ] The target channel is configurable per schedule.

## Notes

- **Dependency:** Nextcloud Talk (spreed) is NOT installed on the current dev instance
  (only `opentalk` video) — this is a hard operator dependency; see ADR-005 for the
  decision and fallback. NC Mail (IMailer) outbound is planned under `nc-native-tools`.
- Related: **ADR-005** (delivery via Nextcloud Talk), `agent-schedule`, `run-audit-log`.
