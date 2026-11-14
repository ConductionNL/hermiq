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
- [ ] Test (PHPUnit idempotency done: `SeedBiasEvalTemplateTest`, `EvalTemplateServiceTest`; the Playwright copy needs a live instance)

### Task 3: Eval schedules and the job
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003`
- **files**: `lib/Settings/hermiq_register.json`, `lib/BackgroundJob/EvalScheduleTask.php`, `appinfo/info.xml`, `lib/Notification/Notifier.php`
- **acceptance_criteria**:
  - GIVEN a due schedule WHEN the job runs THEN one run as the owner with gates applied, nextRun advanced first; a failed gate notifies the owner
- [x] Implement
- [x] Test (PHPUnit: `EvalScheduleServiceTest` for due selection, commit-before-run and the notification; `EvalScheduleTaskTest`; `NotifierTest`)

### Task 4: Run on a schedule in the UI
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003`
- **files**: `src/widgets/EvalRunPanelWidget.vue`, `src/modals/EvalScheduleFormModal.vue`, `src/api/evals.js`, `lib/Controller/EvalRunController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an owner WHEN they set a monthly schedule THEN it shows with its next run; a non-owner gets 404
- [x] Implement
- [ ] Test (PHPUnit on the guard done: `EvalScheduleServiceTest::testANonOwnerGets404`, `EvalScheduleControllerTest`; vitest `eval-schedules.spec.js`; Playwright needs a live instance)

### Task 5: Factsheet and compliance evidence
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004`
- **files**: `lib/Service/ComplianceService.php`, `lib/Repair/SeedComplianceControls.php`, the factsheet modal
- **acceptance_criteria**:
  - GIVEN runs of varying age WHEN computed THEN met, partial and gap as defined; the factsheet shows the latest run
- [x] Implement
- [ ] Test (PHPUnit done: `BiasCheckEvidenceTest`, `ComplianceServiceTest::testTheBiasCheckFeedsTheControlAndTheFactsheet`; Playwright on the factsheet needs a live instance)

## Verification
- [ ] `openspec validate reuse-scheduled-bias-evals --type change --strict` passes
- [ ] PHPUnit and Playwright run, exit codes read

## Build notes

- Task 2: `SeedBiasEvalTemplate` seeds "Bias check: public services (template)" and "Biastoets: publieke dienstverlening (sjabloon)", 20 pairs each, 4 per attribute, under a system identity, matched by name. EvalDataset 0.4.0 gains `template`, `templateKey`, `language`, `agentId` and `copiedFrom`. "Use for this agent" is `POST /api/evals/{datasetId}/use-for-agent` (`EvalTemplateService`): the caller must own the agent; the copy is owned by the caller and keeps the template key, so a bias check is recognised by it. The button sits on the dataset page's run panel, which the manifest renders for every dataset.
- Task 3: schema `EvalSchedule` (`evalschedule`, read by its owner, `agentId` and `datasetId` cascade). `EvalScheduleService::runDue()` stores the next run before the run, runs through `EvalRunService::run()` (so the kill switch and budget gates apply and every run is kept), skips a schedule whose owner no longer owns the dataset or the agent, and on a failed regression gate notifies the owner (`eval_schedule_regressed`: "Bias check for <agent> dropped from 95% to 80%", linking to the dataset). `EvalScheduleTask` runs hourly, never in parallel.
- Task 4: `GET`/`POST /api/evals/{datasetId}/schedules` (`EvalScheduleController`), 404 for anyone who does not own both the dataset and the agent; `EvalScheduleFormModal` (agent, how often, threshold, on/off) from "Run on a schedule" on the run panel, which lists the schedules with their next run.
- Task 5: `BiasCheckEvidence` gives the factsheet `biasCheck` (latest completed run of a bias dataset, or null: "No bias check run") and the evidence source `bias-eval-recency`. The design's met/partial/gap map onto the dashboard's existing statuses satisfied/partial/unevidenced. `SeedComplianceControls` adds ISO/IEC 42001 A.6.2.4 and NIST AI RMF MEASURE-2.11 on it. Register 0.55.0.
- Left: the Playwright tests of tasks 2, 4 and 5 and the verification runs need a live instance.
