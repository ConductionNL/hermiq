# Tasks: tools-deep-research-report

Kind: code. Size M. Row `hermiq:dm-deep-research`.

## Implementation tasks

### Task 1: The research run and its plan
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001`
- **files**: `lib/Service/Research/ResearchRunner.php`, `lib/BackgroundJob/ResearchJob.php`, `lib/Settings/hermiq_register.json` (Handoff research fields), `lib/Mcp/HermiqToolProvider.php` (hermiq.startResearch)
- **acceptance_criteria**:
  - GIVEN a start from chat or the tool WHEN the job begins THEN a research handoff exists and the plan is posted before any search
- [x] Implement
- [x] Test (PHPUnit with a stubbed model)
  - 2026-11-21: `ResearchStarter` opens a `Handoff` of kind `research` (register 0.81.0, Handoff 0.3.0: kind `research` and the fields `depth`, `organisation`, `plan`, `ledger`, `caps`, `progress`, `stopRequested`) after reading the agent and the conversation as the person, and queues `ResearchJob`, which runs `ResearchRunner` as that person. The runner's first model call writes the plan (`ResearchPlan`: three to eight numbered lines, the question itself when none), stores it and posts "Research plan" before any search. `hermiq.startResearch` (`ResearchTool`, reach `user`, `scope: create`) starts a run from a turn; `FacadeToolInvoker` injects the run's agent and conversation. `HandoffDelivery` runs no follow-up turn for a research handoff: the run posts its own messages. Model calls go through `ProviderResearchModel` (`ProviderFactoryInterface::generateText()` with the organisation, so the model policy applies) with the agent's prompt first. Tests: `ResearchRunnerTest`, `ResearchPlanTest`, `ResearchStarterTest`, `ResearchToolTest`, `ResearchJobTest`, `ProviderResearchModelTest`, research cases in `HandoffServiceTest`, `HandoffDeliveryTest` and `FacadeToolInvokerRoutingGoldenTest`.

### Task 2: Gathering through the governed chain, and the ledger
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002`
- **files**: `lib/Service/Research/ResearchRunner.php`, `lib/Service/Research/SourceLedger.php`
- **acceptance_criteria**:
  - GIVEN a search and a fetch WHEN they run THEN they pass FacadeToolInvoker and the fetched page is numbered in the ledger
  - GIVEN a refused fetch WHEN it happens THEN it is not in the ledger
