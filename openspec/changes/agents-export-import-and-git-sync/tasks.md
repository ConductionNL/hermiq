# Tasks: agents-export-import-and-git-sync

Kind: code. Size M. Rows `hermiq:ag-import-export`, `dm-agent-as-code`, `re-template-from-agent`.

## Implementation tasks

### Task 1: Export and save as template on the agent page
- **spec_ref**: `openspec/changes/agents-export-import-and-git-sync/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-exported-to-a-file-from-its-page-req-agexp-001`
- **files**: `src/manifest.json` (AgentDetail header actions), `src/api/agentTemplates.js`, `lib/Controller/AgentTemplateController.php`, `appinfo/routes.php`, `lib/Settings/hermiq_register.json` (`derivedFrom`), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a reader WHEN they export THEN the file holds no user, group, quota or credential field
  - GIVEN the owner WHEN they save as template THEN an active local template with `derivedFrom` exists; a non-owner gets 403
- [x] Implement (part A: `AgentExportModal.vue` opened by the AgentDetail header actions "Export" and "Save as template", `POST /api/agent-templates/from-agent/{agentId}` (`AgentTemplateController::saveFromAgent`, `AgentTemplateService::saveAgentAsTemplate`), `AgentTemplate.derivedFrom`, register 0.45.0)
- [x] Test (PHPUnit: `AgentTemplateControllerTest::testSaveFromAgent*` for 401, 404, 403 and 201; `tests/Unit/Service/AgentTemplateSaveFromAgentTest.php` for the package fields, `derivedFrom` and the real AgentTemplate fragment with a negative control; the file name in `tests/agent-export.spec.js`. Playwright not run in the lane: no live instance, the PR's live-check recipe covers both actions)

### Task 2: Import agent from a file
- **spec_ref**: `openspec/changes/agents-export-import-and-git-sync/specs/agent-template-gallery/spec.md#requirement-an-exported-agent-is-imported-through-review-req-agexp-002`
- **files**: `src/modals/TemplateImportModal.vue`, `src/manifest.json` (AgentCatalog header action)
- **acceptance_criteria**:
  - GIVEN an uploaded file WHEN imported THEN it is a quarantined template with a scan report
- [x] Implement (part A: the catalog header action "Import agent" opens `TemplateImportModal.vue` in agent mode, with a file input; a file import uses source `org`, so it lands quarantined and scanned)
- [x] Test (`tests/agent-export.spec.js` for the file check; the quarantine of an `org` import is `AgentTemplateServiceTest::testImportPackage*`. Playwright not run in the lane: the live-check recipe covers export, import, approve, use)

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
