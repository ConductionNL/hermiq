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
- [ ] Implement
- [ ] Test (PHPUnit with CallService stubbed; Newman on the admin routes; Playwright under tests/e2e/spec-coverage/)

### Task 5: The computer tools, the AI feature and the image path
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002`
- **files**: `lib/Mcp/ComputerToolDescriptors.php`, `lib/Service/ComputerUse/ComputerUseService.php`, `lib/Repair/SeedAiFeatures.php`, `lib/Service/Engine/ToolLoop.php` (leave out on image-less paths), `lib/Service/Llm/ProviderFactory.php` (image tool results)
- **acceptance_criteria**:
  - GIVEN the feature disabled WHEN a computer tool is called THEN nothing reaches integriq
  - GIVEN an image-less provider path WHEN the catalogue is built THEN the computer tools are absent
- [ ] Implement
- [ ] Test (PHPUnit on the gate, the catalogue filter and the Anthropic image tool result)

### Task 6: The approval floor and the run-scoped pre-authorisation
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`, `lib/Service/ApprovalService.php`, `src/views/ApprovalInbox.vue`
- **acceptance_criteria**:
  - GIVEN a policy that allows computerClick WHEN it is called THEN it still needs approval
  - GIVEN a 15 minute pre-authorisation WHEN it expires or another machine or run is named THEN a new approval is needed
  - GIVEN a screenshot WHEN it is called THEN no approval is created
- [ ] Implement
- [ ] Test (PHPUnit with a fake clock; Playwright under tests/e2e/spec-coverage/ on the approval card)

### Task 7: The run record for browser and computer actions
- **spec_ref**: `openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-the-run-records-actions-and-never-stores-screenshots-req-computer-004`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php` (trace extra), `src/views/Runs.vue`
- **acceptance_criteria**:
  - GIVEN a computer action WHEN it is traced THEN machine, action, coordinates, redacted text and approval id are kept and a screenshot is a hash and size
- [ ] Implement
- [ ] Test (PHPUnit on the trace extra)

## Verification
- [ ] `openspec validate tools-browser-and-computer-use --type change --strict` passes
- [ ] PHPUnit, the ExApp tests, Newman and Playwright run once before push, exit codes read
- [ ] One live browser session against a public test form, and one approved click on a test Windows machine through an integriq source
