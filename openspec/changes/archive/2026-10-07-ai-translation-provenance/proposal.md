---
kind: code
depends_on:
  - message-translation-delegate
---

# Proposal: ai-translation-provenance

## Summary

Every answer from the hermiq translation delegate (`POST /api/translate`) says, in fields a consuming app can store and render, that AI made it: `translatedByAi`, `sourceLanguage`, `targetLanguage`, `model`, `originalRef` and a `disclosure` sentence in the target language. The contract of `message-translation-delegate` is amended to match, and the `message-translation` entry in the AI Act register records that its outputs are labelled. This is decision D24 of the learniq competitor round ("AI-made translations are visible"), hermiq side.

## Motivation

Decision D24 (Ruben, 2026-09-27, `learniq-mi/learniq/_round1/compare/decisions.md`) says content translated by the hermiq delegate carries a notice with the source language and a link to the original. The delegate built in `message-translation-delegate` (PR #969, not merged) returns only `translatedText`, `targetLanguage`, a fixed English notice and the provider name. A consumer cannot name the source language, cannot link back to the original it passed in, and cannot show the notice to an Arabic or Turkish reader in their own language.

The competitor evidence shows why this matters. Finding row 9.4 "Automatic translation of messages" (`_round1/compare/findings.md`, rung NICE, five sources: parentcom, canvas, social-schools, kwieb, parnassys) found the feature at every incumbent, and none of the cited sources describes a per-message provenance label. Parro ships translation on by default (parnassys round 1, row 15.7). Hoy refuses to translate content at all. Conduction's position is the middle path: translate, but gated, and visibly.

The EU AI Act Art. 50 transparency obligation for a limited-risk system is the legal floor. The `agentaifeature` register entry is where a DPO checks which features meet it, so the entry has to record the labelling, not only the description text.

## Affected Projects

- [ ] Project: `hermiq`: the translation engine and controller return the provenance fields; the `AiFeature` schema gains `outputsLabelled` and `outputLabelling`; the `message-translation` seed records both and back-fills an existing row; the delegate's contract.md is amended.

The consumer side (portaliq's translated-message notice) is its own change in portaliq (`translated-message-notice`), built against the amended contract.

## Scope

### In Scope

- Success responses carry `translatedByAi: true`, `sourceLanguage`, `targetLanguage`, `model`, `originalRef` and `disclosure`; unavailable responses carry `translatedByAi: false`, so the field is always present.
- A new optional request field `sourceLanguage` (BCP-47). When the caller omits it, the engine asks the same provider for the language tag and marks the answer `sourceLanguageDetected: true`; a detection that does not yield a valid tag becomes `und`.
- A new optional request field `originalRef` (string, up to 512 characters), echoed back unchanged.
- `model` is a provider label built from `chatProvider` and that provider's configured chat model id only. It never carries a credential id, a base URL, an organisation id or a key.
- `disclosure` is a fixed, reviewed sentence per target language ("Translated by AI from Dutch. This translation may contain errors."), with `disclosureLanguage` saying which language it is actually in. A target language without a sentence falls back to English and says so.
- Language tags are validated (BCP-47 shape) before they reach a prompt.
- Two new `AiFeature` properties (`outputsLabelled`, `outputLabelling`), schema version bump, register version bump, catalogue keys.
- `SeedMessageTranslationFeature` seeds both fields and back-fills them on an existing row that lacks them.
- The `message-translation-delegate` contract.md is amended; the existing `machineTranslationNotice` and `provider` fields stay for compatibility.

### Out of Scope

- A UI column for `outputsLabelled` in the AI feature register view (the value is stored and readable over the API; a column is a later UI change).
- Labelling for the other AI features (course recommendations, lesson authoring). Their seeds can adopt the two fields in their own changes.
- Human review of the per-language disclosure sentences. They are listed in design.md as AI-drafted, so a translator can check them.

## Approach

The engine stays gate-then-execute. After the gate, it resolves the source language (caller value, else one detection call), builds the translation prompt as before, then wraps the result in a provenance envelope. A small `TranslationDisclosure` value class holds the sentence table and the language-name lookup (`Locale::getDisplayLanguage` when ext-intl is loaded, else the uppercase tag). The controller validates the two new request fields and the language tag shape. Details in design.md.

## New Dependencies

None. `ext-intl` is used when present and is not required.

## Impact

- `POST /api/translate`: additive response fields; two new optional request fields; a malformed language tag is now a 400 where it used to reach the prompt.
- `agentaifeature` schema: two new optional properties. No existing data changes except the back-fill on the `message-translation` row.
- `lib/Service/MessageTranslationEngine.php`, `lib/Controller/MessageTranslationController.php`, `lib/Repair/SeedMessageTranslationFeature.php`, new `lib/Service/Translation/TranslationDisclosure.php`.

## Cross-Project Dependencies

- Stacked on `message-translation-delegate` (hermiq PR #969). This change cannot land before it.
- portaliq `translated-message-notice` consumes the amended contract. It is duck-typed and degrades to "no translation" when hermiq or this change is absent.

## Risks

### Risk 1: The disclosure sentences are AI-drafted
**Severity:** Medium. **Mitigation:** the table is small, lives in one class, and design.md lists it as awaiting a human translator. Every response names the language the disclosure is actually in, so a consumer can prefer its own catalogue.

### Risk 2: Detection names the wrong source language
**Severity:** Medium. **Mitigation:** detection only runs when the caller does not say; the response marks it `sourceLanguageDetected: true`; an invalid answer becomes `und`, which renders as "Translated by AI" without a language name. Callers that know the language (portaliq does) pass it.

### Risk 3: A label that leaks configuration
**Severity:** Low. **Mitigation:** `model` is built from two allow-listed keys and filtered to a model-id character set; a test feeds a config full of secrets and asserts none of them appear.

## Rollback Strategy

Revert the PR. The response fields are additive, so a consumer that stored them keeps its rows; the two schema properties stay in stored objects harmlessly. The back-filled values on the `message-translation` row can stay or be cleared by hand.

## Open Questions

None blocking. Whether a school may override the disclosure sentence per language is left for a later change.
