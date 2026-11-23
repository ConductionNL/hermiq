# Tasks: tools-agent-workspace-and-installed-tools

Kind: code. Size L. Rows `hermiq:dm-agent-workspace`, `hermiq:dm-install-cli-tool`, `hermiq:dm-build-snapshot`.

## Implementation tasks

### Task 1: The session lifetime for the governed workspace
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001`
- **files**: `lib/Settings/hermiq_register.json` (Agent.workspaceMode), the workspace provider from `hermiq-runner-git-capability`, `lib/BackgroundJob/WorkspaceExpiryJob.php`
- **acceptance_criteria**:
  - GIVEN workspaceMode session WHEN two runs share a conversation THEN they reach one workspace, and a run of another conversation, user or agent does not
  - GIVEN a conversation deleted or idle for 14 days WHEN the job runs THEN its workspace is gone
- [x] Implement
- [x] Test (PHPUnit on keying, isolation and expiry)
  > 2026-11-13: `Agent.workspaceMode` (register v0.53.0); `WorkspaceRunScope` keys a `session` agent's workspace by `SessionWorkspaceKey` (agent, conversation, user from the token); the provider records the lifetime, gives a session 14 days of idleness and 5,000 files, and `WorkspaceExpiryJob` ends the workspace of a deleted conversation. `none` keys by the run like `run`: withholding the workspace tools from `none` agents would change agents that use them today, so it is left to the change that adds the sandbox tools.

### Task 2: Copy in and write back around sandbox runs
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002`
- **files**: `lib/Service/CodeSandbox/RunCodeTool.php`, `lib/Service/CodeSandbox/WorkspaceTransfer.php`
- **acceptance_criteria**:
  - GIVEN a run that changes one file WHEN it ends THEN only that file is written back, confined to the workspace
  - GIVEN a workspace over the input cap WHEN a run is asked THEN it is refused with the limit named
- [x] Implement
- [x] Test (PHPUnit with a stubbed sandbox, including a returned path that tries to leave the workspace)
  > 2026-11-14, partly built: `WorkspaceTransfer` packs the workspace (or the named `paths`) into run files within the 10 MB input cap, refusing over it with the message from design D2, and writes back only the files a run created or changed, through `WorkspacePathGuard::forWrite()` and inside the workspace budget (a run over budget writes nothing); a returned path outside the workspace or into `.git` is refused and reported while the rest lands. Text travels as UTF-8, anything else as base64. PHPUnit `WorkspaceTransferTest` with the test playing the sandbox. Still open: `useWorkspace` on `hermiq.runCode` in `RunCodeTool`, which is not on development yet (tools-code-sandbox is in its own open build); both boxes stay unticked until that call site exists.
  > 2026-11-17: `hermiq.runCode` takes `useWorkspace` and `paths` (`RunCodeTool::workspaceInput()`): inside a governed run with an open workspace it packs the workspace (or `paths`) through `WorkspaceTransfer`, sends it as a workspace run, writes back what the run created or changed and answers `workspace: {written, refused}` instead of the file contents. It defaults to the workspace only when the call names no files of the acting user, so those calls run as before; asked for explicitly without a workspace, or together with `files`, it is refused. The sandbox (`exapp/code-sandbox/src/run.js`) gains the workspace mode: relative paths in a `work` folder that is the working directory, the program kept beside it, and every created or changed file answered; a path leaving the folder or into `.git` is refused before anything runs. PHPUnit `RunCodeToolWorkspaceTest`, node tests in `exapp/code-sandbox/test/run.test.js`.

### Task 3: The Files panel and saving to Files
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003`
- **files**: `lib/Controller/WorkspaceFilesController.php`, `appinfo/routes.php`, `src/views/Chat.vue`, `src/components/WorkspaceFilesPanel.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the session user WHEN they save a file THEN it lands in their Files with the agent-authored tag, and a failed tag fails the save
  - GIVEN another user WHEN they call the routes for this workspace THEN they are refused
- [x] Implement
- [ ] Test (Newman on the routes including another user; Playwright under tests/e2e/spec-coverage/ on the panel)
  > 2026-11-14: `WorkspaceFiles` lists, opens (through `WorkspacePathGuard`) and saves a session workspace file into the person's Files under "Hermiq" with the agent-authored tag, deleting the copy when the tag fails. `WorkspaceFilesController` keys the workspace by the session's agent, the session and the signed-in user and answers 404 to anyone who takes no part in the session; three routes, documented in `openapi.json`. `WorkspaceFilesPanel.vue` sits under the chat header. Covered by PHPUnit (`WorkspaceFilesTest`, `WorkspaceFilesControllerTest`, including another user) and Vitest (`workspace-files-panel.spec.js`); Newman and Playwright need a live instance and were not run. `WorkspaceToolsetReadTest` still refuses every other route naming the workspace; the Files panel's three routes are the stated exception.
  > 2026-11-21: `tests/e2e/spec-coverage/agent-workspace-and-builds.spec.ts` written for the Files panel (listing, download link, "Save to my Files"), with the workspace routes answered by page.route; not run here, it needs a running Nextcloud. Newman stays open.

