# message-translation Specification

**Status**: idea
**Scope**: hermiq
**OpenSpec changes**:
- _(none yet)_

## Purpose

Lets any Conduction app delegate translation of a parent-facing message or news item to Hermiq: source text, a target language and a glossary of school terms in, translated text plus a machine-translation notice out, gated by the same disabled-until-DPO-acknowledged `AiFeature` governance every other Hermiq AI capability uses.

## ADDED Requirements

### Requirement: REQ-001: Translation is gated by a limited-risk, DPO-enabled AiFeature

The system SHALL NOT translate any text unless the `message-translation` `AiFeature` object (seeded `riskCategory: limited`, `lifecycle: disabled`) is `lifecycle: enabled`. The gate MUST be checked before any LLM call — a disabled feature MUST have zero LLM-provider footprint, not merely a hidden result field. The feature MUST use Hermiq's existing AiFeature governance lifecycle unchanged — no new governance mechanism is introduced.

#### Scenario: Translation is unavailable before the feature is enabled

- GIVEN the `message-translation` `AiFeature` exists in `lifecycle: disabled` (its seeded default)
- WHEN a caller requests a translation
- THEN the system MUST return `{available: false, reason: "feature-not-enabled"}`
- AND MUST NOT invoke any LLM provider

#### Scenario: Translation is unavailable when the feature does not exist yet

- GIVEN the `message-translation` `AiFeature` has not been seeded (e.g. the repair step has not run)
- WHEN a caller requests a translation
- THEN the system MUST return `{available: false, reason: "feature-not-enabled"}`
- AND MUST NOT throw

#### Scenario: Translation proceeds once the feature is enabled

- GIVEN the `message-translation` `AiFeature` has been enabled
- WHEN a caller requests a translation
- THEN the system MUST proceed to build the prompt and call the configured LLM provider

### Requirement: REQ-002: A successful translation always carries a machine-translation notice

Every successful translation response SHALL include a `machineTranslationNotice` field disclosing that the text was machine-translated, satisfying the transparency obligation for a limited-risk AI system.

#### Scenario: The notice is present on every success

- GIVEN the feature is enabled and the provider returns translated text
- WHEN the translation completes
- THEN the response MUST include a non-empty `machineTranslationNotice` string
- AND MUST include `translatedText` and `targetLanguage`

### Requirement: REQ-003: A supplied glossary is honoured verbatim

The system SHALL instruct the LLM to render each supplied glossary term using its given translation verbatim, rather than translating that term freely, so that school-specific vocabulary (staff titles, local program names) is not paraphrased.

#### Scenario: Glossary terms appear in the built prompt

- GIVEN a glossary `[{term: "trakteren", translation: "bringing treats to share with the class"}]`
- WHEN the prompt is built for a translation request carrying that glossary
- THEN the built prompt MUST contain both the term and its supplied translation verbatim
- AND MUST instruct the model to use that translation rather than inventing its own

#### Scenario: An empty glossary is a no-op

- GIVEN no glossary is supplied
- WHEN the prompt is built
- THEN the prompt MUST NOT contain an empty or malformed glossary section

### Requirement: REQ-004: A provider failure degrades to unavailable, never an unhandled error

The system SHALL catch any LLM-provider failure and return the same `{available: false}` shape used for a disabled feature, distinguished by `reason`, rather than propagating an exception to the caller.

#### Scenario: Provider failure returns a structured unavailable result

- GIVEN the feature is enabled but the configured LLM provider throws
- WHEN a caller requests a translation
- THEN the system MUST return `{available: false, reason: "provider-error"}`
- AND MUST log the underlying failure
- AND MUST NOT throw to the caller

### Requirement: REQ-005: The REST endpoint requires authentication and validates its input

`POST /api/translate` SHALL require an authenticated caller and SHALL reject a request missing `sourceText` or `targetLanguage` with a 400 before any gate check or LLM call.

#### Scenario: Unauthenticated request is rejected

- GIVEN no authenticated user session
- WHEN `POST /api/translate` is called
- THEN the response status MUST be 401

#### Scenario: Missing required field is rejected

- GIVEN an authenticated caller
- WHEN `POST /api/translate` is called without `targetLanguage`
- THEN the response status MUST be 400
- AND the engine MUST NOT be invoked

## Non-Functional Requirements

- **Performance:** A single translation call completes within the existing `ProviderFactory` timeout budget; no new timeout configuration is introduced.
- **Accessibility:** N/A — no UI ships in this change.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) — `targetLanguage` accepts any BCP-47 language tag, not a fixed enum, so the fleet's full language set is reachable without a schema change per language.

## Acceptance Criteria

- [ ] A disabled or unseeded `message-translation` AiFeature makes zero LLM-provider calls.
- [ ] An enabled feature returns `translatedText`, `targetLanguage`, and a non-empty `machineTranslationNotice` on success.
- [ ] Every supplied glossary term's translation appears verbatim in the built prompt.
- [ ] A provider failure returns a structured unavailable result, never an unhandled exception.
- [ ] `POST /api/translate` is 401 unauthenticated and 400 on missing required fields.

## Notes

Mirrors `course-recommendations`' gate-then-execute shape exactly, at `riskCategory: limited` rather than `high` — translating a message does not decide anything about a person the way a course/career recommendation does, but the AI Act still requires disclosure of AI-generated content, which `machineTranslationNotice` satisfies (see Risk 3 in proposal.md). Portaliq's and learniq's own call sites are out of scope for this change (proposal.md, Out of Scope).
