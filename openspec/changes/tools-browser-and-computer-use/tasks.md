# Tasks: tools-browser-and-computer-use

Kind: code. Size L. Rows `hermiq:dm-browser-use`, `hermiq:dm-computer-use`.

## Implementation tasks

### Task 1: The browser service and its egress
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-the-browser-reaches-only-what-the-egress-policy-allows-req-browse-001`
- **files**: `exapp/code-sandbox/src/browser.js`, `exapp/code-sandbox/deploy/docker-compose.yml`, `exapp/code-sandbox/deploy/browser-egress-proxy`, `lib/Controller/EgressAuthorizeController.php` (accept the sandbox's run token)
- **acceptance_criteria**:
  - GIVEN a URL the policy refuses WHEN the browser opens it THEN no connection is made
  - GIVEN a conversation WHEN it ends or idles 10 minutes THEN its browser context is closed
- [ ] Implement
- [ ] Test (the ExApp tests against a proxy stub; PHPUnit on the decision point)

### Task 2: The browser tools and untrusted snapshots
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-pages-reach-the-model-as-untrusted-content-req-browse-002`
- **files**: `lib/Mcp/BrowserToolDescriptors.php`, `lib/Mcp/HermiqToolProvider.php`, `lib/Service/Browser/BrowserClient.php`
- **acceptance_criteria**:
  - GIVEN a page WHEN it is returned THEN it is a snapshot with references, at most 20 KB, between the untrusted markers, after filterInput
- [ ] Implement
- [ ] Test (PHPUnit with the browser stubbed)

### Task 3: Click, fill and the password refusal
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md#requirement-clicking-and-filling-are-default-denied-approval-gated-under-policy-and-never-type-a-password-req-browse-003`
- **files**: `lib/Mcp/BrowserToolDescriptors.php`, `exapp/code-sandbox/src/browser.js`
- **acceptance_criteria**:
  - GIVEN the descriptors WHEN classified THEN click and fill are destructive with reach external
  - GIVEN a password field WHEN fill is called THEN nothing is typed
- [ ] Implement
- [ ] Test (PHPUnit on classification; the ExApp tests on the field refusal)

### Task 4: ComputerTarget and its admin screen
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001`
- **files**: `lib/Settings/hermiq_register.json` (ComputerTarget), `lib/Service/ComputerUse/ComputerTargetService.php`, `lib/Controller/Settings/ComputerTargetController.php`, `appinfo/routes.php`, `src/components/settings/ComputerTargets.vue`, `src/modals/ComputerTargetFormModal.vue`
- **acceptance_criteria**:
  - GIVEN an integriq source WHEN an admin takes a test screenshot THEN it goes through CallService and is shown, not stored
  - GIVEN a non-admin WHEN they call the routes THEN they are refused
- [x] Implement (backend: the `ComputerTarget` schema, `ComputerTargetService`, the admin-only `ComputerTargetController` and its routes; the admin settings section `src/components/settings/ComputerTargets.vue` with `src/modals/ComputerTargetFormModal.vue`: list, add, edit, delete, and a test screenshot shown in place and never stored)
- [ ] Test (PHPUnit with CallService stubbed; Newman on the admin routes; Playwright under tests/e2e/spec-coverage/)

### Task 5: The computer tools, the AI feature and the image path
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002`
- **files**: `lib/Mcp/ComputerToolDescriptors.php`, `lib/Service/ComputerUse/ComputerUseService.php`, `lib/Repair/SeedAiFeatures.php`, `lib/Service/Engine/ToolLoop.php` (leave out on image-less paths), `lib/Service/Llm/ProviderFactory.php` (image tool results)
- **acceptance_criteria**:
  - GIVEN the feature disabled WHEN a computer tool is called THEN nothing reaches integriq
  - GIVEN an image-less provider path WHEN the catalogue is built THEN the computer tools are absent
