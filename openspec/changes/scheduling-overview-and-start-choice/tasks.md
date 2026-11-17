# Tasks: scheduling-overview-and-start-choice

Kind: code. Size M. Rows `hermiq:sc-list`, `sc-manual-event-choice`, `sc-nl`.

## Implementation tasks

### Task 1: The schedule list endpoint and page
- **spec_ref**: `openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001`
- **files**: `lib/Controller/ScheduleListController.php`, `appinfo/routes.php`, `src/manifest.json` (page Schedules, navigation), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a user and an admin WHEN they list THEN each sees exactly the schedules the rule allows, with filters working
- [ ] Implement
- [ ] Test (PHPUnit on access; Newman; Playwright for the page and the failed filter)

### Task 2: Run by hand without a schedule
- **spec_ref**: `openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002`
- **files**: `lib/Controller/AgentRunController.php`, `lib/Service/ScheduleService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a usable agent WHEN run by hand THEN the gates apply and history records trigger manual
- [ ] Implement
- [ ] Test (PHPUnit for gates and access; Newman)

### Task 3: How this agent starts, on the agent page
- **spec_ref**: `openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002`
- **files**: `src/widgets/AgentRunOperationsWidget.vue`, `src/api/flows.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN an agent with a schedule, a flow step and a webhook WHEN the page loads THEN all three rows show as active; "Add event" creates an inactive draft flow
- [ ] Implement
- [ ] Test (Playwright; check the flow draft shape against openregister's flow schema)

### Task 4: Plain words to a rule
- **spec_ref**: `openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-a-schedule-can-be-set-in-plain-words-req-sched-003`
- **files**: `lib/Service/ScheduleWhenParser.php`, `lib/Controller/ScheduleWhenController.php`, `appinfo/routes.php`, `src/modals/ScheduleFormModal.vue`
- **acceptance_criteria**:
  - GIVEN the phrase table in English and Dutch WHEN parsed THEN each gives the expected rule; an unreadable text gives 422 with an example
- [ ] Implement
- [ ] Test (PHPUnit table test of at least 30 phrases; the model fallback with a stubbed provider; Playwright for the readback)

## Verification
- [ ] `openspec validate scheduling-overview-and-start-choice --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run, exit codes read
