# Test Plan: ai-translation-provenance

## Test Cases

### TC-1: A success carries the full provenance envelope
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance`
- **type**: functional
- **preconditions**: feature enabled, provider returns text
- **steps**: translate with `sourceLanguage`, `targetLanguage`, `originalRef`
- **expected result**: `translatedByAi: true`, both languages, `model`, `originalRef` unchanged, `disclosure`, `disclosureLanguage`
- **test command**: `vendor/bin/phpunit --filter MessageTranslationEngineTest`

### TC-2: An unavailable answer carries translatedByAi false
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-006-every-translation-response-carries-its-ai-provenance`
- **type**: functional
- **preconditions**: feature disabled; separately, provider throws
- **steps**: translate
- **expected result**: `available: false`, `translatedByAi: false`, no `translatedText`
- **test command**: `vendor/bin/phpunit --filter MessageTranslationEngineTest`

### TC-3: Source language caller-first, detected second, und on nonsense
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-007-the-source-language-is-the-callers-or-detected-and-marked-as-detected`
- **type**: functional
- **preconditions**: feature enabled
- **steps**: translate with and without `sourceLanguage`; detection answers `nl`, a sentence, or throws
- **expected result**: one provider call when given; `nl` + detected flag; `und` for a sentence or a throw, translation still returned
- **test command**: `vendor/bin/phpunit --filter MessageTranslationEngineTest`

### TC-4: The model label carries no secret
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-008-the-model-label-never-carries-a-secret`
- **type**: security
- **preconditions**: config holds credentialId, organizationId, baseUrl
- **steps**: translate
- **expected result**: `model` is `openai/gpt-4o-mini` and contains none of the secrets
- **test command**: `vendor/bin/phpunit --filter MessageTranslationEngineTest`

### TC-5: Disclosure table, fallback and und form
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-009-the-disclosure-is-written-in-the-target-language-or-says-which-language-it-is-in`
- **type**: functional
- **preconditions**: intl forced off for determinism
- **steps**: disclosure for nl to tr, nl to so, und to en, nl to pt-BR
- **expected result**: Turkish sentence with endonym; English fallback with `disclosureLanguage: en`; unnamed English sentence; Portuguese sentence with `pt`
- **test command**: `vendor/bin/phpunit --filter TranslationDisclosureTest`

### TC-6: Controller validation
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-010-language-tags-and-the-original-reference-are-validated-before-any-prompt`
- **type**: security
- **preconditions**: authenticated session
- **steps**: post an injected `targetLanguage`, a malformed `sourceLanguage`, a non-string and a 513-character `originalRef`
- **expected result**: 400 each, engine never invoked; valid input forwards both new fields
- **test command**: `vendor/bin/phpunit --filter MessageTranslationControllerTest`

### TC-7: Register records labelling; seed back-fills once
- **spec_ref**: `openspec/changes/ai-translation-provenance/specs/ai-feature-governance/spec.md#requirement-the-register-records-whether-a-features-outputs-are-labelled-as-ai-made`
- **type**: regression
- **preconditions**: none / an old row / a labelled row
- **steps**: run the repair step
- **expected result**: created with the fields; back-filled under the same uuid with lifecycle unchanged; no save for a labelled row
- **test command**: `vendor/bin/phpunit --filter SeedMessageTranslationFeatureTest`

## Coverage Summary

REQ-006 to REQ-010 of message-translation and the ai-feature-governance requirement are each covered by at least one case above.

## Out of Scope

The intl language-name path is asserted only where ext-intl is loaded (skipped otherwise); a live provider call is not part of the unit suite.
