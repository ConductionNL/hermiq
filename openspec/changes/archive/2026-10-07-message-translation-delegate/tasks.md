# Tasks: message-translation-delegate

## Implementation Tasks

### Task 1: Seed the message-translation AiFeature (disabled, pending DPO acknowledgement)
- **spec_ref**: `openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature`
- **files**: `lib/Repair/SeedMessageTranslationFeature.php`
- **acceptance_criteria**:
  - GIVEN a fresh install or upgrade WHEN the repair step runs THEN a `message-translation` `agentaifeature` object is created with `lifecycle: disabled`, `riskCategory: limited`
  - GIVEN the object already exists WHEN the repair step runs again THEN it is skipped, not duplicated
- [x] Implement
- [x] Test

### Task 2: Build the gated translation engine
- **spec_ref**: `openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-001-translation-is-gated-by-a-limited-risk-dpo-enabled-aifeature`
- **files**: `lib/Service/MessageTranslationEngine.php`
- **acceptance_criteria**:
  - GIVEN the feature is missing or disabled WHEN translate() is called THEN it returns `{available: false, reason: "feature-not-enabled"}` and makes no provider call
  - GIVEN the feature is enabled WHEN translate() is called THEN it builds a glossary-aware prompt and calls `ProviderFactory::generateText()`, returning `{available: true, translatedText, targetLanguage, machineTranslationNotice, provider}`
  - GIVEN the feature is enabled but the provider throws WHEN translate() is called THEN it returns `{available: false, reason: "provider-error"}` and logs the failure, never throwing
  - GIVEN a glossary is supplied WHEN the prompt is built THEN every term's given translation appears verbatim in the prompt text
- [x] Implement
- [x] Test

### Task 3: Add the REST endpoint
- **spec_ref**: `openspec/changes/message-translation-delegate/specs/message-translation/spec.md#requirement-req-005-the-rest-endpoint-requires-authentication-and-validates-its-input`
- **files**: `lib/Controller/MessageTranslationController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN no authenticated session WHEN POST /api/translate is called THEN the response is 401
  - GIVEN an authenticated caller WHEN POST /api/translate is called without targetLanguage THEN the response is 400 and the engine is never invoked
  - GIVEN a valid authenticated request WHEN POST /api/translate is called THEN the engine's result is returned as the response body with status 200
- [x] Implement
- [x] Test

### Task 4: Register the repair step fleet-wide and verify the diff-scoped gates
- **spec_ref**: `openspec/changes/message-translation-delegate/proposal.md#impact`
- **files**: `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN `appinfo/info.xml` WHEN inspected THEN `SeedMessageTranslationFeature` appears in both the `install` and `repair-steps` (upgrade) blocks, alongside `SeedCourseRecommendationFeature`
  - GIVEN the diff-scoped gate suite (`php -l`, `phpcs`, `phpstan`, `phpunit --filter`) WHEN run on every new/changed file THEN it reports zero NEW findings
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests: not added — no Newman/Postman suite exists in this repo for any Hermiq endpoint (`grep -rl newman .` returns nothing); PHPUnit controller tests cover the endpoint's auth/validation/shape instead, matching every other Hermiq controller's existing test coverage
- UI changes covered by Playwright browser tests: N/A, no UI ships in this change
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010): N/A, no user-facing surface ships in this change (server-to-server API only)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007): N/A, no user-facing strings ship in this change
- `openspec validate` passes
