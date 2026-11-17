# Tasks: run-audit-log

## 1. Redaction (full redact.py port)

- [x] 1.1 Port Hermes' `agent/redact.py` in full to `lib/Service/RedactionService.php` (`redact(string, ...): string`, SPDX docblock). Port the complete pattern set: ~40 vendor API-key prefixes (`sk-`, `ghp_`, `AKIA`, `xai-`, `AIza…`, Stripe, Slack, JWT `eyJ…`), Authorization/`x-api-key` headers, DB DSN passwords, bare-URL tokens, private keys, Telegram tokens, E.164 phone numbers (PII), and ENV/JSON/YAML `key=value` assignments. Mask preserving head/tail for debuggability (mirror `mask_secret`); freeze the enable toggle at construction so a run can't disable it. Source to port faithfully: the cloned `hermes-agent/agent/redact.py` (session scratchpad) — re-clone `NousResearch/hermes-agent` if the scratch copy is gone.
- [x] 1.2 Add PHPUnit coverage for the full pattern set: each secret family masked (≥1 vendor prefix, Authorization header, DSN password, private key, JWT), E.164 phone masked, ENV/JSON assignment masked, benign prose untouched, empty/very-long input safe, head/tail-preserving mask shape asserted.

## 2. Per-run audit write

- [x] 2.1 Inject OpenRegister `AuditTrailMapper` into `ScheduleService`.
- [x] 2.2 In `ScheduleService::dispatch`, on finalise (both success and error branches), build a redacted context (`agentId`, `status`, start/end/duration, redacted output summary) and call `AuditTrailMapper::createAuditTrailEntry($schedule, 'run', $context)`.
- [x] 2.3 Wrap the audit write so a redaction/audit failure is logged but never aborts the tick (mirror the non-fatal delivery seam).
- [x] 2.4 Add PHPUnit coverage: success run writes an `action='run'` entry with owner/status; failed run still writes `status=error`; audit-write failure does not fail the tick; the output summary is redacted before the write.

## 3. Run-history read surface

- [x] 3.1 Add `lib/Service/RunHistoryService.php` calling `ObjectService::getLogs($scheduleUuid, filters)` filtered to `action='run'`, newest first, mapping each `AuditTrail` to a run record (status, timing, agentId, link).
- [x] 3.2 Add `lib/Controller/RunHistoryController.php` with one `#[NoAdminRequired]` `#[NoCSRFRequired]` GET method that loads the schedule via `ObjectService` with RBAC ON, asserts the requester is the owner (refuse otherwise), then returns the run records.
- [x] 3.3 Register the owner-scoped GET route in `appinfo/routes.php`.
- [x] 3.4 Add PHPUnit coverage: owner gets newest-first run records; non-owner is refused (no cross-tenant leak / IDOR).

## 4. Quality + live verify

- [x] 4.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the Hydra gates; fix any pre-existing issues touched.
- [x] 4.2 Bump `appinfo/info.xml` `<version>` for the immutable-JS cache-bust and deploy to the dev instance.
- [x] 4.3 Verified live on NC 34 + OR 0.2.17: a dispatcher tick wrote exactly one `action='run'` AuditTrail entry on the Schedule object (status/agentId/timing in `changed`, actor from the impersonated owner, joined to the hash chain, alongside OR's auto create/update entries). `GET /api/schedules/{id}/runs` returned the run as the owner; a non-owner user got HTTP 404 "Schedule not found" with zero run records leaked (IDOR guard). Read goes via `AuditTrailMapper::findAll(object_uuid, action='run')` — workaround for the upstream OR bug where `createAuditTrailEntry` leaves `object` NULL (see design.md Risks).

## Acceptance criteria

- Each run finalise (success and error) writes exactly one explicit `action='run'` OpenRegister AuditTrail entry for the Schedule object, scoped to the impersonated owner + organisation.
- The output summary is redacted before the audit write; no raw secret/PII appears in the persisted entry.
- The run-history read returns an owner's runs newest-first with status/timing and refuses a non-owner.
- No new OpenRegister schema and no logging store are introduced; all writes go through OpenRegister (single write-path).

## Quality reminders

- Redaction MUST run before `createAuditTrailEntry`, never after (append-only chain — ADR-004).
- Single write-path: no state write bypasses `ObjectService`/OpenRegister's audit layer.
- Controller must guard ownership before reading logs (ADR-005 IDOR / gate-7); declare auth attributes on every routed method.
- Use safe placeholders in tests/docs (nil UUID `00000000-0000-0000-0000-000000000000`, `<angle-bracket>` tokens) — gitleaks scans fixtures.
- Verify LIVE, not only against mocks — OR round-trip artifacts and audit chaining are not exercised by unit mocks.
