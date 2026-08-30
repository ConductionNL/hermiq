# Tasks: hermiq-mcp-adoption

## Implementation Tasks

### Task 1: Verify `Agent.configuration` holds no credential material, then declare the dialect
- **spec_ref**: `openspec/changes/hermiq-mcp-adoption/specs/hermiq-mcp-adoption/spec.md#requirement-declarative-read-only-tool-surface-on-a-curated-schema-subset`
- **files**: `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN `Agent.configuration` is a free-form blob WHEN its live contents are inspected post-`hermiq#43` THEN it MUST contain no provider API keys; if it does, `Agent` drops to OFF and only `Schedule` + `Session` are declared
  - GIVEN the dialect is declared on `Agent`, `Schedule`, `Session` WHEN the register is imported THEN `McpAnnotationValidator` MUST accept it, with every `search.filters` entry naming a real property
  - GIVEN the declarations WHEN the catalog is built THEN only `search` and `get` verbs MUST appear, each with `scope: 'read'` and `readOnlyHint: true`
- [ ] Implement
- [ ] Test

### Task 2: Create `NcNativeToolService` and move the six NC-native tool bodies verbatim
- **spec_ref**: `openspec/changes/hermiq-mcp-adoption/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider`
- **files**: `lib/Service/NcNativeToolService.php`
- **acceptance_criteria**:
  - GIVEN the six bodies move from `HermiqToolProvider` WHEN they run THEN the IDOR guards, the size cap, and the structured error envelopes MUST be byte-for-byte equivalent in behaviour
  - GIVEN `readFile` is invoked for user U WHEN a path escaping U's user folder is supplied THEN it MUST be denied
- [ ] Implement
- [ ] Test

### Task 3: Annotate the six NC-native methods with honest `#[McpTool]` hints
- **spec_ref**: `openspec/changes/hermiq-mcp-adoption/specs/nc-native-tools/spec.md#requirement-every-curated-tool-declares-honest-hints-and-scope`
- **files**: `lib/Service/NcNativeToolService.php`
- **acceptance_criteria**:
  - GIVEN `listFiles`, `readFile`, `searchContacts`, `listCalendarEvents`, `listDeckBoards` WHEN scanned THEN each MUST declare `readOnlyHint: true`, `destructiveHint: false`, `idempotentHint: true`, `scope: 'read'`
  - GIVEN `sendMail` WHEN scanned THEN it MUST declare `destructiveHint: true`, `idempotentHint: false`, `scope: 'create'`
  - GIVEN `ToolGrantResolver` classifies `hermiq.listFiles` WHEN hints are present THEN it MUST NOT fall through to the fail-closed branch
- [ ] Implement
- [ ] Test

### Task 4: Annotate `CourseRecommendationEngine` and `ToolSearchService`, preserving both tool ids
- **spec_ref**: `openspec/changes/hermiq-mcp-adoption/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools`
- **files**: `lib/Service/CourseRecommendationEngine.php`, `lib/Service/ToolSearchService.php`
- **acceptance_criteria**:
  - GIVEN `ToolSearchService::search()` is annotated `#[McpTool(name: 'searchTools')]` WHEN the scanner builds the descriptor THEN the id MUST be exactly `hermiq.searchTools` and `FacadeToolInvoker`'s short-circuit MUST still match it
  - GIVEN `CourseRecommendationEngine::getOrRegenerate()` persists on staleness WHEN annotated THEN it MUST declare `readOnlyHint: false`, `scope: 'update'`, and the id MUST be exactly `hermiq.recommendCourses`
- [ ] Implement
- [ ] Test

### Task 5: Add the `IMcpScannableServices` opt-in and delete `HermiqToolProvider`
- **spec_ref**: `openspec/changes/hermiq-mcp-adoption/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools`
- **files**: `lib/Mcp/HermiqScannableServices.php`, `lib/AppInfo/Application.php`, `lib/Mcp/HermiqToolProvider.php`
- **acceptance_criteria**:
  - GIVEN the three services are listed by `HermiqScannableServices` WHEN registered under the `IMcpScannableServices::hermiq` alias THEN all eight tools MUST enumerate through `AttributeToolProvider`
  - GIVEN the provider now holds zero tools WHEN the change lands THEN `lib/Mcp/HermiqToolProvider.php` MUST be deleted and the `IMcpToolProvider::hermiq` alias removed
- [ ] Implement
- [ ] Test

### Task 6: Retarget the provider's unit tests at the new services
- **spec_ref**: `openspec/changes/hermiq-mcp-adoption/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider`
- **files**: `tests/Unit/Mcp/`, `tests/Unit/Service/NcNativeToolServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the existing `HermiqToolProvider` tests WHEN the provider is deleted THEN their IDOR, error-envelope and size-cap assertions MUST be preserved against `NcNativeToolService`
  - GIVEN the suite runs the CI way WHEN measured against a baseline taken first THEN there MUST be zero new failures
- [ ] Implement
- [ ] Test

### Task 7: Withdraw the superseded `hermiq-domain-mcp-tools` change and update the CHANGELOG
- **spec_ref**: `openspec/changes/hermiq-mcp-adoption/specs/hermiq-mcp-adoption/spec.md#requirement-the-agent-governance-objects-are-off-the-tool-surface-entirely`
- **files**: `openspec/changes/hermiq-domain-mcp-tools/`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN `hermiq-domain-mcp-tools` is 0/13 and proposes `hermiq.listPendingApprovals` + `hermiq.runAgentNow` WHEN this change lands THEN it MUST be withdrawn with a recorded reason referencing ADR-063 and REQ-003
  - GIVEN the CHANGELOG WHEN the change lands THEN it MUST record the provider deletion and the read-only surface
- [ ] Implement
- [ ] Test

## Verification

- Register imports cleanly; `openspec validate hermiq-mcp-adoption --type change --strict` passes.
- The `hermiq` catalog contains six derived read tools and eight attributed tools; no `.create` / `.update` / `.delete` id; no governance schema under any verb.
- A read-only agent can call `hermiq.listFiles` without an explicit grant; `hermiq.sendMail` still requires one.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- PHP verified the CI way in a container, against a baseline measured first — zero new failures
- Scoped PHPCS clean on every touched `lib/` file; `python3 -m json.tool` after every JSON edit
- `@spec` tags point at `openspec/specs/...`, never an archived change path (gate-46)
- No new user-facing strings — i18n N/A
- `openspec validate` passes
