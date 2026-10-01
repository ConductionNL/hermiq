# Tasks: agents-bound-to-their-app

Kind: code. Size L. Rows `hermiq:ag-app-slug`, `buildiq:ai-in-app-assistant`, `buildiq:ai-record-summary`.

## Implementation tasks

### Task 1: Schema: appAssistant, RecordSummary, offeredBy, and the record-summary prompt and feature
- **spec_ref**: `openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Repair/SeedAiFeatures.php`
- **acceptance_criteria**:
  - GIVEN the register re-import WHEN it runs THEN Agent has `appAssistant`, RecordSummary exists, AgentTemplate has `offeredBy`, and the `record-summary` feature and prompt are seeded
- [ ] Implement (part 1 shipped Agent.appAssistantFor and the hydra-console seed; RecordSummary, the feature and prompt come with task 5, offeredBy with task 6)
- [ ] Test (npm run check:register; PHPUnit on the seed)

### Task 2: The app field and the assistant flag on the agent form
- **spec_ref**: `openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-agent-owner-ties-an-agent-to-the-app-it-serves-req-appag-001`
- **files**: `src/modals/AgentFormModal.vue`, `lib/Controller/AgentsController.php`, `openspec/specs/hermiq-agent-application-slug/spec.md` at archive time, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an owner WHEN they choose an app THEN `applicationSlug` is saved
  - GIVEN a second assistant for one app WHEN saved THEN 409
- [x] Implement
- [x] Test (PHPUnit for the uniqueness guard and the admin-only flag; Playwright for the form: not run in part 1, no live instance in the lane; the form logic is in src/utils/agentApp.js under tests/agent-app.spec.js)

### Task 3: One resolver on both chat endpoints
- **spec_ref**: `openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002`
- **files**: `lib/Service/AppAssistantResolver.php`, `lib/Controller/ChatStreamController.php`, `lib/Controller/ChatController.php`
- **acceptance_criteria**:
  - GIVEN an app assistant WHEN either endpoint gets a request with that appId and no agent THEN that agent answers
- [x] Implement
- [x] Test (PHPUnit for the three fallbacks on both endpoints)

### Task 4: Retrieval scoped to the app's registers
- **spec_ref**: `openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-apps-agent-answers-from-the-apps-data-first-req-appag-003`
- **files**: `lib/Service/Engine/ContextRetrievalHandler.php`
- **acceptance_criteria**:
  - GIVEN an agent tied to an application with no views WHEN it retrieves THEN only that application's registers are searched and each source names its register
- [x] Implement
- [x] Test (PHPUnit; a live check on a built app, answer and sources in the PR body: the live check is the PR's recipe, not run in the lane)

### Task 5: The record summary on the agent leaf
- **spec_ref**: `openspec/changes/agents-bound-to-their-app/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004`
- **files**: `lib/Controller/AssistantController.php`, `lib/Service/Assistant/RecordSummaryService.php`, `appinfo/routes.php`, `src/components/CnAgentRunsWidget/CnAgentRunsWidget.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a readable object WHEN a summary is asked THEN it is stored per object version and labelled
  - GIVEN an unreadable object WHEN asked THEN 404 and no model call
- [ ] Implement
- [ ] Test (PHPUnit on caching and authorization; Newman 200 and 404; Playwright on an OpenBuild record page)

### Task 6: CollectAgentTemplatesEvent and the quarantined import
- **spec_ref**: `openspec/changes/agents-bound-to-their-app/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005`
- **files**: `lib/Event/CollectAgentTemplatesEvent.php`, `lib/Repair/CollectAppAgentTemplates.php`, `lib/Service/AgentTemplateService.php`, the Store page action, `docs/` page for app developers
- **acceptance_criteria**:
  - GIVEN a test listener offering one package WHEN the repair step runs twice THEN one quarantined template exists with `offeredBy` set
- [ ] Implement
- [ ] Test (PHPUnit with a real event dispatcher and a fixture listener; tell the shillinq lane the route is available)

## Verification
- [ ] `openspec validate agents-bound-to-their-app --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run, exit codes read
