# Tasks: delivery-channels

## Implementation Tasks

### Task 1: Schema — extend `Schedule.deliver` + new fields, bump app version
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the `Schedule` schema WHEN `deliver` is set to `email` or `webhook` THEN it validates against the updated enum (`talk`\|`notification`\|`none`\|`email`\|`webhook`)
  - GIVEN the new `deliverWebhookMaxAttempts` (1-5, default 3), `deliverWebhookBackoffBaseSeconds` (1-30, default 2), `deliverWebhookSecretConfigured` (boolean, default false), and `deliverWebhookSecretRotatedAt` (date-time\|null) fields WHEN the register is imported THEN validation succeeds and defaults apply
  - GIVEN `appinfo/info.xml` WHEN this change ships THEN its version is bumped by one patch (`0.1.52` → `0.1.53`) so the register re-import gate fires
- [ ] Implement
- [ ] Test

### Task 2: `WebhookSecretService` — `ICredentialsManager`-backed mint/rotate/revoke
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp`
- **files**: `lib/Service/WebhookSecretService.php`, `tests/Unit/Service/WebhookSecretServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a schedule with no webhook secret WHEN `mint()` is called THEN a random secret is generated, stored via `ICredentialsManager::store($owner, 'hermiq/webhook-secret/'.$scheduleUuid, $secret)`, and returned once; `deliverWebhookSecretConfigured` flips to `true` on the schedule
  - GIVEN an existing secret WHEN `rotate()` is called THEN the stored value is overwritten and the previous secret no longer verifies any signature
  - GIVEN an existing secret WHEN `revoke()` is called THEN `ICredentialsManager::delete()` removes it and `deliverWebhookSecretConfigured` flips to `false`
- [ ] Implement
- [ ] Test

### Task 3: `ScheduleWebhookSecretController` — owner-guarded CRUD
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp`
- **files**: `lib/Controller/ScheduleWebhookSecretController.php`, `appinfo/routes.php`, `tests/Unit/Controller/ScheduleWebhookSecretControllerTest.php`
- **acceptance_criteria**:
  - GIVEN the schedule owner WHEN they POST `/api/schedules/{id}/webhook-secret` (mint/rotate), POST `.../revoke`, or GET `.../webhook-secret` THEN the request succeeds per `WebhookSecretService`
  - GIVEN a non-owner WHEN they call any of these routes for another owner's schedule THEN the response is 404, never 403
- [ ] Implement
- [ ] Test

### Task 4: `DeliveryService::deliverEmail()` — redact, resolve recipient, send via `IMailer`
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-email-mvp`
- **files**: `lib/Service/DeliveryService.php`, `tests/Unit/Service/DeliveryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `deliver=email` with an empty `deliverTarget` WHEN a run completes THEN the redacted output is emailed to the owner's own Nextcloud account email via `IMailer`
  - GIVEN `deliver=email` with `deliverTarget` set WHEN a run completes THEN the email goes to that address instead
  - GIVEN no resolvable recipient (empty target, owner has no email) WHEN a run completes THEN no send is attempted and a `DeliveryResult` warning is recorded, never a thrown exception
- [ ] Implement
- [ ] Test

### Task 5: `DeliveryService::deliverWebhook()` — HMAC sign, bounded retry, size cap
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp`
- **files**: `lib/Service/DeliveryService.php`, `tests/Unit/Service/DeliveryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a configured `deliverTarget` URL and a minted secret WHEN a run completes THEN the system POSTs a redacted, size-capped (65536 bytes) JSON envelope via `IClientService` with header `X-Hermiq-Signature: sha256=<hex hmac over the exact sent body>`
  - GIVEN a failing endpoint WHEN delivery is attempted THEN it retries up to `deliverWebhookMaxAttempts` times with `deliverWebhookBackoffBaseSeconds * 2^(attempt-1)` backoff before recording a warning, never throwing
  - GIVEN no URL or no configured secret WHEN a run completes THEN no POST is attempted and a `DeliveryResult` warning is recorded
- [ ] Implement
- [ ] Test

### Task 6: `ScheduleService` — generalise the `delivery` trace-step name
- **spec_ref**: `openspec/changes/delivery-channels/specs/run-audit-log/spec.md#requirement-the-delivery-trace-step-reflects-the-channel-actually-used-mvp`
- **files**: `lib/Service/ScheduleService.php`, `tests/Unit/Service/ScheduleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a run delivered via `talk`/`notification`/`email`/`webhook`/`none` WHEN `appendDeliveryStep()` runs THEN the recorded step's `name` matches the channel actually used (`DeliveryResult::getChannel()`), while `type` stays `delivery` in every case
- [ ] Implement
- [ ] Test

### Task 7: Frontend — `ScheduleFormModal.vue` new channels + `ScheduleWebhookSecretDialog.vue`
- **spec_ref**: `openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp`
- **files**: `src/modals/ScheduleFormModal.vue`, `src/dialogs/ScheduleWebhookSecretDialog.vue`, `src/api/schedules.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the schedule form WHEN the owner selects "Email" or "Webhook" in the deliver-to picker THEN the matching recipient/URL field appears, mirroring the existing Talk-room-token conditional field
  - GIVEN `deliver=webhook` WHEN the owner opens the webhook-secret dialog THEN they can mint/rotate/revoke a secret, with the plaintext shown exactly once and never re-displayed
  - GIVEN new user-facing strings WHEN the form renders THEN every label/placeholder resolves via `t('hermiq', …)` with English-keyed entries present in both `l10n/en.json` and `l10n/nl.json`
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
