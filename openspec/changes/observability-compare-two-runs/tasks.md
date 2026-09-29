# Tasks: observability-compare-two-runs

Kind: code. Size M. Row `hermiq:dm-run-compare`.

## Implementation tasks

### Task 1: The comparator, and the replay diff on top of it
- **spec_ref**: `openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002`
- **files**: `lib/Service/RunComparator.php`, `lib/Service/ScheduleService.php`
- **acceptance_criteria**:
  - GIVEN sequences with one inserted step WHEN compared THEN exactly one step is `only-right`
  - GIVEN the existing replay test cases WHEN `diffTrace()` runs THEN its result is unchanged
- [x] Implement
- [x] Test (PHPUnit on the comparator; the existing replay tests stay green)

### Task 2: The compare route on the run list's boundary
- **spec_ref**: `openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001`
- **files**: `appinfo/routes.php`, `lib/Controller/AnalyticsController.php`, `lib/Service/AnalyticsService.php`
- **acceptance_criteria**:
  - GIVEN two visible runs WHEN compared THEN both records, both step lists and the comparison come back
  - GIVEN one run of an invisible agent WHEN compared THEN that side is 404
- [x] Implement
- [x] Test (PHPUnit; Newman with a visible and an invisible run)

### Task 3: Selection and the comparison view
- **spec_ref**: `openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001`
- **files**: `src/views/Runs.vue`, `src/views/RunCompare.vue`, `src/manifest.json`, `src/widgets/AgentRunHistoryWidget.vue`, `src/api/analytics.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Runs page WHEN two rows are ticked THEN "Compare" opens the view with the summary line
  - GIVEN a third tick WHEN attempted THEN it is refused with "Choose two runs to compare."
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/run-compare.spec.ts`)

### Task 4: Flow run comparison
- **spec_ref**: `openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003`
- **files**: `src/views/FlowRunCompare.vue`, `src/api/flowRuns.js`, `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN two runs of one flow WHEN compared THEN nodes line up by id with status and duration, and a version difference is flagged
  - GIVEN a run log without node ids WHEN compared THEN the view says the run could not be read
- [ ] Implement
- [ ] Test (Playwright with two seeded flow runs; a unit test on the log reader)

## Verification
- [ ] `openspec validate observability-compare-two-runs --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance: compare two nightly runs and two runs of one flow
