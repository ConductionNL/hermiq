# Tasks: tools-code-sandbox

Kind: code. Size L. Rows `hermiq:tl-code-exec`, `hermiq:fl-code-step`, `hermiq:dm-sandbox-choice`.

## Implementation tasks

### Task 1: The code sandbox ExApp
- **spec_ref**: `openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-sandbox-runs-code-without-network-without-data-mounts-and-within-limits-req-sandbox-001`
- **files**: `exapp/code-sandbox/Dockerfile`, `exapp/code-sandbox/appinfo/info.xml`, `exapp/code-sandbox/src/server.js`, `exapp/code-sandbox/src/run.js`, `exapp/code-sandbox/deploy/docker-compose.yml`, `exapp/code-sandbox/README.md`, `exapp/code-sandbox/test/`
- **acceptance_criteria**:
  - GIVEN code that opens a socket WHEN it runs THEN it fails and nothing leaves the container
  - GIVEN an endless loop, a memory hog or a fork bomb WHEN it runs THEN the matching limit stops it
  - GIVEN any run WHEN it ends THEN its work directory is gone
- [ ] Implement
- [ ] Test (the ExApp's own test suite, including the network and limit cases)

### Task 2: Dispatch and the backend choice
- **spec_ref**: `openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004`
- **files**: `lib/Service/CodeSandbox/CodeSandboxClient.php`, `lib/Service/CodeSandbox/CodeSandboxSettings.php`, `lib/Controller/Settings/CodeSandboxSettingsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN backend exapp WHEN a run is dispatched THEN it goes through AppAPI to hermiq-code-sandbox
  - GIVEN backend hosted WHEN a run is dispatched THEN it goes through integriq's CallService on the chosen source and nowhere else
- [ ] Implement
- [ ] Test (PHPUnit with AppAPI and CallService stubbed; Newman on the admin settings routes)

### Task 3: The runCode tool and its AI feature gate
- **spec_ref**: `openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002`
- **files**: `lib/Mcp/CodeSandboxToolDescriptors.php`, `lib/Mcp/HermiqToolProvider.php`, `lib/Service/CodeSandbox/RunCodeTool.php`, `lib/Repair/SeedAiFeatures.php`
- **acceptance_criteria**:
  - GIVEN the feature disabled WHEN runCode is called THEN nothing is dispatched and the stated message is returned
  - GIVEN each backend and a files argument WHEN the catalogue is built THEN the reach is self, external or at least user as specified
- [ ] Implement
- [ ] Test (PHPUnit on the descriptor, the gate and the files read)

### Task 4: The code-step flow node
- **spec_ref**: `openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-a-flow-can-run-a-code-step-over-each-item-req-sandbox-003`
- **files**: `lib/Flow/HermiqCodeStepNode.php`, `lib/Flow/HermiqFlowNodeListener.php`
- **acceptance_criteria**:
  - GIVEN an item and code that prints JSON WHEN the node runs THEN the item is replaced
  - GIVEN code that fails WHEN the node runs THEN the item is failed with the stderr tail
  - GIVEN no sandbox WHEN the palette is built THEN the node is absent
- [ ] Implement
- [ ] Test (PHPUnit with the client stubbed; a live flow run with the ExApp)

### Task 5: The admin section and the run record
- **spec_ref**: `openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005`
- **files**: `src/components/settings/CodeSandboxSettings.vue`, `src/views/AdminRoot.vue`, `lib/Service/Engine/FacadeToolInvoker.php` (trace extra), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an admin WHEN they run the test THEN the result and the live backend are shown
  - GIVEN a runCode call WHEN it is traced THEN code, limits and exit code are kept and each output is cut to 4 KB and redacted
- [ ] Implement
- [ ] Test (PHPUnit on the trace extra; Playwright under tests/e2e/spec-coverage/ on the admin section)

## Verification
- [ ] `openspec validate tools-code-sandbox --type change --strict` passes
- [ ] PHPUnit, the ExApp tests, Newman and Playwright run once before push, exit codes read
- [ ] One live chat turn in which an agent runs a calculation in the installed sandbox, with the network test from Task 1 repeated on that host
