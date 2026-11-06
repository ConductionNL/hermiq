# Tasks: agents-switch-off-and-stop

Kind: code. Size M. Rows `hermiq:ag-enable`, `ov-kill-agent`, `ag-delete`, `dm-tool-call-cap`.

## Implementation tasks

### Task 1: Schema: availability fields, maxToolCalls and the cascade
- **spec_ref**: `openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004`
- **files**: `lib/Settings/hermiq_register.json`, seed data in the same file
- **acceptance_criteria**:
  - GIVEN the register is re-imported WHEN an agent with two schedules is deleted through ObjectService THEN both schedules are gone
  - GIVEN a fresh install WHEN the seed runs THEN "Weekly supplier digest" is switched off with its reason
- [x] Implement
- [x] Test (`AgentSwitchSchemaTest`: the cascade is declared on Schedule.agentId only, the cap and the three fields exist, and the switched-off seed agent validates with Opis; the cascade itself is OpenRegister's and is in the live check)

### Task 2: The availability endpoint and its authorization
- **spec_ref**: `openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001`
- **files**: `lib/Controller/AgentsController.php`, `appinfo/routes.php`, `lib/Service/AgentAvailabilityService.php`
- **acceptance_criteria**:
  - GIVEN the owner, an instance admin or the organisation owner WHEN they switch the agent THEN it is stored with who, when and why, and an `agent.availability` audit entry is written
  - GIVEN any other user WHEN they call the endpoint THEN 403 and no change
- [x] Implement
- [x] Test (`AgentAvailabilityServiceTest` for owner, instance admin, organisation owner, anyone else, a missing reason and the payload against the Agent fragment; `AgentAvailabilityControllerTest` for 200, 400, 403 and 404)

### Task 3: Refuse runs at the three seams and record the skip
- **spec_ref**: `openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002`
- **files**: `lib/Service/Engine/Engine.php`, `lib/Service/ScheduleService.php`, `lib/Service/Assistant/AssistantService.php`, `lib/Controller/ChatController.php`, `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN a switched-off agent WHEN each entry point is called THEN no model is called
  - GIVEN a due schedule of a switched-off agent WHEN dispatch runs THEN `skipped_agent_off` is recorded and nextRun advances
- [x] Implement
- [x] Test (`EngineSwitchedOffTest`, `ScheduleServiceTest::testScheduleOfASwitchedOffAgentIsSkippedAndAdvances` and `::testRunAgentAsOwnerRefusesASwitchedOffAgent`, `AssistantServiceTest::testSwitchedOffAgentIsRefusedBeforeAnythingIsStored`, `ChatControllerTest::testASwitchedOffAgentAnswers409`, and the caller list)

### Task 4: Stop an in-flight turn and the tool call cap
- **spec_ref**: `openspec/changes/agents-switch-off-and-stop/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Service/Engine/ToolLoop.php`, `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**:
  - GIVEN maxToolCalls 5 WHEN a sixth call is asked THEN it is not invoked and the trace records the limit
  - GIVEN an agent switched off mid-turn WHEN the next tool call comes THEN it is refused and the turn ends
- [x] Implement
- [x] Test (`FacadeToolInvokerStopTest`, `ProviderFactoryUsageTest::testAnthropicLoopStopsAtTheAgentsToolCallCap`, `RunToolCallCounterTest`, `McpRunControllerTest::testToolsCallPastTheRunsCapIsRefusedWithoutRunning`)

### Task 5: The switch on the agent page, the form field and the catalog state
- **spec_ref**: `openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001`
- **files**: `src/manifest.json` (AgentDetail header action, AgentCatalog column), `src/modals/AgentAvailabilityModal.vue`, `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an organisation admin on an agent page WHEN they switch it off with a reason THEN the page and the catalog show it switched off
  - GIVEN the agent form WHEN the owner sets the maximum tool calls THEN it is saved
- [x] Implement
- [x] Test (Playwright `tests/e2e/spec-coverage/agents-switch-off-and-stop.spec.ts`: switch off, switch on, the chat refusal and the form field)

### Task 6: Delete confirmation names the schedules
- **spec_ref**: `openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004`
- **files**: `lib/Controller/AgentsController.php` (`scheduleCount` on show), the catalog row delete dialog
- **acceptance_criteria**:
  - GIVEN an agent with two schedules WHEN its owner chooses delete THEN the dialog says two schedules go with it
- [x] Implement
- [x] Test (Playwright, same file: the confirmation names two schedules; the mirrored-flow answer is in design.md, As built, D4)

## Verification
- [x] `openspec validate agents-switch-off-and-stop --type change --strict` passes
- [ ] PHPUnit run with exit codes read in the full lane check (see the PR). The Playwright spec is written and not run here: it needs a live instance. No Newman collection: the controller test covers 200, 400, 403 and 404
- [ ] A live check: switch off an agent with a due schedule, see the skip in run history, switch it on (recipe in the PR body; not run here)
