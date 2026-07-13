## ADDED Requirements

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

## MODIFIED Requirements

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