### Task 4: The installer and installTool
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004`
- **files**: `exapp/code-sandbox/src/installer.js`, `exapp/code-sandbox/deploy/docker-compose.yml`, `exapp/code-sandbox/deploy/installer-egress-proxy`, `lib/Mcp/CodeSandboxToolDescriptors.php`, `lib/Service/CodeSandbox/InstallToolService.php`
- **acceptance_criteria**:
  - GIVEN an allowlisted registry WHEN a package is installed THEN no install script runs and a layer with hash and versions is stored
  - GIVEN a registry or package outside the allowlists WHEN install is asked THEN the installer is not called
  - GIVEN a code run WHEN it tries the installer's network THEN it has no route
- [x] Implement
- [x] Test (the ExApp tests for scripts, egress and isolation; PHPUnit on the tool)
  > 2026-11-23: the ExApp gains the installer service (`src/installer.js`, `src/installerServer.js`), run from the same image in its own container as the unprivileged sandbox uid; the sandbox server forwards `POST /install` to it like the browser routes. pip resolves with a `--dry-run --report` and then installs each resolved requirement with `--only-binary=:all: --no-deps --target`, npm installs with `--ignore-scripts`, every step spawned with an argument list and no shell; the prefix is packed as a gzipped tar with fixed owners and times, answered with its SHA-256, the resolved versions and the binaries. Its only way out is `installer-egress-proxy` (`src/egressAllowlistProxy.js`, `deploy/installer-egress-proxy/Dockerfile`): HTTPS CONNECT to an exact allowlisted host on 443, default PyPI and npm. In `deploy/docker-compose.yml` the code runs' container joins `installer-control` only, never `installer-jail` or `installer-out`. Hermiq: `hermiq.installTool` (reach `external`, `scope: create`, `destructiveHint: true`) served by `InstallToolService`: `InstallPolicy` checks the AI feature, the arguments, the admin's registries (`CodeSandboxSettings::installManagers()`, `installManagers` on the settings PATCH) and the organisation's `GuardrailPolicy.packageAllowlist` (register 0.83.0) before `CodeSandboxClient::install()` is called; `ToolLayerKeeper` checks the layer against its hash, stores it by hash beside the workspaces and records the tool on the conversation's workspace, so `AgentBuildService::publish()` now pins the installed tools. The trace step records manager, package, version and the layer hash (`ToolInstallTrace`). Tests: node `test/installer.test.js`, `test/installerServer.test.js`, the installer cases in `test/deploy.posture.test.js` and `test/server.test.js`; PHPUnit `InstallToolServiceTest` and cases in `CodeSandboxClientTest`, `CodeSandboxSettingsTest`, `GuardrailPolicyServiceTest`, `ServerSideWorkspaceProviderSessionTest`, `AgentBuildServiceTest` and `FacadeToolInvokerTest`. Not done here: a real install against PyPI through the proxy (needs the deployed containers), and a field for the registries in the admin's code sandbox section.

### Task 5: runInstalledTool
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005`
- **files**: `exapp/code-sandbox/src/run.js`, `lib/Service/CodeSandbox/RunInstalledToolService.php`, `lib/Mcp/CodeSandboxToolDescriptors.php`
- **acceptance_criteria**:
  - GIVEN an argument with shell syntax WHEN the tool runs THEN it reaches the binary as one argument and nothing else runs
  - GIVEN a layer hash the sandbox lacks WHEN it runs THEN hermiq sends the bytes and the sandbox verifies the hash
- [x] Implement
- [x] Test (the ExApp tests; PHPUnit on the dispatch)
  > 2026-11-23: the sandbox's `POST /run` takes `{installed: {sha256, tool, args}, layer?, files, stdin, limits}` (`src/installedTool.js`). A layer the cache (`SANDBOX_LAYER_ROOT`, a `/layers` tmpfs the runner can read and run from but not write) lacks is answered 409 `layer_missing`; sent bytes are checked against the hash before they are unpacked. The binary is resolved inside the layer (`bin/` for pip, `node_modules/.bin/` for npm, a symlink out of the layer is refused) and runs with an argument list through the same `prlimit`/runner-uid command as code (`limitedCommand()`, `executeCommand()` in `src/run.js`), on the workspace copy, answering what it created or changed. Hermiq: `hermiq.runInstalledTool` (descriptor as `hermiq.runCode`, reach at least `user`) served by `RunInstalledToolService`: the AI feature, a plain binary name and a list of at most 64 strings, the installed tool offering that binary (`ToolLayerKeeper::toolOffering()`), the workspace packed through `WorkspaceTransfer`, `CodeSandboxClient::runInstalled()` resending once with the kept layer (`ToolLayerKeeper::layer()`, re-checked against its hash) on 409, and the changes written back. Tests: node `test/installedTool.test.js` (shell syntax stays one argument, the cache and its hash check, a binary outside the layer), the layer cases in `test/deploy.posture.test.js` and `test/server.test.js`; PHPUnit `RunInstalledToolServiceTest` and two cases in `CodeSandboxClientTest`. Not done here: a run of a real wheel's console script in the deployed containers.

