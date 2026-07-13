# Test Plan: delivery-channels

## Test Cases

### TC-1: A run's output arrives by email to the owner
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp`
- **type**: functional
- **persona**: Jan-Willem van der Berg (small business owner scheduling a daily briefing)
- **preconditions**: A schedule exists with `deliver=email`, empty `deliverTarget`, owned by a user with an email address configured
- **steps**: Trigger the schedule's "Run now" action and wait for completion
- **expected result**: An email arrives at the owner's own Nextcloud account address containing the (redacted) run output
- **test command**: `/test-functional`

### TC-2: A run's output arrives by email to an explicit recipient
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp`
- **type**: functional
- **preconditions**: A schedule exists with `deliver=email` and `deliverTarget` set to a specific address
- **steps**: Run the schedule
- **expected result**: The email is sent to the configured address, not the owner's own address
- **test command**: `/test-functional`

### TC-3: No resolvable email recipient degrades gracefully
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp`
- **type**: functional
- **preconditions**: A schedule with `deliver=email`, empty `deliverTarget`, owner has no email address configured
- **steps**: Run the schedule
- **expected result**: No email is sent; the run still completes and `lastDeliveryError` records the reason; the run is not marked failed
- **test command**: `/test-functional`

### TC-4: A run's output is posted to the configured webhook URL, correctly signed
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp`
- **type**: api
- **preconditions**: A schedule with `deliver=webhook`, `deliverTarget` pointing at a test HTTP sink, and a minted webhook signing secret
- **steps**: Run the schedule; capture the POST the sink receives
- **expected result**: The sink receives a JSON body containing the redacted output and an `X-Hermiq-Signature: sha256=<hex>` header whose value equals `hash_hmac('sha256', $rawBody, $secret)` computed independently over the exact received bytes
- **test command**: `/test-api`

### TC-5: Missing signing secret fails closed
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp`
- **type**: api
- **preconditions**: A schedule with `deliver=webhook`, a configured `deliverTarget`, no webhook secret ever minted
- **steps**: Run the schedule
- **expected result**: No HTTP request reaches the target URL; `lastDeliveryError` records "no signing secret configured"; the run completes normally
- **test command**: `/test-api`

### TC-6: Missing webhook URL fails closed
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp`
- **type**: api
- **preconditions**: A schedule with `deliver=webhook` and an empty `deliverTarget`
- **steps**: Run the schedule
- **expected result**: No HTTP request is attempted; `lastDeliveryError` records "no destination URL configured"
- **test command**: `/test-api`

### TC-7: A transient webhook failure retries with growing backoff and eventually succeeds
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp`
- **type**: api
- **preconditions**: A schedule with `deliver=webhook`, `deliverWebhookMaxAttempts=3`, `deliverWebhookBackoffBaseSeconds=2`, and a test sink configured to fail twice then succeed
- **steps**: Run the schedule; measure inter-attempt timing at the sink
- **expected result**: The sink observes 3 attempts, with at least 2s then at least 4s between them; the run's delivery is recorded as successful with no warning
- **test command**: `/test-api`

### TC-8: A webhook retry budget exhaustion is recorded, not fatal
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-webhook-delivery-retries-with-bounded-exponential-backoff-mvp`
- **type**: api
- **preconditions**: A schedule with `deliver=webhook`, `deliverWebhookMaxAttempts=3`, and a sink that always fails
- **steps**: Run the schedule
- **expected result**: Exactly 3 attempts are made, no fourth; `lastDeliveryError` records the failure; the run still completes and is audited
- **test command**: `/test-api`

