# Tasks: reuse-scheduled-bias-evals

Kind: code. Size M. Row `hermiq:td-bias-test`.

## Implementation tasks

### Task 1: Pair cases in the scoring and the run
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-test-case-can-compare-two-requests-that-differ-in-one-attribute-req-biaseval-001`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Service/EvalScoringService.php`, `lib/Service/EvalRunService.php`
- **acceptance_criteria**:
  - GIVEN a pair WHEN run THEN two sessions, one judge call, both answers and the rationale stored
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed provider for pass, fail and a malformed judge answer)

### Task 2: The bias template and "Use for this agent"
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002`
- **files**: `lib/Repair/SeedBiasEvalTemplate.php`, `src/views/EvalDatasetDetail.vue` or its manifest widget, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN seeded twice THEN one template per language; copying creates an editable dataset
- [ ] Implement
- [ ] Test (PHPUnit idempotency; Playwright for the copy)

### Task 3: Eval schedules and the job
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003`
- **files**: `lib/Settings/hermiq_register.json`, `lib/BackgroundJob/EvalScheduleTask.php`, `appinfo/info.xml`, `lib/Notification/Notifier.php`
- **acceptance_criteria**:
  - GIVEN a due schedule WHEN the job runs THEN one run as the owner with gates applied, nextRun advanced first; a failed gate notifies the owner
- [ ] Implement
- [ ] Test (PHPUnit for due selection, commit-before-run and the notification)

### Task 4: Run on a schedule in the UI
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003`
- **files**: `src/widgets/EvalRunPanelWidget.vue`, `src/modals/EvalScheduleFormModal.vue`, `src/api/evals.js`, `lib/Controller/EvalRunController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an owner WHEN they set a monthly schedule THEN it shows with its next run; a non-owner gets 404
- [ ] Implement
- [ ] Test (PHPUnit on the guard; Playwright)

### Task 5: Factsheet and compliance evidence
- **spec_ref**: `openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-the-latest-bias-check-is-visible-as-evidence-req-biaseval-004`
- **files**: `lib/Service/ComplianceService.php`, `lib/Repair/SeedComplianceControls.php`, the factsheet modal
- **acceptance_criteria**:
  - GIVEN runs of varying age WHEN computed THEN met, partial and gap as defined; the factsheet shows the latest run
- [ ] Implement
- [ ] Test (PHPUnit; Playwright on the factsheet)

## Verification
- [ ] `openspec validate reuse-scheduled-bias-evals --type change --strict` passes
- [ ] PHPUnit and Playwright run, exit codes read
