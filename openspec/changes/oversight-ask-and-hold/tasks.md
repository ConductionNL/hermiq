# Tasks: oversight-ask-and-hold

Kind: code. Size M. Rows `hermiq:dm-ask-mid-run`, `hermiq:ov-draft-hold`.

## Implementation tasks

### Task 1: Schema: holdOutput, question and draft source types, editedOutput
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the re-import WHEN it runs THEN Approval accepts `question` and `draft`, and Agent and Schedule have `holdOutput`
- [ ] Implement
- [ ] Test (npm run check:register; coordinate the enum with the open approval-task-convergence and name it in the PR)

### Task 2: askPerson in the tool provider and the invoker
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001`
- **files**: `lib/Mcp/HermiqToolProvider.php`, `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Controller/ChatStreamController.php`, `lib/Controller/ChatController.php`
- **acceptance_criteria**:
  - GIVEN a call with five options WHEN invoked THEN refused as invalid; GIVEN a valid call in chat THEN the final event carries the question
- [ ] Implement
- [ ] Test (PHPUnit on validation and the final payload)

### Task 3: The question card in chat
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001`
- **files**: `src/views/Chat.vue`, `src/components/AgentQuestionCard.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a final event with a question WHEN rendered THEN one button per option and a text box when allowed
- [ ] Implement
- [ ] Test (Playwright with a stubbed stream)

### Task 4: Questions in unattended runs
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002`
- **files**: `lib/Service/ApprovalService.php`, `lib/Service/ScheduleService.php`, `src/views/ApprovalInbox.vue`
- **acceptance_criteria**:
  - GIVEN a nightly run that asks WHEN answered THEN the run is re-dispatched with the answer; GIVEN 7 days without answer THEN denied
- [ ] Implement
- [ ] Test (PHPUnit for creation, answer and expiry; Playwright for answering in the inbox)

### Task 5: Holding delivery and outbound message tools
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003`
- **files**: `lib/Service/ScheduleService.php`, `lib/Service/DeliveryService.php`, `lib/Service/ApprovalService.php`, `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Mcp/HermiqToolProvider.php`
- **acceptance_criteria**:
  - GIVEN holdOutput WHEN a scheduled run ends THEN a draft approval and no delivery; WHEN released THEN delivered with the edited text; WHEN recipients change THEN 422
- [ ] Implement
- [ ] Test (PHPUnit for each path; Newman for release and discard)

### Task 6: The drafts view in the approval inbox
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003`
- **files**: `src/views/ApprovalInbox.vue`, `src/modals/DraftReviewModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a held draft WHEN the reviewer edits and releases THEN the approval shows original and sent text
- [ ] Implement
- [ ] Test (Playwright)

## Verification
- [ ] `openspec validate oversight-ask-and-hold --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run, exit codes read
