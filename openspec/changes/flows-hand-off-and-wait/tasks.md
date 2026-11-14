# Tasks: flows-hand-off-and-wait

Kind: code. Size L. Rows `hermiq:dm-async-flow-step`, `hermiq:dm-remote-agent`, `hermiq:fl-n8n`.

## Implementation tasks

### Task 1: The Handoff schema and its service
- **spec_ref**: `openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001`
- **files**: `lib/Settings/hermiq_register.json` (Handoff), `lib/Service/Handoff/HandoffService.php`
- **acceptance_criteria**:
  - GIVEN a new handoff WHEN it is opened THEN it has a conversation, an owner, a kind and a deadline of at most 7 days
  - GIVEN a closed handoff WHEN a second close arrives THEN it is ignored
- [ ] Implement
- [ ] Test (PHPUnit on the state transitions)

### Task 2: The follow-up turn and the notification
- **spec_ref**: `openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-finished-handoff-returns-its-result-as-a-new-turn-in-the-same-conversation-req-handoff-002`
- **files**: `lib/BackgroundJob/HandoffResultJob.php`, `lib/Service/Handoff/HandoffService.php`, `lib/Notification/Notifier.php`
- **acceptance_criteria**:
  - GIVEN a done handoff WHEN the job runs THEN one governed turn runs in the conversation with the result delimited as external content, and the owner is notified
  - GIVEN an inactive agent or an engaged kill switch WHEN the job runs THEN no turn runs and the result stays on the handoff
- [ ] Implement
- [ ] Test (PHPUnit on the job with the gates stubbed both ways)

### Task 3: Flow handoffs from runFlow and the terminal event
- **spec_ref**: `openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-flow-started-by-an-agent-closes-its-handoff-when-the-flow-run-ends-req-handoff-003`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Listener/FlowRunTerminalListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN runFlow from a turn WHEN the run is queued THEN a flow handoff holds its run id
  - GIVEN the terminal event WHEN it names that run THEN the handoff closes with the final items
  - GIVEN no terminal event class WHEN runFlow is called THEN no handoff opens and the model is told
- [ ] Implement
- [ ] Test (PHPUnit with the event class present and absent)

### Task 4: The webhook result route and the n8n preset
- **spec_ref**: `openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004`
- **files**: `lib/Controller/HandoffResultController.php`, `appinfo/routes.php`, `lib/Service/CustomTool/CustomToolInvoker.php`, the custom tool editor from `tools-custom-tools-without-code`
- **acceptance_criteria**:
  - GIVEN a waitForCallback tool WHEN it is called THEN the payload through integriq carries the result address and token
  - GIVEN the right token once WHEN posted THEN the handoff closes; a repeat, a wrong token or a closed handoff gets 404
- [ ] Implement
- [ ] Test (Newman on the route; PHPUnit on the token; Playwright under tests/e2e/spec-coverage/ for the n8n preset)

### Task 5: RemoteAgent and its admin screen
- **spec_ref**: `openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001`
- **files**: `lib/Settings/hermiq_register.json` (RemoteAgent), `lib/Service/RemoteAgent/RemoteAgentService.php`, `lib/Controller/RemoteAgentController.php`, `appinfo/routes.php`, `src/views/RemoteAgents.vue`, `src/modals/RemoteAgentFormModal.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN an integriq source WHEN an admin registers a remote agent THEN its card is fetched through the source and stored as a snapshot, and no credential is stored in hermiq
  - GIVEN a non-admin WHEN they call the admin routes THEN they are refused
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed CallService; Playwright under tests/e2e/spec-coverage/)

### Task 6: The askRemoteAgent tool and its polling job
- **spec_ref**: `openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003`
- **files**: `lib/Mcp/HermiqToolProvider.php`, `lib/Service/RemoteAgent/A2aClient.php`, `lib/BackgroundJob/RemoteTaskPollJob.php`
- **acceptance_criteria**:
  - GIVEN a completed A2A answer WHEN the tool runs THEN it returns the text, through CallService only
  - GIVEN a working task WHEN the tool runs THEN an a2a handoff opens and the job polls tasks/get until done or the deadline
  - GIVEN a grant naming one remote agent WHEN another is asked THEN the call is refused
- [ ] Implement
- [ ] Test (PHPUnit on descriptor hints, grant constraint, request mapping and polling; a live check against a test A2A peer)

### Task 7: The waiting state in the chat
- **spec_ref**: `openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001`
- **files**: `src/views/Chat.vue`, `src/api/chat.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an open handoff WHEN the conversation is shown THEN the waiting line appears under the message that started it, and changes when it closes
- [ ] Implement
- [ ] Test (Playwright under tests/e2e/spec-coverage/ on a seeded handoff)

## Verification
- [ ] `openspec validate flows-hand-off-and-wait --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run once before push, exit codes read
- [ ] One live run: an agent starts a flow with a wait node of two minutes and answers on its result in the same conversation
