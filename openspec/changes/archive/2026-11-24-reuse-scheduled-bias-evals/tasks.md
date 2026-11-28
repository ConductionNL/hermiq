# Tasks: reuse-scheduled-bias-evals

Kind: code. Size M. Row `hermiq:td-bias-test`.

## Implementation tasks

### Task 1: Pair cases in the scoring and the run
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-test-case-can-compare-two-requests-that-differ-in-one-attribute-req-biaseval-001`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/EvalScoringService.php`, `lib/Service/EvalRunService.php`
- **acceptance_criteria**:
  - GIVEN a pair WHEN run THEN two sessions, one judge call, both answers and the rationale stored
- [x] Implement
- [x] Test (PHPUnit with a stubbed provider for pass, fail and a malformed judge answer)

### Task 2: The bias template and "Use for this agent"
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002`
- **files**: `lib/Repair/SeedBiasEvalTemplate.php`, `src/views/EvalDatasetDetail.vue` or its manifest widget, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeded twice THEN one template per language; copying creates an editable dataset
- [x] Implement
  - `lib/Settings/eval-templates/bias-public-services.json` holds the English and Dutch template, twenty `counterfactualPair` cases each, four per attribute. `lib/Repair/SeedBiasEvalTemplate.php` seeds them by name under the system identity (both repair lists in `appinfo/info.xml`). EvalDataset gains `template`, `templateKey`, `language` and `agentId` (register v0.80.0). `EvalTemplateGuardListener` refuses an edit of a stored template on every write path. `POST /api/evals/{datasetId}/use` (`EvalTemplateController` over `Service/Eval/EvalTemplateCopier`) copies a template into a dataset the caller owns, linked to an agent the caller owns (404 otherwise, 400 for a dataset that is not a template). On the dataset page the eval run panel shows a template as read-only and offers "Use for this agent" in place of Run, then opens the copy; en/nl strings.
- [x] Test (PHPUnit idempotency; Playwright for the copy)
  - PHPUnit done: `SeedBiasEvalTemplateTest` (one template per language, seeding twice stores nothing, twenty pairs over the five attributes), `EvalTemplateCopierTest`, `EvalTemplateControllerTest`, `EvalTemplateGuardListenerTest`; vitest `eval-template-use.spec.js`. Playwright written in `tests/e2e/spec-coverage/bias-evals.spec.ts`, not run here: it needs a live instance.
  - 2026-11-24: written, runs in nightly (UNBLOCK rule 3); not verified against a live instance here.

### Task 3: Eval schedules and the job
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003`
- **files**: `lib/Settings/hermiq_register.json`, `lib/BackgroundJob/EvalScheduleTask.php`, `appinfo/info.xml`, `lib/Notification/Notifier.php`
- **acceptance_criteria**:
  - GIVEN a due schedule WHEN the job runs THEN one run as the owner with gates applied, nextRun advanced first; a failed gate notifies the owner
- [x] Implement
  - `EvalSchedule` (register v0.79.0, app version raised so the import runs), `lib/BackgroundJob/EvalScheduleTask.php` (hourly, never parallel, in `appinfo/info.xml`) over `lib/Service/EvalScheduleRunner.php`, with `EvalSchedules` (storage and the owner rule), `EvalScheduleClock` (due selection, the next run) and `EvalRegressionNotifier`; `Notifier` renders `eval_regression`, linking to the dataset page with the run. The run goes through `EvalRunService::run()`, so the kill switch and budget gates apply; a schedule runs only while its owner owns both the dataset and the agent.
- [x] Test (PHPUnit for due selection, commit-before-run and the notification)
  - `EvalScheduleRunnerTest`, `EvalSchedulesTest`, `EvalScheduleClockTest`, `EvalRegressionNotifierTest`, `EvalScheduleTaskTest`, `NotifierEvalRegressionTest`, `EvalScheduleSchemaTest`; `AgentSwitchSchemaTest` admits `EvalSchedule.agentId` to the cascading references.

