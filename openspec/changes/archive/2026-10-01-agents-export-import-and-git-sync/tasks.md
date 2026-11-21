# Tasks: agents-export-import-and-git-sync

Kind: code. Size M. Rows `hermiq:ag-import-export`, `dm-agent-as-code`, `re-template-from-agent`.

## Implementation tasks

### Task 1: Export and save as template on the agent page
- **spec_ref**: `openspec/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-exported-to-a-file-from-its-page-req-agexp-001`
- **files**: `src/manifest.json` (AgentDetail header actions), `src/api/agentTemplates.js`, `lib/Controller/AgentTemplateController.php`, `appinfo/routes.php`, `lib/Settings/hermiq_register.json` (`derivedFrom`), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a reader WHEN they export THEN the file holds no user, group, quota or credential field
  - GIVEN the owner WHEN they save as template THEN an active local template with `derivedFrom` exists; a non-owner gets 403
- [x] Implement (part A: `AgentExportModal.vue` opened by the AgentDetail header actions "Export" and "Save as template", `POST /api/agent-templates/from-agent/{agentId}` (`AgentTemplateController::saveFromAgent`, `AgentTemplateService::saveAgentAsTemplate`), `AgentTemplate.derivedFrom`, register 0.45.0)
- [x] Test (PHPUnit: `AgentTemplateControllerTest::testSaveFromAgent*` for 401, 404, 403 and 201; `tests/Unit/Service/AgentTemplateSaveFromAgentTest.php` for the package fields, `derivedFrom` and the real AgentTemplate fragment with a negative control; the file name in `tests/agent-export.spec.js`. Playwright not run in the lane: no live instance, the PR's live-check recipe covers both actions)

### Task 2: Import agent from a file
- **spec_ref**: `openspec/specs/agent-template-gallery/spec.md#requirement-an-exported-agent-is-imported-through-review-req-agexp-002`
- **files**: `src/modals/TemplateImportModal.vue`, `src/manifest.json` (AgentCatalog header action)
- **acceptance_criteria**:
  - GIVEN an uploaded file WHEN imported THEN it is a quarantined template with a scan report
- [x] Implement (part A: the catalog header action "Import agent" opens `TemplateImportModal.vue` in agent mode, with a file input; a file import uses source `org`, so it lands quarantined and scanned)
- [x] Test (`tests/agent-export.spec.js` for the file check; the quarantine of an `org` import is `AgentTemplateServiceTest::testImportPackage*`. Playwright not run in the lane: the live-check recipe covers export, import, approve, use)

### Task 3: Git coordinates, publish and push
- **spec_ref**: `openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Controller/AgentGitController.php`, `appinfo/routes.php`, `lib/Service/GitHubTemplatePushService.php`
- **acceptance_criteria**:
  - GIVEN a published agent WHEN a push names other coordinates THEN the stamped repository is used or 400
- [x] Implement (part B: `AgentGitService::publish()` and `push()`, `AgentGitController` (`POST /api/agents/{id}/git/publish`, `POST /api/agents/{id}/git/push`), Agent `gitOwner`, `gitRepo`, `gitRef`, `gitLastPulledHash` with an admin-only update rule, register 0.46.0. `store-through-federated-config` has not landed (still open on 1 Oct), so the existing `GitHubTemplatePushService` is the engine)
- [x] Test (PHPUnit with the GitHub clients stubbed: `tests/Unit/Service/Agent/AgentGitServiceTest.php` (publish stamps, a second publish is 409, only the owner, 404 for an unreadable agent, a push goes to the stamp, no stamp is 409; the git properties validated against the real Agent fragment with a negative control) and `tests/Unit/Controller/AgentGitControllerTest.php` (request coordinates ignored, every refusal keeps its status))

### Task 4: Pull from git with scan and diff
- **spec_ref**: `openspec/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005`
- **files**: `lib/Service/AgentGitSyncService.php`, `lib/Service/GitHubTemplateCatalogService.php`, `src/modals/AgentGitPullModal.vue`
- **acceptance_criteria**:
  - GIVEN a changed prompt in the repository WHEN pulled and confirmed THEN one new agent version and `gitLastPulledSha` set
  - GIVEN a dangerous verdict WHEN pulled THEN refused and unchanged
- [x] Implement (part B: `AgentGitService::pullPreview()` and `pullApply()`, `GET` and `POST /api/agents/{id}/git/pull`)
- [x] Test (PHPUnit for scan, diff and field allowlist in `AgentGitServiceTest`: the preview lists only changed fields and writes nothing, a confirmed pull writes the package fields and keeps sharing and credentials, a dangerous verdict is 422 with the scan reason and writes nothing. The live check against a scratch repository is the PR's recipe: no GitHub credential in the lane)

### Task 5: Keep in git on the agent page
- **spec_ref**: `openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004`
- **files**: `src/modals/AgentGitModal.vue`, `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the owner WHEN they open "Keep in git" THEN publish, push and pull are offered according to whether a repository is stamped
- [x] Implement (part B: `src/modals/AgentGitModal.vue`, the AgentDetail header action "Keep in git", `src/utils/agentGit.js`)
- [x] Test (`tests/agent-git.spec.js` for which actions are offered and the repository link. Playwright not run in the lane: no live instance; the PR's live-check recipe covers the modal)

## Verification
- [x] `openspec validate agents-export-import-and-git-sync --type change --strict` passes (1 Oct, lane 16)
- [ ] PHPUnit and Playwright run, exit codes read (PHPUnit full suite in the lane; Playwright not run: no live instance in the lane, the PR live-check recipes cover the screens)