- [x] Implement
  - `lib/Mcp/ComputerToolDescriptors.php` (screenshot read-only; click, type and key destructive; all reach external, each names a `target`), listed by `HermiqToolProvider` and routed to `ComputerUseService` (`ComputerUseServiceInterface`). The service refuses every call unless the `computer-use` AI feature is explicitly enabled (seeded in `SeedAiFeatures`: high-risk, disabled), the machine is registered and switched on, and the arguments are well formed; it reaches the machine through `ComputerTargetService::screenshot()` / `act()` (integriq `CallService`, `POST /action`).
  - `ResponseGenerationHandler` drops the computer tools once the driver is known unless it is Anthropic over HTTP (`ComputerToolDescriptors::forDriver()`); `AnthropicMessageFormat` turns a screenshot result into an image block. No OpenAI Responses path exists in hermiq yet. The agent form hint "Computer use needs a model that can read images." is not built.
- [x] Test (PHPUnit on the gate, the catalogue filter and the Anthropic image tool result)
  - `ComputerUseServiceTest`, `ComputerToolDescriptorsTest`, `AnthropicMessageFormatTest::testAScreenshotToolResultIsAnImageBlock`, `ComputerTargetServiceTest::testAnActionPostsItsBodyToTheMachineAndAnswersWithTheNewScreen`; catalogue and seed tests updated.

### Task 6: The approval floor and the run-scoped pre-authorisation
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Service/ApprovalService.php`, `src/views/ApprovalInbox.vue`
- **acceptance_criteria**:
  - GIVEN a policy that allows computerClick WHEN it is called THEN it still needs approval
  - GIVEN a 15 minute pre-authorisation WHEN it expires or another machine or run is named THEN a new approval is needed
  - GIVEN a screenshot WHEN it is called THEN no approval is created
- [ ] Implement
  - Done: the floor. `FacadeToolInvoker::classifyTool()` returns `confirm` for click, type and key whatever the policy says (a `deny` still refuses), and an owner's `#noapproval` waiver never answers them (`waivable()`). A screenshot creates no approval.
  - Open: the 15 minute run-scoped pre-authorisation per machine (run id into the invoker, `ApprovalService` request and lookup with a clock), and the approval card in `src/views/ApprovalInbox.vue` showing the machine, the action and the screenshot with the point marked.
- [ ] Test (PHPUnit with a fake clock; Playwright under tests/e2e/spec-coverage/ on the approval card)
  - PHPUnit on the floor done: `FacadeToolInvokerComputerTest` (no policy, auto, waiver, deny, screenshot). The fake-clock test waits on the pre-authorisation.

### Task 7: The run record for browser and computer actions
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-the-run-records-actions-and-never-stores-screenshots-req-computer-004`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php` (trace extra), `src/views/Runs.vue`
- **acceptance_criteria**:
  - GIVEN a computer action WHEN it is traced THEN machine, action, coordinates, redacted text and approval id are kept and a screenshot is a hash and size
- [ ] Implement
  - Done: `ComputerActionRecord` builds the trace extra (`computer`: target, action, x/y, redacted text, keys, approvalId, screenshot sha256 and bytes), added by `FacadeToolInvoker::traceExtraFor()` with the approval id threaded through `FacadeCall`; the streamed tool result carries the hash instead of the image (`ToolCallReporter`).
  - Open: showing these steps in `src/views/Runs.vue`, which lists runs without their steps today.
- [x] Test (PHPUnit on the trace extra)
  - `ComputerActionRecordTest`, `FacadeToolInvokerComputerTest` (approval id and coordinates on the step, no image in the trace or the stream).

## Verification
- [ ] `openspec validate tools-browser-and-computer-use --type change --strict` passes
- [ ] PHPUnit, the ExApp tests, Newman and Playwright run once before push, exit codes read
- [ ] One live browser session against a public test form, and one approved click on a test Windows machine through an integriq source