### Task 6: AgentBuild, publishing and runs from the build
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-runs-of-a-published-agent-start-from-its-pinned-build-req-build-002`
- **files**: `lib/Settings/hermiq_register.json` (AgentBuild, Agent.pinnedBuildId), `lib/Service/AgentBuildService.php`, `lib/Service/AgentVersionService.php` (rollback re-pin), `lib/Service/ScheduleService.php` (buildId on the run), `lib/Controller/AgentVersionController.php`
- **acceptance_criteria**:
  - GIVEN publish WHEN it completes THEN the build holds the version id, the archive hash and the tools, and is pinned
  - GIVEN a pinned build WHEN a new conversation starts THEN its workspace starts from the build and run changes never reach the build
  - GIVEN a rollback to a version with a build WHEN it completes THEN that build is pinned
- [x] Implement
- [ ] Test (PHPUnit on publish, start, rollback; Newman on the publish route)
  > 2026-11-14: `AgentBuild` schema and `Agent.pinnedBuildId` (register v0.58.0, both written only by the service: the schema is admin-only through the object API and the pin carries an admin update rule). `AgentBuildService` publishes from the publisher's own session workspace (keyed by agent, conversation and publisher), numbers the build, records `currentVersionId()`, archives through `WorkspaceSnapshot` (a zip in the build store named by the sha256 over the sorted paths and file hashes outside `.git`, so equal builds share one archive) and pins it. `McpRunController` starts a new session workspace from the pinned build (`ServerSideWorkspaceProvider::restore()`, which refuses an archive naming a path outside the workspace and never overwrites an open workspace); the run entry in `ScheduleService` records `buildId` next to `agentVersion`. `GET`/`POST /api/agents/{id}/builds` list (readers) and publish (modifiers only); a rollback re-pins the newest build of that version, or keeps the pin and says so. `overview()` reports unpublished changes when a versioned field differs between the build's version and the current one. PHPUnit: `AgentBuildServiceTest`, `WorkspaceSnapshotTest`, `AgentVersionControllerBuildsTest`, plus cases in `McpRunControllerWorkspaceScopeTest` and `ScheduleServiceTest`. Newman needs a live instance and was not run. Installed tools are recorded as an empty list until task 4 builds the installer.

### Task 7: The builds on the agent page
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001`
- **files**: `src/manifest.json` (agent detail), `src/components/AgentBuildsPanel.vue`, `src/modals/PublishBuildModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an agent owner WHEN they publish with a note THEN the panel lists the build with files, tools, author and date
  - GIVEN edits after the pinned build WHEN the page opens THEN it shows the unpublished changes note
- [x] Implement
- [ ] Test (Playwright under tests/e2e/spec-coverage/)
  > 2026-11-14: `AgentBuildsPanel.vue` is the `agent-builds` widget on AgentDetail (manifest widget, layout row and slot, registry entry): build number, publisher's name, date, note, file and tool counts, the pinned build marked, "Unpublished changes since build N", and "Publish build" only when the server says the caller may modify the agent. `PublishBuildModal.vue` (src/modals) offers the caller's own conversations with this agent and a note, and names the reason when the conversation has no workspace. en and nl strings, including the new register fields. Covered by Vitest (`agent-builds-panel.spec.js`); Playwright needs a live instance and was not run.
  > 2026-11-21: `tests/e2e/spec-coverage/agent-workspace-and-builds.spec.ts` written for the builds panel (build 3 pinned with its counts, the unpublished changes note, publishing with a note from a real conversation), with the build routes answered by page.route; not run here, it needs a running Nextcloud.

## Verification
- [x] `openspec validate tools-agent-workspace-and-installed-tools --type change --strict` passes
  - 2026-11-21: valid under `@fission-ai/openspec@1.2.0`, the version hydra's apply loop pins.
- [ ] PHPUnit, the ExApp tests, Newman and Playwright run once before push, exit codes read
- [ ] One live conversation that installs a tool, runs it on a workspace file, publishes a build, and a second user's new conversation starting from that build
