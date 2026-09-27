# Tasks: ai-translation-provenance

Stacked on `message-translation-delegate` (hermiq PR #969).

## Implementation Tasks

### Task 1: The disclosure table and language names
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-009-the-disclosure-is-written-in-the-target-language-or-says-which-language-it-is-in`
- **files**: `lib/Service/Translation/TranslationDisclosure.php`, `tests/Unit/Service/Translation/TranslationDisclosureTest.php`
- **acceptance_criteria**:
  - GIVEN source nl and target tr WHEN the disclosure is built THEN it is the Turkish sentence and `disclosureLanguage` is tr
  - GIVEN a target the table lacks WHEN the disclosure is built THEN it is English and `disclosureLanguage` is en
  - GIVEN source und WHEN the disclosure is built THEN the unnamed sentence is used
  - GIVEN a tag WHEN it is checked THEN only BCP-47 shaped tags pass
- [x] Implement
- [x] Test

### Task 2: The provenance envelope in the engine
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance`
- **files**: `lib/Service/MessageTranslationEngine.php`, `tests/Unit/Service/MessageTranslationEngineTest.php`
- **acceptance_criteria**:
  - GIVEN the feature is enabled WHEN a translation succeeds THEN the response carries `translatedByAi: true`, `sourceLanguage`, `targetLanguage`, `model`, `originalRef`, `disclosure`, `disclosureLanguage`
  - GIVEN the feature is disabled or the provider fails WHEN translate() runs THEN the response carries `translatedByAi: false`
- [x] Implement
- [x] Test

### Task 3: Source language detection and the model label
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-007-the-source-language-is-the-callers-or-detected-and-marked-as-detected`
- **files**: `lib/Service/MessageTranslationEngine.php`, `tests/Unit/Service/MessageTranslationEngineTest.php`
- **acceptance_criteria**:
  - GIVEN a caller source language WHEN translate() runs THEN the provider is called once
  - GIVEN no source language WHEN detection answers nl THEN `sourceLanguage` is nl and `sourceLanguageDetected` is true
  - GIVEN detection answers a sentence or throws THEN `sourceLanguage` is und and the translation is still returned
  - GIVEN a config with credential id, organisation id and base URL WHEN a translation succeeds THEN `model` is `openai/gpt-4o-mini` and contains none of them (REQ-008)
- [x] Implement
- [x] Test

### Task 4: Controller validation and the two new request fields
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-010-language-tags-and-the-original-reference-are-validated-before-any-prompt`
- **files**: `lib/Controller/MessageTranslationController.php`, `tests/Unit/Controller/MessageTranslationControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a malformed target or source language WHEN posted THEN 400 and the engine is not invoked
  - GIVEN an `originalRef` that is not a string or longer than 512 characters WHEN posted THEN 400
  - GIVEN valid input WHEN posted THEN `sourceLanguage` and `originalRef` reach the engine
- [x] Implement
- [x] Test

### Task 5: The register records that outputs are labelled
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/ai-feature-governance/spec.md#requirement-the-register-records-whether-a-features-outputs-are-labelled-as-ai-made`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Settings/hermiq_mock_register.json`, `l10n/nl.json`, `l10n/en.json`, `lib/Repair/SeedMessageTranslationFeature.php`, `tests/Unit/Repair/SeedMessageTranslationFeatureTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN `AiFeature` 0.5.0 has `outputsLabelled` (default false) and `outputLabelling`, register version 0.33.0, catalogue keys for both
  - GIVEN no row WHEN the seed runs THEN the row is created with `outputsLabelled: true`
  - GIVEN an old row WHEN the seed runs THEN it is saved once under the same uuid with lifecycle unchanged
  - GIVEN a labelled row WHEN the seed runs THEN nothing is saved
- [x] Implement
- [x] Test

### Task 6: Amend the delegate contract and document the fields
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance`
- **files**: `openspec/changes/message-translation-delegate/contract.md`, `CHANGELOG.md`, `docs/features/message-translation.md`
- **acceptance_criteria**:
  - GIVEN the base contract WHEN read THEN it lists the new request fields, response fields, validation and error codes
  - GIVEN the docs WHEN read THEN a consumer knows which fields to store and how to render the notice
- [x] Implement
- [x] Test

### Task 7: The gate answers for a caller without a session
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-011-the-gate-answers-for-a-caller-without-a-nextcloud-session`
- **files**: `lib/Service/AiFeatureService.php`, `lib/Service/MessageTranslationEngine.php`, `tests/Unit/Service/AiFeatureServiceGateTest.php`, `tests/Unit/Service/MessageTranslationEngineTest.php`
- **acceptance_criteria**:
  - GIVEN no Nextcloud user WHEN the engine gates THEN it reads the feature with `_rbac: false` and tenant scoping kept
  - GIVEN no Nextcloud user WHEN `findBySlug` reads THEN RBAC still applies
- [x] Implement
- [x] Test

## Quality checklist

- New and changed logic covered by PHPUnit unit tests (`tests/Unit/`)
- No Newman collection exists for `/api/translate` yet; the controller unit tests cover the new 400s
- No UI change in this change, so no Playwright test
- Catalogue keys for the two schema properties (`npm run check:schema-l10n`, `npm run l10n:build`)
- `openspec validate ai-translation-provenance` passes