### TC-9: An oversized run output is truncated before signing
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-webhook-payload-is-size-capped-before-it-is-signed-and-sent-mvp`
- **type**: api
- **preconditions**: A schedule with `deliver=webhook` and an agent configured to produce output larger than 65536 bytes once redacted
- **steps**: Run the schedule; inspect the received body and signature
- **expected result**: The received envelope is at most 65536 bytes, the `output` field ends with a truncation marker, and the signature verifies against exactly the received (truncated) bytes
- **test command**: `/test-api`

### TC-10: Minting, rotating, and revoking a webhook signing secret
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp`
- **type**: functional
- **persona**: Priya Ganpat (ZZP developer/integrator wiring Hermiq into an external system)
- **preconditions**: A schedule with `deliver=webhook` and no secret configured
- **steps**: Open the webhook-secret dialog; mint a secret (note the shown value); rotate it; attempt to sign with the old value; revoke it
- **expected result**: The mint response shows the plaintext once; after rotation the old value no longer produces a valid signature; after revocation, `deliverWebhookSecretConfigured` reads `false` and the next run fails closed per TC-5
- **test command**: `/test-functional`

### TC-11: A non-owner cannot manage another owner's schedule webhook secret
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp`
- **type**: security
- **preconditions**: A schedule owned by user A with a webhook secret configured; an authenticated user B
- **steps**: As user B, call mint/rotate/revoke/status for user A's schedule id
- **expected result**: Every call responds 404 (never 403), never revealing whether the schedule exists
- **test command**: `/test-security`

### TC-12: Output is redacted before crossing the instance boundary, unredacted for Talk
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-output-crossing-the-instance-boundary-is-redacted-before-delivery-mvp`
- **type**: security
- **preconditions**: An agent configured to emit an API-key-shaped token in its output; three schedules bound to it with `deliver=talk`, `deliver=email`, and `deliver=webhook` respectively
- **steps**: Run all three schedules
- **expected result**: The Talk message shows the token unmasked (unchanged behavior); the email body and webhook payload both show it masked
- **test command**: `/test-security`

### TC-13: The delivery trace step name reflects the channel used
- **spec_ref**: `openspec/changes/delivery-channels/specs/run-audit-log/spec.md#requirement-the-delivery-trace-step-reflects-the-channel-actually-used-mvp`
- **type**: api
- **preconditions**: Four schedules, one per channel (`talk`, `notification`, `email`, `webhook`), each with a completed run
- **steps**: Retrieve each run's trace via the run-trace read endpoint
- **expected result**: Each trace's `delivery` step has `type=delivery` and a `name` matching its channel (`"Talk delivery"`, `"Notification delivery"`, `"Email delivery"`, `"Webhook delivery"`)
- **test command**: `/test-api`

### TC-14: Delivery failures never fail the run (regression)
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-delivery-failures-are-recorded-not-fatal-mvp`
- **type**: regression
- **preconditions**: Schedules covering all five `deliver` values, each configured so its delivery attempt fails
- **steps**: Run each schedule
- **expected result**: Every run still completes with `lastStatus=ok` and an audit entry; `lastDeliveryError` is populated per schedule; none are marked `error` due to delivery alone
- **test command**: `/test-regression`

## Coverage Summary

- Requirement: Deliver run output via email — covered (TC-1, TC-2, TC-3)
- Requirement: Deliver run output via a signed outbound webhook — covered (TC-4, TC-5, TC-6)
- Requirement: Webhook delivery retries with bounded exponential backoff — covered (TC-7, TC-8)
- Requirement: Webhook payload is size-capped before it is signed and sent — covered (TC-9)
- Requirement: A per-schedule webhook signing secret can be minted, rotated, and revoked — covered (TC-10, TC-11)
- Requirement: Output crossing the instance boundary is redacted before delivery — covered (TC-12)
- Requirement: The delivery trace step reflects the channel actually used — covered (TC-13)
- Requirement: Delivery failures are recorded, not fatal (email/webhook scenarios) — covered (TC-14)

## Out of Scope

- Load/performance testing of the webhook retry loop under many concurrent due schedules — deferred to `/test-performance` as a separate pass, not blocking this change.
- Accessibility audit of the new `ScheduleWebhookSecretDialog.vue` — covered by the existing `/test-accessibility` sweep cadence, not a dedicated TC here since it reuses `NcDialog`/`NcButton` primitives already audited elsewhere in the app.