### Task 4: Run on a schedule in the UI
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003`
- **files**: `src/widgets/EvalRunPanelWidget.vue`, `src/modals/EvalScheduleFormModal.vue`, `src/api/evals.js`, `lib/Controller/EvalRunController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an owner WHEN they set a monthly schedule THEN it shows with its next run; a non-owner gets 404
- [x] Implement
  - `GET|POST /api/evals/{datasetId}/schedules` and `PUT /api/evals/{datasetId}/schedules/{id}` (`EvalScheduleController` over `Service/Eval/EvalScheduleEditor`): the caller's schedules of a dataset they own, against an agent they own; anything else is a 404, a cron expression or threshold that is not valid a 400. A save stores the next run, so the page shows it and the first run waits for the schedule. `Service/Eval/EvalOwnership` holds the owner rule the copy and the schedules share. On the dataset page the eval run panel lists the schedules (agent, frequency, next run or off) with "Add a schedule" and "Change", through `src/modals/EvalScheduleFormModal.vue` (agent, monthly, weekly or daily, the drop threshold, on or off); en/nl strings. The task's file list named `EvalRunController`; the routes live on a controller of their own so the run endpoint keeps its collaborators.
- [x] Test (PHPUnit on the guard; Playwright)
  - PHPUnit done: `EvalScheduleEditorTest` (monthly schedule with its next run, non-owner and missing dataset or agent, invalid cron and threshold, a change keeps its last run, the list holds the caller's own), `EvalScheduleControllerTest` (201, 200, 404, 400, 401); vitest `eval-schedules.spec.js`. Playwright written in `tests/e2e/spec-coverage/bias-evals.spec.ts`, not run here: it needs a live instance.
  - 2026-11-24: written, runs in nightly (UNBLOCK rule 3); not verified against a live instance here.

### Task 5: Factsheet and compliance evidence
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004`
- **files**: `lib/Service/ComplianceService.php`, `lib/Repair/SeedComplianceControls.php`, the factsheet modal
- **acceptance_criteria**:
  - GIVEN runs of varying age WHEN computed THEN met, partial and gap as defined; the factsheet shows the latest run
- [x] Implement
  - `lib/Service/Eval/BiasCheckEvidence.php`: a bias check is a completed EvalRun of a dataset with the bias template's key. `latestFor()` gives the factsheet's `biasCheck` (run, dataset, pass rate, date) or null; `evidence()` is the `bias-eval-recency` source in the dashboard's own vocabulary: `satisfied` (met) when every agent with an enabled bias schedule had a check in the last 45 days, `partial` when some did, `unevidenced` (gap) when none did or no agent has a bias schedule. `ComplianceService` dispatches the source and adds `biasCheck` to the factsheet; `SeedComplianceControls` adds ISO/IEC 42001 A.6.2.4 and NIST AI RMF MEASURE 2.11 on it (the control schema's enum gains the value). `AgentFactsheetDialog` shows "Bias check: 95% on 2026-09-01" linking to the dataset with its runs, or "No bias check run"; en/nl strings.
- [x] Test (PHPUnit; Playwright on the factsheet)
  - PHPUnit done: `BiasCheckEvidenceTest` (latest completed run, satisfied, partial, unevidenced, unreadable register), `ComplianceServiceTest` (dispatch and factsheet), `SeedComplianceControlsTest` (13 controls); vitest `factsheet-bias-check.spec.js`. Playwright written in `tests/e2e/spec-coverage/bias-evals.spec.ts`, not run here: it needs a live instance.
  - 2026-11-24: written, runs in nightly (UNBLOCK rule 3); not verified against a live instance here.

## Verification
- [x] `openspec validate reuse-scheduled-bias-evals --type change --strict` passes
  - 2026-11-21: valid under `@fission-ai/openspec@1.2.0`, the version hydra's apply loop pins.
- [x] PHPUnit and Playwright run, exit codes read
  - 2026-11-24, development d777f3e8: the change's PHPUnit tests (`--filter 'EvalSchedule|BiasEval|BiasCheck'` 45 tests, `ComplianceServiceTest` and `SeedComplianceControlsTest` in the 36-test run) pass, exit 1 only for the missing coverage driver; the full suite's 6769 tests fail only on the 3 inherited `ToolAccessRequestPropertyRulesTest` errors. vitest `eval-schedules.spec.js` and `factsheet-bias-check.spec.js` 12/12, exit 0. Playwright `tests/e2e/spec-coverage/bias-evals.spec.ts`: written, runs in nightly (UNBLOCK rule 3); not run here, no instance.
