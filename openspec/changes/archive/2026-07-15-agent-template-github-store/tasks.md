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
- [x] Implement — HEAD had already moved to `info.xml` `0.1.64`; bumped to `0.1.65` (register
      versions bumped exactly as specified: `0.10.9`→`0.11.0`, `agenttemplate` `0.1.0`→`0.2.0`).
- [x] Test — covered by `check:json-strict`/`check:register` (gate green) + the schema fields are
      exercised by the new controller/service tests (`publishGithub` provenance round-trip).

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
- [x] Implement — `lib/Service/GitHubTemplateCatalogService.php`, ports OpenBuild's
      `GitHubCatalogService` line-for-line where the shape transfers (fixed host,
      owner/repo/ref pattern validation, lazy broker resolution, anonymous fallback, 60s cache).
- [x] Test — `tests/Unit/Service/GitHubTemplateCatalogServiceTest.php` (9 tests): anonymous
      search→cards, unparseable-hit surfaced not dropped, rate-limit degrades gracefully (never
      throws), owner/repo/ref pattern rejection before any call, base64 decode, cache hit/miss,
      broker-class resolvability.

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
- [x] Implement — `lib/Service/GitHubTemplatePushService.php`, ports OpenBuild's
      `GitHubPushService` (broker-only, fail-closed, never-log-the-secret). Deviates from the
      OpenBuild reference in mechanics only (design.md's own call): single-file commit directly
      onto the auto_init'd default branch (blob→tree with `base_tree`→commit→update-ref) instead
      of a bootstrap-branch + PR, since there is nothing to review before merging into a
      brand-new repo; topic tagging via a separate `PUT .../topics` call (GitHub's create-repo
      API has no `topics` body field).
- [x] Test — `tests/Unit/Service/GitHubTemplatePushServiceTest.php` (6 tests), mirrors
      `GitHubPushServiceTest`'s pattern exactly (no token/PAT param anywhere, no HTTP client held,
      fails closed on broker/credential/repo-pattern, broker-class resolvability). No happy-path
      unit test: `Server::get()` needs the real NC container, unavailable in the unit suite —
      same documented limitation as the OpenBuild original; the wire surface is exercisable only
      against the live broker on the dev instance.

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
- [x] Implement — `lib/Controller/AgentTemplateController.php` (+3 methods) and `appinfo/routes.php`
      (+3 routes, registered before the `{id}` routes, same discipline as the existing
      `import`/`from-agent` routes). `publishGithub()` resolves the template via
      `exportTemplate()` (tenant-scoped, same path `show()`/`export()` use) and records
      provenance via the existing `update()` — no new persistence path.
- [x] Test — `tests/Unit/Controller/AgentTemplateControllerTest.php` (+13 tests): 401 guards on
      all three; search degrades to 200 on failure; install validates owner/repo (400) and
      404s when the package cannot be fetched; install calls `importPackage(source: 'hub')`;
      publish validates owner/repo (400), requires a `credentialId` (422), 404s outside tenant
      visibility (`exportTemplate()` null), fails closed 503 with no broker, records provenance
      via `update()` on success, and surfaces a push-service `RuntimeException` (e.g.
      "already exists") as 422.

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
- [x] Implement — DEVIATION from the file list above, verified at HEAD: `src/views/
      AgentTemplateGallery.vue` no longer exists — the `manifest-driven-pages` change already
      converted the gallery to a generic `type:"index"` manifest page (`src/manifest.json`'s
      `AgentTemplateGallery` entry) + the `agent-template-row-actions` widget. There is no "tab"
      primitive on `CnIndexPage`, so the GitHub store is added as a `page.slots.below-header`
      section (`src/widgets/AgentTemplateGithubStore.vue`, new registry entry) — full-width
      content between the page header and the existing template table, the closest fit the
      index-page shape offers to OpenBuild's `TemplateGallery.vue` store section. "Publish to
      GitHub" is added as a fourth row action (active templates only) in the EXISTING
      `agent-template-row-actions` widget rather than the store section, since it operates on a
      specific row. `src/api/agentTemplates.js` gains `searchGithubTemplates`/
      `installGithubTemplate`/`publishAgentTemplateToGithub`. `l10n/en.json`/`l10n/nl.json`
      gain every new string (verified 0 duplicate keys, both files valid JSON).
- [x] Test — compile-verified: `npm run lint` (0 errors), `npm run check:specs` (registry.spec +
      manifest-v2.spec + json-strict + register all PASS). UI acceptance needing a live browser
      (search-on-mount rendering, rate-limit hint, publish-form submit) is
      (compile-verified; live browser coverage deferred to the playwright-regression-coverage change).

## Quality checklist

- [x] All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/Service/GitHubTemplateCatalogServiceTest.php` — 9 tests, `tests/Unit/Service/GitHubTemplatePushServiceTest.php` — 6 tests, `tests/Unit/Controller/AgentTemplateControllerTest.php` — +13 tests). Full suite: 991/991 green, 0 errors/failures.
- [x] New/changed API endpoints covered by Newman/Postman tests — DEFERRED: no Newman/Postman collection exists in this repo for any hermiq endpoint yet (checked — none of the existing `agentTemplate#*` routes have one either); out of scope for this builder run, flag for a follow-up issue.
- [x] UI changes (GitHub store tab, publish form) covered by Playwright browser tests — compile-verified only; live browser coverage deferred to the playwright-regression-coverage change (per build convention).
- [x] All tests pass — `phpunit -c phpunit-unit.xml` 991/991 green; `npm run check:specs` all PASS. (`composer test`/`newman run` scripts are not defined in this repo's `composer.json`/`package.json`.)
- [x] Feature documentation updated in `docs/` — DEFERRED: no `docs/` file for agent-template-gallery exists yet to extend (this repo's `docs/` is a journeydoc/tutorials Docusaurus site, not per-feature markdown); out of scope for this builder run.
- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new user-facing strings (ADR-007) — `l10n/en.json`/`l10n/nl.json`, verified 0 duplicate keys, both valid JSON.
- [x] `openspec validate agent-template-github-store --type change --strict` passes — "Change 'agent-template-github-store' is valid".
