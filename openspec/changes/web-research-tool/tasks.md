# Tasks: web-research-tool

## Implementation Tasks

### Task 1: WebResearchSettingsHandler + `hermiq.webResearch` config shape
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend`
- **files**: `lib/Service/WebResearch/WebResearchSettingsHandler.php`, `tests/Unit/Service/WebResearch/WebResearchSettingsHandlerTest.php`
- **acceptance_criteria**:
  - GIVEN no `hermiq.webResearch` config exists WHEN it is read THEN default values are returned (`searchProvider=""`, empty allowlist/denylist, `maxResponseBytes=500000`, `timeoutSeconds=10`, `allowInsecureHttp=false`)
  - GIVEN a partial patch (e.g. only `fetchAllowlist`) WHEN it is applied THEN other existing fields (incl. `searchCredentialId`) are preserved, mirroring `LlmSettingsHandler`'s merge behavior
- [ ] Implement
- [ ] Test

### Task 2: WebResearchSettingsController + routes
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-paid-search-api-credentials-come-from-the-credential-broker`
- **files**: `lib/Controller/Settings/WebResearchSettingsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a non-admin WHEN they call `GET /api/settings/web-research` THEN the request is rejected per `#[AuthorizedAdminSetting]`
  - GIVEN an admin `PATCH`es with a blank `searchCredentialId` WHEN the existing config already has one set THEN the existing credential id is NOT cleared
  - GIVEN an admin reads the config WHEN a credential is configured THEN the response exposes `searchCredentialConfigured: true` and never the raw credential id or key
- [ ] Implement
- [ ] Test

### Task 3: WebResearchEgressGuard (SSRF + allowlist/denylist)
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-egress-guard-blocks-ssrf-shaped-destinations-for-webfetch`
- **files**: `lib/Service/WebResearch/WebResearchEgressGuard.php`, `tests/Unit/Service/WebResearch/WebResearchEgressGuardTest.php`
- **acceptance_criteria**:
  - GIVEN a `web.fetch` URL whose host resolves to an RFC 1918 address WHEN validated THEN it is rejected
  - GIVEN a `web.fetch` URL whose host resolves to `169.254.169.254` WHEN validated THEN it is rejected even if allowlisted
  - GIVEN a non-empty `fetchAllowlist` and a target not on it WHEN validated THEN it is rejected
  - GIVEN the admin-configured `searchEndpoint` on a private address WHEN validated as a search-endpoint call (not a fetch target) THEN it is NOT rejected for being private
- [ ] Implement
- [ ] Test

### Task 4: WebSearchClient (SearXNG + generic-json)
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend`
- **files**: `lib/Service/WebResearch/WebSearchClient.php`, `tests/Unit/Service/WebResearch/WebSearchClientTest.php`
- **acceptance_criteria**:
  - GIVEN `searchProvider=""` WHEN `web.search` is invoked THEN a structured `search_unavailable` error is returned and no HTTP call is made
  - GIVEN `searchProvider=searxng` and a configured endpoint WHEN invoked THEN results are parsed from SearXNG's native JSON shape into `{title, url, snippet}`
  - GIVEN `searchProvider=generic-json` with a `searchFieldMapping` WHEN invoked THEN results are parsed using that mapping
  - GIVEN a `searchCredentialId` is configured WHEN invoked THEN the call is routed through `BrokerHttpClient`
- [ ] Implement
- [ ] Test

### Task 5: WebFetchService + ReadableTextExtractor
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-webfetch-extracts-readable-text-with-a-content-type-gate`
- **files**: `lib/Service/WebResearch/WebFetchService.php`, `lib/Service/WebResearch/ReadableTextExtractor.php`, `tests/Unit/Service/WebResearch/WebFetchServiceTest.php`, `tests/Unit/Service/WebResearch/ReadableTextExtractorTest.php`
- **acceptance_criteria**:
  - GIVEN a `text/html` response WHEN fetched THEN script/style/nav markup is stripped and readable text is returned wrapped in the untrusted-content markers
  - GIVEN a non-text `Content-Type` (e.g. `application/pdf`) WHEN fetched THEN a structured `unsupported_content_type` error is returned with no extraction attempted
  - GIVEN a response body larger than `maxResponseBytes` WHEN processed THEN the result is truncated with `truncated: true`
  - GIVEN a 3xx response WHEN processed THEN the redirect is NOT followed and a structured error names the redirect target
- [ ] Implement
- [ ] Test

### Task 6: Register `hermiq.webSearch`/`hermiq.webFetch` on HermiqToolProvider
- **spec_ref**: `openspec/changes/web-research-tool/specs/nc-native-tools/spec.md#requirement-remote-systems-route-through-openconnector`
- **files**: `lib/Mcp/HermiqToolProvider.php`, `tests/Unit/Mcp/HermiqToolProviderTest.php`
- **acceptance_criteria**:
  - GIVEN OR's `ToolRegistry` lists tools WHEN queried THEN `hermiq.webSearch` and `hermiq.webFetch` appear with correct input schemas
  - GIVEN either tool call throws internally WHEN `invokeTool()` handles it THEN a structured error envelope is returned, never an exception
- [ ] Implement
- [ ] Test

### Task 7: Trace/audit target extension
- **spec_ref**: `openspec/changes/web-research-tool/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp`
- **files**: `lib/Service/Engine/RunTraceCollector.php`, `lib/Service/Engine/FacadeToolInvoker.php`, `tests/Unit/Service/Engine/RunTraceCollectorTest.php`, `tests/Unit/Service/Engine/FacadeToolInvokerTest.php`
- **acceptance_criteria**:
  - GIVEN an existing caller of `endStep()` that omits the new parameter WHEN it runs THEN behavior is unchanged (no `target` key in the resulting step)
  - GIVEN a `hermiq.webFetch` call with a URL containing a query string WHEN traced THEN the recorded target is host+path only, no query string
- [ ] Implement
- [ ] Test

### Task 8: Admin settings UI for the web-research backend
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#non-functional-requirements`
- **files**: `src/api/webResearch.js`, `src/modals/WebResearchSettingsModal.vue`
- **acceptance_criteria**:
  - GIVEN an admin opens the web-research settings modal WHEN no backend is configured THEN the UI clearly shows the tool as unconfigured/unavailable
  - GIVEN an admin edits the allowlist/denylist fields WHEN saved THEN the PATCH payload matches the config shape in design.md
- [ ] Implement
- [ ] Test

### Task 9: l10n strings
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#non-functional-requirements`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the new admin settings UI strings WHEN the app is loaded in `nl_NL` THEN every new string has a Dutch translation (no fallback-to-English key leakage)
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints (`GET`/`PATCH /api/settings/web-research`) covered by Newman/Postman tests
- Admin settings UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
