# Tasks: agents-export-import-and-git-sync

Kind: code. Size M. Rows `hermiq:ag-import-export`, `dm-agent-as-code`, `re-template-from-agent`.

## Implementation tasks

### Task 1: Export and save as template on the agent page
- **spec_ref**: `openspec/changes/agents-export-import-and-git-sync/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-exported-to-a-file-from-its-page-req-agexp-001`
- **files**: `src/manifest.json` (AgentDetail header actions), `src/api/agentTemplates.js`, `lib/Controller/AgentTemplateController.php`, `appinfo/routes.php`, `lib/Settings/hermiq_register.json` (`derivedFrom`), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a reader WHEN they export THEN the file holds no user, group, quota or credential field
  - GIVEN the owner WHEN they save as template THEN an active local template with `derivedFrom` exists; a non-owner gets 403
- [ ] Implement
- [ ] Test (PHPUnit on the save route guard; Playwright for both actions)

### Task 2: Import agent from a file
- **spec_ref**: `openspec/changes/agents-export-import-and-git-sync/specs/agent-template-gallery/spec.md#requirement-an-exported-agent-is-imported-through-review-req-agexp-002`
- **files**: `src/modals/TemplateImportModal.vue`, `src/manifest.json` (AgentCatalog header action)
- **acceptance_criteria**:
  - GIVEN an uploaded file WHEN imported THEN it is a quarantined template with a scan report
- [ ] Implement
- [ ] Test (Playwright: export on one agent, import, approve, use)

### Task 3: Git coordinates, publish and push
- **spec_ref**: `openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Controller/AgentGitController.php`, `appinfo/routes.php`, `lib/Service/GitHubTemplatePushService.php`
- **acceptance_criteria**:
  - GIVEN a published agent WHEN a push names other coordinates THEN the stamped repository is used or 400
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed GitHub client; check first whether `store-through-federated-config` has landed and use its engine if so, noted in the PR)

### Task 4: Pull from git with scan and diff
- **spec_ref**: `openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005`
- **files**: `lib/Service/AgentGitSyncService.php`, `lib/Service/GitHubTemplateCatalogService.php`, `src/modals/AgentGitPullModal.vue`
- **acceptance_criteria**:
  - GIVEN a changed prompt in the repository WHEN pulled and confirmed THEN one new agent version and `gitLastPulledSha` set
  - GIVEN a dangerous verdict WHEN pulled THEN refused and unchanged
- [ ] Implement
- [ ] Test (PHPUnit for scan, diff and field allowlist; a live check against a scratch repository, commit sha in the PR)

### Task 5: Keep in git on the agent page
- **spec_ref**: `openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004`
- **files**: `src/modals/AgentGitModal.vue`, `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the owner WHEN they open "Keep in git" THEN publish, push and pull are offered according to whether a repository is stamped
- [ ] Implement
- [ ] Test (Playwright with the GitHub calls mocked at the route)

## Verification
- [ ] `openspec validate agents-export-import-and-git-sync --type change --strict` passes
- [ ] PHPUnit and Playwright run, exit codes read
