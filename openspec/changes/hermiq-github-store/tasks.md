# Tasks: hermiq-github-store

<!-- Code change. Unindented `- [ ]` count: 14 (7 tasks x Implement/Test) — under the Hydra cap of 20.
     Depends on hermiq-github-store-skill-schema being merged + re-imported first (ADR-032 chain). -->

## 1. Dependency pre-flight

### Task 1: Verify the Skill provenance fields are live before wiring the stamp
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path`
- **files**: `lib/Settings/hermiq_register.json` (read-only check)
- **acceptance_criteria**:
  - GIVEN the head change is merged WHEN the `agentskill` schema is inspected THEN `githubOwner`,
    `githubRepo`, and `publishedAt` are present as optional fields (register `info.version` >= 0.14.0).
  - GIVEN the fields are absent WHEN this change is applied THEN the builder halts (the chain order was
    violated) rather than writing to non-existent fields.
- [ ] Implement
- [ ] Test

## 2. Generalise the GitHub services

### Task 2: Generalise GitHubTemplateCatalogService to index both topics, kind-tagged
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos`
- **files**: `lib/Service/GitHubTemplateCatalogService.php`
- **acceptance_criteria**:
  - GIVEN a search WHEN it runs THEN it queries both `topic:hermiq-agent-template` and
    `topic:hermiq-skill`, tags each card with `kind`, and fetches the per-kind package file in
    `buildCard()`; the 200-always/never-5xx and rate-limit-degradation contract is unchanged.
  - GIVEN a kind filter WHEN supplied THEN results are restricted to that kind.
- [ ] Implement
- [ ] Test

### Task 3: Generalise GitHubTemplatePushService to push skills in agentskills.io format
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path`
- **files**: `lib/Service/GitHubTemplatePushService.php`
- **acceptance_criteria**:
  - GIVEN a skill package and kind `skill` WHEN `push()` runs THEN it creates the repo, tags it
    `topic:hermiq-skill`, and commits the agentskills.io package under the skill package filename.
  - GIVEN the broker is unavailable OR the repo exists OR coordinates are invalid THEN publish fails
    closed / refuses / rejects, and the token is never held or logged (template path unchanged).
- [ ] Implement
- [ ] Test

## 3. Skill GitHub endpoints

### Task 4: Add skill GitHub search + install endpoints on SkillController
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate`
- **files**: `lib/Controller/SkillController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a search request THEN it returns kind-tagged cards (mirrors `AgentTemplateController::githubSearch`).
  - GIVEN a skill install request THEN it fetches the package and calls
    `SkillMarketplaceService::installFromSource(source: 'hub')`, landing the skill quarantined + scanned;
    invalid coordinates return `400 invalid_repo` with no outbound call.
- [ ] Implement
- [ ] Test

### Task 5: Add skill GitHub publish endpoint on SkillMarketplaceController with provenance stamp
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path`
- **files**: `lib/Controller/SkillMarketplaceController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a publish request for a visible skill THEN it exports via `SkillService::exportSkill()`, calls
    generalised `push()`, then stamps `githubOwner`/`githubRepo`/`publishedAt` on the `Skill`
    (mirrors `AgentTemplateController::publishGithub()`); a skill outside visibility is 404; missing
    `credentialId` is 422; broker unavailable is 503.
  - GIVEN the committed package THEN it contains none of the three provenance fields.
- [ ] Implement
- [ ] Test

## 4. Unified Store UI

### Task 6: Replace AgentTemplateGallery with a unified Store page (per-kind filter)
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-a-single-unified-store-page-replaces-the-agent-templates-gallery`
- **files**: `src/manifest.json`, `src/registry.js`, `src/widgets/AgentTemplateGithubStore.vue`
- **acceptance_criteria**:
  - GIVEN the manifest THEN the `AgentTemplateGallery` page, `/agent-templates` route, and "Agent
    templates" menu item are removed and replaced by a "Store" page serving both kinds behind an
    accessible per-kind filter, reusing the store + row-action widgets (with a `kind` prop).
  - GIVEN the agent-detail action that linked to `AgentTemplateGallery` (`src/manifest.json:195`) THEN it
    is repointed to the Store route with no dead route.
- [ ] Implement
- [ ] Test

### Task 7: i18n + regression proof for retained agent-template behaviour
- **spec_ref**: `openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos`
- **files**: `l10n/en_US.js`, `l10n/nl_NL.js`, `tests/`
- **acceptance_criteria**:
  - GIVEN new Store strings THEN `nl_NL` + `en_US` translations exist (ADR-005).
  - GIVEN the existing agent-template search/install/publish scenarios THEN they still pass (the template
    `DISCOVERY_TOPIC`/`PACKAGE_FILE` path is regression-verified unchanged).
- [ ] Implement
- [ ] Test

## Quality checklist

- PHPUnit unit tests for the generalised services + new controller methods (`tests/Unit/`).
- Newman/Postman tests for the new skill GitHub search/install/publish endpoints.
- Playwright browser tests for the unified Store page (kind filter, install, publish dialog).
- Existing `agent-template-github-store` scenarios kept green (regression).
- Feature docs updated in `docs/` for the unified Store (ADR-010).
- Dutch + English strings added for all new Store labels (ADR-005).
- `openspec validate` passes; Hydra gates (manifest-validation, route-auth, spec-coverage,
  custom-widget-ratchet, effective-manifest-crossref) run clean.