- [x] Implement
- [x] Test (PHPUnit with stubbed tools)
  - 2026-11-21: `GovernedResearchTools` opens the toolbox from `ToolLoop::listAgentFunctions()` (only the agent's granted `hermiq.webSearch` and `hermiq.webFetch`; either missing ends the run with "Research ended: this agent may not both search and read the web.") bound by `ToolLoop::buildFunctionInfos()` to the same `FacadeToolInvoker` a chat turn uses, so grants, guardrails, the egress guard, tracing and redaction apply. `InvokerToolbox` turns a refusal envelope into an `error`, which `SourceLedger::record()` does not number. As built, the runner chooses the queries: each sub-question is one search and its first five results are read, rather than the model picking every call. Tests: `GovernedResearchToolsTest`, the ledger and refusal cases in `ResearchRunnerTest`.
  - 2026-11-13, first slice: `lib/Service/Research/SourceLedger.php` is built and tested (`SourceLedgerTest`, 5): a fetched page gets a number with its URL, title, fetch time and text (webFetch's untrusted delimiter taken off); a refused or empty fetch gets none; the same URL keeps its first number; the ledger round-trips through the handoff and ends the report with its sources list. The gathering loop through `FacadeToolInvoker` waits on the runner (Task 1).

### Task 3: The citation check
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-every-citation-is-checked-against-what-was-read-req-research-003`
- **files**: `lib/Service/Research/CitationChecker.php`
- **acceptance_criteria**:
  - GIVEN citations that match, a wrong number and an invented passage WHEN checked THEN the first passes and the other two are removed with "(unverified)"
- [x] Implement
- [x] Test (PHPUnit with fixtures, including quotation marks and whitespace differences)
  - `lib/Service/Research/CitationChecker.php` and `CitationCheck.php`; `CitationCheckerTest` (7): a matching citation renders as `[n]`, a wrong number and an invented passage are removed and their sentence ends with "(unverified)", case, whitespace and every kind of quotation mark are normalised, a full stop inside a quoted passage does not end the sentence, two failures in one sentence mark it once, and the counts read "14 sources, 1 citation checked, 1 unverified".

### Task 4: The report file and the conversation message
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-report-is-saved-in-the-users-files-and-marked-as-agent-authored-req-research-004`
- **files**: `lib/Service/Research/ReportWriter.php`, `lib/Service/NcNative/AgentArtefactMarker.php` (read only)
- **acceptance_criteria**:
  - GIVEN a finished run WHEN the report is written THEN it is in the user's Files with the tag, and the conversation has the summary, counts and link
  - GIVEN a failed mark WHEN writing THEN the file is removed and the run fails
- [x] Implement
- [x] Test (PHPUnit with the tag manager stubbed to fail)
  - `ReportWriter::write()` saves the report as the user into `Hermiq/Onderzoek/<yyyy-mm-dd> <title>.md` (folders made when missing, a free name when taken), marks it "Agent authored" in the same operation and on a failed mark deletes it and throws, so the run fails; `message()` words the conversation message: at most five summary lines, "14 sources, 31 citations checked, 2 unverified" and the link. `ReportWriterTest` (4). The runner (task 1) posts the message once it exists.

### Task 5: Caps, budget, kill switch and stop
- **spec_ref**: `openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005`
- **files**: `lib/Service/Research/ResearchRunner.php`, `lib/Controller/ResearchController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN each cap WHEN reached THEN gathering stops and the report states the reason
  - GIVEN a blocked budget or an engaged kill switch WHEN the next model call is due THEN the run ends without a report
  - GIVEN stop WHEN pressed THEN the run ends at the next step
- [x] Implement
- [ ] Test (PHPUnit with a fake clock and stubbed BudgetService; Newman on the stop route for owner and non-owner)
  - 2026-11-21, wired: before every tool call the runner reads the caps (`ResearchCaps::stopReason()`) and the stop flag fresh from the handoff; at a cap it writes the report with the cap sentence as its first line, at a stop it ends with "Research stopped." and no file. `ResearchGate` runs before both model calls. `POST /api/research`, `GET /api/research/{id}` and `POST /api/research/{id}/stop` (`ResearchController`, the caller's own runs only, 404 otherwise; documented in `openapi.json`). The token cap counts characters / 4 of each prompt and answer, because `generateText()` answers text only. PHPUnit done (`ResearchRunnerTest` with a fake clock for the time and source limits, a stop between fetches, the kill switch and a budget at its cap; `ResearchControllerTest` for owner, non-owner and signed out). Newman on the stop route needs a running instance and is still open.
  - 2026-11-13, first slice: the caps themselves are built, `lib/Service/Research/ResearchCaps.php` with `ResearchCapsTest` (6): instance defaults 40 / 20 / 20 min / 200,000, an agent may only lower a cap, `quick` halves each, and the first line names the cap reached ("This report stopped early: the source limit of 20 was reached."). The budget and kill-switch checks and the stop route wait on the runner.
  - 2026-11-14, second slice: the check before every model call is built, `lib/Service/Research/ResearchGate.php` with `ResearchGateTest` (5): a stop ends the run with "Research stopped." without reading anything else, an engaged kill switch of the run's organisation ends it before the budget is read, another organisation's does not, and a budget at its hard cap ends it. Wiring it into the runner, the stop route and the controller wait on the runner (Task 1), which needs the `Handoff` schema from `flows-hand-off-and-wait` (still on its own unmerged branch).

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
