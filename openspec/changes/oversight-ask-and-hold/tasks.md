# Tasks: oversight-ask-and-hold

Kind: code. Size M. Rows `hermiq:dm-ask-mid-run`, `hermiq:ov-draft-hold`.

## Implementation tasks

### Task 1: Schema: holdOutput, question and draft source types, editedOutput
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN the re-import WHEN it runs THEN Approval accepts `question` and `draft`, and Agent and Schedule have `holdOutput`
- [x] Implement
- [x] Test (npm run check:register; coordinate the enum with the open approval-task-convergence and name it in the PR)
  - Approval.sourceType gains `question` and `draft`, Approval gains `editedOutput`, and Agent and Schedule gain `holdOutput`. Schemas: Approval 0.9.0, Agent 0.15.0, Schedule 0.5.0; register 0.55.0. `npm run check:register` PASS. The enum was not coordinated with approval-task-convergence (not open on development at build time); whichever lands second keeps both sets of values.

### Task 2: askPerson in the tool provider and the invoker
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001`
- **files**: `lib/Mcp/HermiqToolProvider.php`, `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Controller/ChatStreamController.php`, `lib/Controller/ChatController.php`
- **acceptance_criteria**:
  - GIVEN a call with five options WHEN invoked THEN refused as invalid; GIVEN a valid call in chat THEN the final event carries the question
- [x] Implement
- [x] Test (PHPUnit on validation and the final payload)
  - `hermiq.askPerson` is in `HermiqToolProvider` (reach self, read; the facade answers internal_only). `FacadeToolInvoker` routes it to `AskPersonCall`, which validates through `AskPersonQuestion`, allows one question per run, records it on the run trace and stops the turn through `TurnGuard::stop()`. `ChatStreamController` (`final` payload `question`) and `ChatController` (`question` on the result) carry it. Test: `FacadeToolInvokerAskPersonTest` (5).

### Task 3: The question card in chat
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001`
- **files**: `src/views/Chat.vue`, `src/components/AgentQuestionCard.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a final event with a question WHEN rendered THEN one button per option and a text box when allowed
- [x] Implement
- [ ] Test (Playwright with a stubbed stream)
  - `AgentQuestionCard` shows the question under the answer it came with (the `final` payload's or the POST result's `messageId`), a button per suggested answer (at most four) and a text box when `allowOther`; `Chat.vue` `takeQuestion()` keeps it and `answerQuestion()` sends the choice as the next message. Vitest: `agent-question-card.spec.js` (6), `chat-agent-question.spec.js` (4). The Playwright run is a browser task and is left for a live run.

### Task 4: Questions in unattended runs
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002`
- **files**: `lib/Service/ApprovalService.php`, `lib/Service/ScheduleService.php`, `src/views/ApprovalInbox.vue`
- **acceptance_criteria**:
  - GIVEN a nightly run that asks WHEN answered THEN the run is re-dispatched with the answer; GIVEN 7 days without answer THEN denied
- [x] Implement
- [ ] Test (PHPUnit for creation, answer and expiry; Playwright for answering in the inbox)
  - A scheduled run's question (`RunTraceCollector::question()`) becomes one pending `question` Approval for the schedule's reviewer, else its owner, with Talk and notification like any approval (`ApprovalService::ensurePendingQuestion()`), and nothing is delivered. `POST /api/approvals/{id}/answer` (`answerQuestion()`, answer checked by `QuestionAnswer`, 422 when refused) dispatches the run again through `ScheduleService::runNow(prompt:)` with the prompt, the question and the answer, and stores `draftPayload.runId`, `answer` and `answeredRunId` so the history links them. The dispatcher tick expires questions older than 7 days as denied (`expireUnansweredQuestions()`). The inbox answers a question with the same `AgentQuestionCard` the chat uses. PHPUnit done: `QuestionAnswerTest` (10), `ApprovalServiceAskAndHoldTest`, `ScheduleServiceTest` (question, answered prompt, expiry), `ApprovalControllerTest`; vitest `approval-inbox-rows.spec.js`. Left: Playwright (browser), and questions from flow and webhook runs, which still deliver as before: those runs have no schedule to re-dispatch, and their resume context needs its own answer path.

### Task 5: Holding delivery and outbound message tools
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003`
- **files**: `lib/Service/ScheduleService.php`, `lib/Service/DeliveryService.php`, `lib/Service/ApprovalService.php`, `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Mcp/HermiqToolProvider.php`
- **acceptance_criteria**:
  - GIVEN holdOutput WHEN a scheduled run ends THEN a draft approval and no delivery; WHEN released THEN delivered with the edited text; WHEN recipients change THEN 422
- [x] Implement
- [ ] Test (PHPUnit for each path; Newman for release and discard)
  - `ScheduleService::deliver()` holds the output when `OutputHold::holds()` (the schedule's `holdOutput` when set, else the agent's): `ensurePendingDraft()` stores `{kind: delivery, channel, output, conversationUuid}` and nothing is sent; a draft that cannot be stored sends nothing and records why. For an agent with `holdOutput`, `FacadeToolInvoker` holds `hermiq.sendMail` and any tool whose descriptor declares `sendsMessage` at its one dispatch point (after every gate, never in a dry run) via `ensurePendingToolDraft()`, routed to the agent owner, and tells the model "Draft held for review". `POST /api/approvals/{id}/release` (`releaseDraft()`, `DraftRelease`) sends it as written or with edits to `output` or the tool's `editable` fields, records `editedOutput {original, sent}`, runs a released tool as the owner, and refuses a changed recipient or other fixed field with 422; approving a draft releases it as written, and discarding is a deny with a reason. PHPUnit done: `DraftReleaseTest` (9), `OutputHoldTest`, `FacadeToolInvokerHoldOutputTest` (5), `ApprovalServiceAskAndHoldTest`, `ScheduleServiceTest`, `ApprovalControllerTest`. Seed: the "Weekly supplier digest" agent has `holdOutput: true`. Left: Newman (needs a live instance).

### Task 6: The drafts view in the approval inbox
- **spec_ref**: `openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003`
- **files**: `src/views/ApprovalInbox.vue`, `src/modals/DraftReviewModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a held draft WHEN the reviewer edits and releases THEN the approval shows original and sent text
- [x] Implement
- [ ] Test (Playwright)
  - The inbox gets an All / Drafts filter with the drafts count; a draft row shows its first line and "Review", which opens `DraftReviewModal`: the recipients read-only, the subject and text in an editor, and "Release", "Edit and release" (sends only the changed fields) and "Discard" (the deny dialog, with a reason). The inbox record carries `question` and `draft` for those two source types only. Vitest: `draft-review-modal.spec.js` (7), `approval-inbox-rows.spec.js`; PHPUnit `ApprovalInboxTest`. Left: Playwright (browser). The stored `editedOutput` holds both versions; a view of decided approvals to show them is not part of this inbox, which lists pending ones.

## Verification
- [ ] `openspec validate oversight-ask-and-hold --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run, exit codes read
