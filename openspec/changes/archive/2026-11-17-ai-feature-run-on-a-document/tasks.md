# Tasks: ai-feature-run-on-a-document

Tier: V1. Kind: code. Size S. Built for dossiq `ai-features-on-the-case-consume-hermiq` 3.1 under decision 156.

- [x] 1.1 `ProviderFactory::generateText()` takes optional `aiFeature` and `documentReference` and passes both to `createChatDriver()`.
  - Test: `tests/Unit/Service/Llm/ProviderFactoryGenerateTextFeatureTest.php`.
- [x] 1.2 `lib/Service/AiFeature/DocumentFeatureRun.php`: requires the reference, refuses an unknown or switched-off feature, reads the document as the caller, applies the organisation's guardrails, generates through the gates.
  - Test: `tests/Unit/Service/AiFeature/DocumentFeatureRunTest.php`.
- [x] 1.3 `lib/Controller/AiFeatureRunController.php` and the route `aiFeatureRun#runOnDocument`; each gate's refusal is a 422 with `gate`.
  - Test: `tests/Unit/Controller/AiFeatureRunControllerTest.php`.
- [x] 2.1 Live: run a feature that requires redaction on an unredacted document and read the 422 (live pass, decision 139).
  - Live 2026-11-17, clean NC 34 + OpenRegister main: an acknowledged, enabled feature with `requiresRedaction` run on an unredacted text file answered 422 with `gate: redaction` ("the redaction client (filinq) is not installed on this instance"): the fail-closed path. The provider is resolved first, so a feature with no configured provider answers 503 before any gate.
