# Tasks: agent-template-github-store

## Implementation Tasks

### Task 1: Bump the AgentTemplate schema + register version, add GitHub provenance fields
- **spec_ref**: `openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-record-github-publish-provenance-without-leaking-it-into-packages`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the `agenttemplate` schema WHEN it is loaded THEN it declares `githubOwner` (string),
    `githubRepo` (string), and `publishedAt` (date-time) properties
  - GIVEN the schema change WHEN reviewing the register file THEN `info.version` (0.10.9→0.11.0)
    and the `agenttemplate` schema's own `version` (0.1.0→0.2.0) are both bumped, and
    `appinfo/info.xml`'s `<version>` is bumped (0.1.59→0.1.60)
- [ ] Implement
- [ ] Test

### Task 2: GitHubTemplateCatalogService (search + fetch, broker-optional, anonymous fallback)
- **spec_ref**: `openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos`
- **files**: `lib/Service/GitHubTemplateCatalogService.php`
- **acceptance_criteria**:
  - GIVEN no credential WHEN `search()` is called THEN GitHub is queried anonymously for
    `topic:hermiq-agent-template` (+ optional term) and cards are returned
  - GIVEN a rate-limited/unreachable response WHEN `search()` is called THEN it returns
    `outcome`/`rateLimited` without throwing, and results are cached 60s
  - GIVEN an owner/repo/ref WHEN `fetchTemplateFile()` is called THEN they are pattern-validated
    before any path interpolation and a broker credential (if supplied) upgrades the call
- [ ] Implement
- [ ] Test

### Task 3: GitHubTemplatePushService (create-repo, blob/tree/commit push, broker-only)
- **spec_ref**: `openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token`
- **files**: `lib/Service/GitHubTemplatePushService.php`
- **acceptance_criteria**:
  - GIVEN no broker credential WHEN `push()` is called THEN it throws without any outbound call
  - GIVEN an existing target repo WHEN `push()` is called THEN it refuses with an "already exists"
    error and creates nothing
  - GIVEN a valid credential + absent repo WHEN `push()` is called THEN it creates the repo,
    commits the package as a single blob, and returns `{repoUrl, commitSha}`
  - GIVEN any broker call failure WHEN it is logged THEN only method + path appear, never body/token
- [ ] Implement
- [ ] Test

### Task 4: AgentTemplateController — githubSearch, githubInstall, publishGithub
- **spec_ref**: `openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate`
- **files**: `lib/Controller/AgentTemplateController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `GET /api/agent-templates/github/search` WHEN called authenticated THEN it delegates to
    `GitHubTemplateCatalogService::search()` and shapes the response per design.md
  - GIVEN `POST /api/agent-templates/github/install` WHEN called with a valid card THEN the
    fetched package is passed to the UNCHANGED `AgentTemplateService::importPackage(source: 'hub')`
  - GIVEN `POST /api/agent-templates/{id}/publish-github` WHEN called for a template outside the
    caller's organisation THEN it responds 404 (tenant-scoped via `AgentTemplateService::get()`)
  - GIVEN a successful publish WHEN the template is re-read THEN `githubOwner`/`githubRepo`/
    `publishedAt` are populated
- [ ] Implement
- [ ] Test

### Task 5: Frontend — src/api/agentTemplates.js + AgentTemplateGallery.vue GitHub store tab
- **spec_ref**: `openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable`
- **files**: `src/api/agentTemplates.js`, `src/views/AgentTemplateGallery.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the gallery page WHEN the user opens the "GitHub store" tab THEN it searches on mount
    and renders result cards (name/description/owner-repo/version/stars)
  - GIVEN a rate-limited/unreachable search WHEN it happens THEN an `NcNoteCard` hint appears,
    offering to add a GitHub credential when none is configured (mirrors OpenBuild's hint copy)
  - GIVEN a local template row WHEN the user clicks "Publish to GitHub" THEN a form collects
    owner/repo/visibility/credential and calls the new publish endpoint
  - GIVEN any new user-facing string WHEN added THEN it exists in both `l10n/en.json` and
    `l10n/nl.json` under an English key
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/Service/GitHubTemplateCatalogServiceTest.php`, `tests/Unit/Service/GitHubTemplatePushServiceTest.php`, `tests/Unit/Controller/AgentTemplateControllerTest.php`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes (GitHub store tab, publish form) covered by Playwright browser tests
- All tests pass (`composer test`, `npm run check:specs`, `newman run`)
- Feature documentation updated in `docs/` (agent-template-gallery doc gains a GitHub-store section)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new user-facing strings (ADR-007)
- `openspec validate agent-template-github-store --type change --strict` passes
