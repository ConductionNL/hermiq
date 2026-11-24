# Tasks: observability-export-traces

Kind: code. Size M. Row `hermiq:ob-external-tracing`.

## Implementation tasks

### Task 1: Admin settings and the connection row
- **spec_ref**: `openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-an-administrator-configures-trace-export-with-a-brokered-credential-req-trex-001`
- **files**: `lib/Controller/Settings/TraceExportSettingsController.php`, `appinfo/routes.php`, `lib/Settings/connections.json`, `src/views/AdminRoot.vue`, `src/modals/TraceExportSettingsModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they save an endpoint and a credential reference THEN `trace_export` holds both and no secret
  - GIVEN a non-admin WHEN they call the route THEN the answer is 403
- [x] Implement
- [x] Test (PHPUnit on auth and storage; Playwright for the section)
  - 2026-11-24: PHPUnit in `tests/Unit/Service/TraceExport/TraceExportSettingsTest.php` (`testBothRoutesAreAdminOnly`: both routes carry `#[AuthorizedAdminSetting(AdminSettings::class)]`; `testAnAdministratorConnectsLangfuse`: the reference is stored and the secret is not; `testTheControllerSavesAndRefuses`; 5 tests green here). Playwright `tests/e2e/spec-coverage/trace-export.spec.ts` ("an administrator connects Langfuse") written, runs in nightly; not verified against a live instance here.

### Task 2: The exporter
- **spec_ref**: `openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-traces-carry-metadata-by-default-and-never-tool-arguments-or-results-req-trex-002`
- **files**: `lib/Service/TraceExport/TraceExporter.php`, `lib/Service/TraceExport/OtlpPayloadBuilder.php`
- **acceptance_criteria**:
  - GIVEN a run with a tool step WHEN spans are built THEN the root, model and tool spans exist with the attribute names of D3 and no argument or result
  - GIVEN redacted text on WHEN spans are built THEN input and output are filtered, redacted and capped
- [x] Implement
- [x] Test (PHPUnit on the payload, including a secret pattern)

### Task 3: The queue, the job and the broker call
- **spec_ref**: `openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-export-never-slows-or-changes-a-run-req-trex-003`
- **files**: `lib/Service/TraceExport/TraceExportQueue.php`, `lib/BackgroundJob/TraceExportJob.php`, `lib/Service/Connection/ConnectionReporter.php`
- **acceptance_criteria**:
  - GIVEN a 503 endpoint WHEN the job runs THEN it retries three times, drops the batch, logs once and reports the connection error
  - GIVEN an endpoint resolving to a private address WHEN the job runs THEN the egress guard refuses it
- [x] Implement
- [x] Test (PHPUnit with a fake broker and endpoint)

### Task 4: Every run writer and the chat turn enqueue
- **spec_ref**: `openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-export-never-slows-or-changes-a-run-req-trex-003`
- **files**: `lib/Service/ScheduleService.php`, `lib/Service/FlowAgentRunService.php`, `lib/Service/WebhookAgentRunService.php`, `lib/Service/Engine/Engine.php`
- **acceptance_criteria**:
  - GIVEN export on WHEN a scheduled, flow, webhook or chat run completes THEN one entry is enqueued and the run's result is unchanged
  - GIVEN the source tree WHEN the writer test scans for `run` and `agent-run` audit writes THEN each one calls `enqueue()`
- [x] Implement
- [x] Test (PHPUnit per writer, plus the scan test)
  - 2026-11-14: the schedule, flow and webhook writers each hand their audited context to `enqueueRun()` once (`ScheduleServiceTest`, `FlowAgentRunServiceTest`, `WebhookAgentRunServiceTest`). The webhook test found the writer passing the incoming context, which has no status, timings or summary and holds the payload unredacted; it now passes the audited context.

### Task 5: The copy notice and the live check
- **spec_ref**: `openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-the-export-is-a-copy-and-the-audit-trail-stays-the-record-req-trex-004`
- **files**: `src/modals/TraceExportSettingsModal.vue`, `docs/`
- **acceptance_criteria**:
  - GIVEN the settings section WHEN it is opened THEN the copy notice is shown, and switching export off leaves the run list unchanged
- [x] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/trace-export.spec.ts`; a live check against a local OTLP collector)
  - 2026-11-21: `tests/e2e/spec-coverage/trace-export.spec.ts` written from the scenarios (seeded objects and `page.route` stubs where a model, sidecar or Talk is needed); not run here, it needs a running Nextcloud.

## Verification
- [x] `openspec validate observability-export-traces --type change --strict` passes
  - 2026-11-21: valid under `@fission-ai/openspec@1.2.0`, the version hydra's apply loop pins.
- [ ] PHPUnit and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: a local Jaeger or Langfuse receives one run's trace with metadata only
