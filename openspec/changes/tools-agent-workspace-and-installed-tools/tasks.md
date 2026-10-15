# Tasks: tools-agent-workspace-and-installed-tools

Kind: code. Size L. Rows `hermiq:dm-agent-workspace`, `hermiq:dm-install-cli-tool`, `hermiq:dm-build-snapshot`.

## Implementation tasks

### Task 1: The session lifetime for the governed workspace
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001`
- **files**: `lib/Settings/hermiq_register.json` (Agent.workspaceMode), the workspace provider from `hermiq-runner-git-capability`, `lib/BackgroundJob/WorkspaceExpiryJob.php`
- **acceptance_criteria**:
  - GIVEN workspaceMode session WHEN two runs share a conversation THEN they reach one workspace, and a run of another conversation, user or agent does not
  - GIVEN a conversation deleted or idle for 14 days WHEN the job runs THEN its workspace is gone
- [ ] Implement
- [ ] Test (PHPUnit on keying, isolation and expiry)

### Task 2: Copy in and write back around sandbox runs
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002`
- **files**: `lib/Service/CodeSandbox/RunCodeTool.php`, `lib/Service/CodeSandbox/WorkspaceTransfer.php`
- **acceptance_criteria**:
  - GIVEN a run that changes one file WHEN it ends THEN only that file is written back, confined to the workspace
  - GIVEN a workspace over the input cap WHEN a run is asked THEN it is refused with the limit named
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed sandbox, including a returned path that tries to leave the workspace)

### Task 3: The Files panel and saving to Files
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003`
- **files**: `lib/Controller/WorkspaceFilesController.php`, `appinfo/routes.php`, `src/views/Chat.vue`, `src/components/WorkspaceFilesPanel.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the session user WHEN they save a file THEN it lands in their Files with the agent-authored tag, and a failed tag fails the save
  - GIVEN another user WHEN they call the routes for this workspace THEN they are refused
- [ ] Implement
- [ ] Test (Newman on the routes including another user; Playwright under tests/e2e/spec-coverage/ on the panel)

### Task 4: The installer and installTool
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-installs-command-line-tools-only-from-an-allowlisted-registry-without-install-scripts-req-wksp-004`
- **files**: `exapp/code-sandbox/src/installer.js`, `exapp/code-sandbox/deploy/docker-compose.yml`, `exapp/code-sandbox/deploy/installer-egress-proxy`, `lib/Mcp/CodeSandboxToolDescriptors.php`, `lib/Service/CodeSandbox/InstallToolService.php`
- **acceptance_criteria**:
  - GIVEN an allowlisted registry WHEN a package is installed THEN no install script runs and a layer with hash and versions is stored
  - GIVEN a registry or package outside the allowlists WHEN install is asked THEN the installer is not called
  - GIVEN a code run WHEN it tries the installer's network THEN it has no route
- [ ] Implement
- [ ] Test (the ExApp tests for scripts, egress and isolation; PHPUnit on the tool)

### Task 5: runInstalledTool
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-installed-tool-runs-with-an-argument-list-and-no-shell-req-wksp-005`
- **files**: `exapp/code-sandbox/src/run.js`, `lib/Service/CodeSandbox/RunInstalledToolService.php`, `lib/Mcp/CodeSandboxToolDescriptors.php`
- **acceptance_criteria**:
  - GIVEN an argument with shell syntax WHEN the tool runs THEN it reaches the binary as one argument and nothing else runs
  - GIVEN a layer hash the sandbox lacks WHEN it runs THEN hermiq sends the bytes and the sandbox verifies the hash
- [ ] Implement
- [ ] Test (the ExApp tests; PHPUnit on the dispatch)

### Task 6: AgentBuild, publishing and runs from the build
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-runs-of-a-published-agent-start-from-its-pinned-build-req-build-002`
- **files**: `lib/Settings/hermiq_register.json` (AgentBuild, Agent.pinnedBuildId), `lib/Service/AgentBuildService.php`, `lib/Service/AgentVersionService.php` (rollback re-pin), `lib/Service/ScheduleService.php` (buildId on the run), `lib/Controller/AgentVersionController.php`
- **acceptance_criteria**:
  - GIVEN publish WHEN it completes THEN the build holds the version id, the archive hash and the tools, and is pinned
  - GIVEN a pinned build WHEN a new conversation starts THEN its workspace starts from the build and run changes never reach the build
  - GIVEN a rollback to a version with a build WHEN it completes THEN that build is pinned
- [ ] Implement
- [ ] Test (PHPUnit on publish, start, rollback; Newman on the publish route)

### Task 7: The builds on the agent page
- **spec_ref**: `openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001`
- **files**: `src/manifest.json` (agent detail), `src/components/AgentBuildsPanel.vue`, `src/modals/PublishBuildModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an agent owner WHEN they publish with a note THEN the panel lists the build with files, tools, author and date
  - GIVEN edits after the pinned build WHEN the page opens THEN it shows the unpublished changes note
- [ ] Implement
- [ ] Test (Playwright under tests/e2e/spec-coverage/)

## Verification
- [ ] `openspec validate tools-agent-workspace-and-installed-tools --type change --strict` passes
- [ ] PHPUnit, the ExApp tests, Newman and Playwright run once before push, exit codes read
- [ ] One live conversation that installs a tool, runs it on a workspace file, publishes a build, and a second user's new conversation starting from that build
