# Tasks: tools-deep-research-report

Kind: code. Size M. Row `hermiq:dm-deep-research`.

## Implementation tasks

### Task 1: The research run and its plan
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001`
- **files**: `lib/Service/Research/ResearchRunner.php`, `lib/BackgroundJob/ResearchJob.php`, `lib/Settings/hermiq_register.json` (Handoff research fields), `lib/Mcp/HermiqToolProvider.php` (hermiq.startResearch)
- **acceptance_criteria**:
  - GIVEN a start from chat or the tool WHEN the job begins THEN a research handoff exists and the plan is posted before any search
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed model)

### Task 2: Gathering through the governed chain, and the ledger
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002`
- **files**: `lib/Service/Research/ResearchRunner.php`, `lib/Service/Research/SourceLedger.php`
- **acceptance_criteria**:
  - GIVEN a search and a fetch WHEN they run THEN they pass FacadeToolInvoker and the fetched page is numbered in the ledger
  - GIVEN a refused fetch WHEN it happens THEN it is not in the ledger
- [ ] Implement
- [ ] Test (PHPUnit with stubbed tools)

### Task 3: The citation check
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-every-citation-is-checked-against-what-was-read-req-research-003`
- **files**: `lib/Service/Research/CitationChecker.php`
- **acceptance_criteria**:
  - GIVEN citations that match, a wrong number and an invented passage WHEN checked THEN the first passes and the other two are removed with "(unverified)"
- [ ] Implement
- [ ] Test (PHPUnit with fixtures, including quotation marks and whitespace differences)

### Task 4: The report file and the conversation message
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004`
- **files**: `lib/Service/Research/ReportWriter.php`, `lib/Service/NcNative/AgentArtefactMarker.php` (read only)
- **acceptance_criteria**:
  - GIVEN a finished run WHEN the report is written THEN it is in the user's Files with the tag, and the conversation has the summary, counts and link
  - GIVEN a failed mark WHEN writing THEN the file is removed and the run fails
- [ ] Implement
- [ ] Test (PHPUnit with the tag manager stubbed to fail)

### Task 5: Caps, budget, kill switch and stop
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005`
- **files**: `lib/Service/Research/ResearchRunner.php`, `lib/Controller/ResearchController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN each cap WHEN reached THEN gathering stops and the report states the reason
  - GIVEN a blocked budget or an engaged kill switch WHEN the next model call is due THEN the run ends without a report
  - GIVEN stop WHEN pressed THEN the run ends at the next step
- [ ] Implement
- [ ] Test (PHPUnit with a fake clock and stubbed BudgetService; Newman on the stop route for owner and non-owner)

### Task 6: The chat controls
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001`
- **files**: `src/views/Chat.vue`, `src/api/chat.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an agent with both web tools WHEN the chat opens THEN the Deep research switch is shown, and hidden otherwise
  - GIVEN a running research WHEN shown THEN progress and the stop button appear
- [ ] Implement
- [ ] Test (Playwright under tests/e2e/spec-coverage/ on a seeded research handoff)

## Verification
- [ ] `openspec validate tools-deep-research-report --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run once before push, exit codes read
- [ ] One live research run on a public question, with the report opened in Files and its citations spot-checked by hand
