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
- [x] Implement
  - `exapp/code-sandbox/`: `POST /run` behind the AppAPI shared secret, a fresh work directory per run in the `/work` tmpfs removed afterwards, a separate runner uid, `prlimit` caps (address space for Python, heap for Node, 64 processes, 10 MB files), the process group killed at the wall time, 64 KB per output stream, no server environment passed on; Dockerfile with pinned numpy/pandas/python-dateutil, `deploy/docker-compose.yml` with only an internal network, no volumes, read-only root and dropped capabilities.
- [ ] Test (the ExApp's own test suite, including the network and limit cases)
  - `npm test` in exapp/code-sandbox: 20 pass (run, server, deploy posture). The network case runs only inside the container (`SANDBOX_IN_CONTAINER=1`), and the per-run process cap only binds the non-root runner uid; both wait on a run of the suite in the built image.

### Task 2: Dispatch and the backend choice
- **spec_ref**: `openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004`
- **files**: `lib/Service/CodeSandbox/CodeSandboxClient.php`, `lib/Service/CodeSandbox/CodeSandboxSettings.php`, `lib/Controller/Settings/CodeSandboxSettingsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN backend exapp WHEN a run is dispatched THEN it goes through AppAPI to hermiq-code-sandbox
  - GIVEN backend hosted WHEN a run is dispatched THEN it goes through integriq's CallService on the chosen source and nowhere else
- [x] Implement
  - Done: `CodeSandboxSettings` (backend `exapp` default or `hosted` with a source; any doubtful stored value reads as `exapp`; reach `self` / `external`) and `CodeSandboxClient` (the `exapp` path through AppAPI `POST /run` with clamped limits; "No code sandbox is installed on this instance." when AppAPI or the ExApp is absent). `CodeSandboxSettingsController` (admin only) reads the choice with whether it can take runs and saves it, refusing an unknown backend or a hosted one without its source with 422; routes `GET`/`PATCH /api/settings/code-sandbox`, documented in `openapi.json`; `CodeSandboxSettingsControllerTest`. `HostedSandboxDispatch` sends a hosted run to the chosen integriq source's `/run` through integriq's CallService only, with bodies kept out of integriq's call log; without integriq or that source a hosted choice refuses the run and sends nothing. `POST /api/settings/code-sandbox/test` runs `print(1 + 1)`.
- [ ] Test (PHPUnit with AppAPI and CallService stubbed; Newman on the admin settings routes)
  - PHPUnit done (`CodeSandboxClientTest`, `HostedSandboxDispatchTest`, `CodeSandboxSettingsControllerTest`); Newman needs a live instance.

### Task 3: The runCode tool and its AI feature gate
- **spec_ref**: `openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002`
- **files**: `lib/Mcp/CodeSandboxToolDescriptors.php`, `lib/Mcp/HermiqToolProvider.php`, `lib/Service/CodeSandbox/RunCodeTool.php`, `lib/Repair/SeedAiFeatures.php`
- **acceptance_criteria**:
  - GIVEN the feature disabled WHEN runCode is called THEN nothing is dispatched and the stated message is returned
  - GIVEN each backend and a files argument WHEN the catalogue is built THEN the reach is self, external or at least user as specified
- [x] Implement
  - `CodeSandboxToolDescriptors` (`hermiq.runCode`, `scope: create`, reach `self` or `external` from the setting, `reachWithArguments.files` at least `user`), `RunCodeTool` (the `agent-code-execution` gate before anything is read or sent, files read from the acting user's own folder, the client's refusals as envelopes), routed from `HermiqToolProvider`; `agent-code-execution` seeded high-risk and disabled.
- [x] Test (PHPUnit on the descriptor, the gate and the files read)

### Task 4: The code-step flow node
- **spec_ref**: `openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-a-flow-can-run-a-code-step-over-each-item-req-sandbox-003`
- **files**: `lib/Flow/HermiqCodeStepNode.php`, `lib/Flow/HermiqFlowNodeListener.php`
- **acceptance_criteria**:
  - GIVEN an item and code that prints JSON WHEN the node runs THEN the item is replaced
  - GIVEN code that fails WHEN the node runs THEN the item is failed with the stderr tail
  - GIVEN no sandbox WHEN the palette is built THEN the node is absent
- [x] Implement
  - `HermiqCodeStepNode` (item JSON on stdin, the printed JSON object replaces it, a failing, timed-out or unprintable run keeps the item with an `error` and the stderr tail, 1 to 30 seconds, the same AI feature gate, absent from the palette while no sandbox can take runs), registered last by `HermiqFlowNodeListener`.
- [ ] Test (PHPUnit with the client stubbed; a live flow run with the ExApp)
  - PHPUnit done (`HermiqCodeStepNodeTest`); the live flow run waits on the ExApp (Task 1).

### Task 5: The admin section and the run record
- **spec_ref**: `openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005`
- **files**: `src/components/settings/CodeSandboxSettings.vue`, `src/views/AdminRoot.vue`, `lib/Service/Engine/FacadeToolInvoker.php` (trace extra), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an admin WHEN they run the test THEN the result and the live backend are shown
  - GIVEN a runCode call WHEN it is traced THEN code, limits and exit code are kept and each output is cut to 4 KB and redacted
- [x] Implement
  - `CodeSandboxSettings.vue` in Admin settings (own or hosted with its integriq source, the live backend, "Run a test" through `POST /api/settings/code-sandbox/test`, which runs `print(1 + 1)`), `CodeRunTrace` (the `codeRun` trace extra from `FacadeToolInvoker`: code, language, limits, exit code, duration, `timedOut`, stdout and stderr cut to 4 KB and redacted, e-mail addresses masked, outputs withheld without a redaction service), en/nl strings.
- [ ] Test (PHPUnit on the trace extra; Playwright under tests/e2e/spec-coverage/ on the admin section)
  - PHPUnit (`CodeRunTraceTest`, the invoker's trace step, the test route) and vitest (`code-sandbox-settings.spec.js`) done; the Playwright run needs a live instance.

## Verification
- [x] `openspec validate tools-code-sandbox --type change --strict` passes
  - 2026-11-21: valid under `@fission-ai/openspec@1.2.0`, the version hydra's apply loop pins.
- [ ] PHPUnit, the ExApp tests, Newman and Playwright run once before push, exit codes read
- [ ] One live chat turn in which an agent runs a calculation in the installed sandbox, with the network test from Task 1 repeated on that host
