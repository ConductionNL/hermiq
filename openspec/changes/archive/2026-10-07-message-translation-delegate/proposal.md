# Proposal: message-translation-delegate

## Summary

Add a translation delegate to Hermiq: a governed, off-by-default AI feature that takes a parent-facing message or news item's source text, a target language and a glossary of school terms, and returns the translated text plus a machine-translation notice. It is exposed the same way Hermiq already exposes AI-feature governance to a consuming app — a central `agentaifeature` register entry a caller checks before use, disabled until a DPO enables it — the pattern scholiq's `ai-feature-delegate-to-hermiq` change already follows for its own AI Act governance surface.

## Motivation

`M3-integrations.md` row I24 ("AI: translation, summaries, agents") already names the target shape for the whole fleet: "AI features gated, delegated to hermiq", but nothing in Hermiq answers what that delegation actually returns for translation, only how governance is checked. Every Dutch parent-communication incumbent in this round ships some form of message translation, and they disagree sharply on the AI Act posture: Parro's translation feature ships **on by default**, which the corpus explicitly marks a `no` against "AI features disabled by default with DPO acknowledgement" (parnassys round1, row 15.7, "Parro Vertalen (AI) is 'Gratis uitbreiding is direct actief', on by default and switched off by the school"). Kwieb ships two-way translation across 40+ languages as a normal parent-app feature. Hoy refuses to translate message *content* at all, as a deliberate privacy/accuracy stance, and translates only its own interface strings. Hermiq's own governance model (EU AI Act Annex III risk classification, disabled-until-DPO-enabled lifecycle) already gives Conduction a principled middle path between Parro's on-by-default posture and Hoy's blanket refusal: ship the capability, gate it, and require a human to turn it on.

## Affected Projects

- [ ] Project: `hermiq` — adds the `message-translation` AI feature (governance seed + execution engine + REST endpoint) that any Conduction app (portaliq's future guardian messaging, learniq) can call once a DPO enables it.

No other apps-extra project is touched by this change. The consuming side (portaliq's `guardian-direct-messages`/`news-and-newsletter-authoring`, learniq) is out of scope here and follows in those apps' own changes, calling this endpoint once it exists.

## Scope

### In Scope

1. A new `message-translation` `agentaifeature` governance entry, seeded `lifecycle: disabled`, `riskCategory: limited` (EU AI Act: a system whose output must be disclosed as AI-generated, not a high-risk decision system — translating a newsletter does not decide anything about a person).
2. A `MessageTranslationEngine` that, once the feature is DPO-enabled, builds a glossary-aware prompt from `{sourceText, targetLanguage, glossary}` and calls the existing `ProviderFactory::generateText()` primitive (the same one-shot text primitive `CourseRecommendationEngine` already uses for its LLM-phrased explanations) — no new LLM integration.
3. A REST endpoint (`POST /api/translate`) that other Conduction apps call server-to-server. Returns `{translatedText, targetLanguage, machineTranslationNotice, provider}` on success, and a clear "feature not enabled" result (not an error) when the DPO has not turned it on, mirroring `CourseRecommendationEngine::unavailableResult()`'s "degrade to unavailable, never throw" shape.
4. A glossary parameter: an optional list of `{term, translation}` pairs the caller supplies (school-specific terms — "trakteren", "verlofaanvraag", staff titles) that the prompt instructs the model to use verbatim rather than translate freely.
5. Tests: the governance gate (disabled by default; enabled unlocks execution), the prompt builder (glossary terms appear verbatim in the instructions), the "unavailable" degrade path, and the controller's auth/shape mapping.

### Out of Scope

- Any consuming app's own UI or call site (portaliq, learniq) — this ships the delegate only, per the M3(b) instruction to "define what 'AI features gated, delegated to hermiq' actually delegates to," not to build every consumer.
- Streaming/conversational translation (chat-style back-and-forth) — this is one-shot text-in, text-out, matching the message/news use case, not Hoy's or Kwieb's live chat translation.
- Automatic language detection of the source text — the caller supplies both source text and target language; detecting the *source* language is deferred.
- Translating attachments, images, or any non-text content.

## Approach

Mirror the already-shipped `course-recommendations` AI feature end to end: a repair step seeds the governance object disabled, an engine gates on that object's `lifecycle` before doing any LLM work, and a thin controller maps HTTP to the engine. The only new integration is the glossary-aware prompt; the governance gate, the provider resolution, and the "never throw, degrade instead" shape are all reused as-is. See design.md.

## New Dependencies

None. Reuses `ProviderFactory`, `AiFeatureService`, and the existing `agentaifeature` OpenRegister schema.

## Impact

- `lib/Repair/SeedMessageTranslationFeature.php` (new): seeds the governance object.
- `lib/Service/MessageTranslationEngine.php` (new): gate + prompt + call.
- `lib/Controller/MessageTranslationController.php` (new): REST endpoint.
- `appinfo/routes.php`: one new route.
- `appinfo/info.xml`: register the repair step (install + repair-steps blocks, alongside `SeedCourseRecommendationFeature`).

## Cross-Project Dependencies

None hard. Portaliq's planned `guardian-direct-messages`/`news-and-newsletter-authoring` changes and learniq are soft, future consumers of this endpoint; this change does not modify either app.

## Risks

### Risk 1: A caller treats "feature not enabled" as a hard failure
- **Severity:** Medium — **Mitigation:** the engine's unavailable result is a normal 200-shaped response (`{available: false, reason}`), not an HTTP error, mirroring `CourseRecommendationEngine`; a caller that only checks the HTTP status code still gets a response it can render as "translation is not available here" rather than a crash.

### Risk 2: Glossary terms leak into the notice or get silently dropped
- **Severity:** Low — **Mitigation:** covered by a dedicated prompt-builder test asserting every glossary term appears verbatim in the built prompt text.

### Risk 3: LLM output quality/accuracy for safety-relevant school communication
- **Severity:** Medium — **Mitigation:** out of scope to solve generally (matches Hoy's documented reason for refusing translation entirely), but the `machineTranslationNotice` field is mandatory in every successful response so a consuming app can always disclose the text is machine-translated, which is the EU AI Act Article 50 transparency obligation for a limited-risk system — the actual accuracy trade-off is a DPO/school policy decision made when they enable the feature, not something this change can adjudicate.

## Rollback Strategy

Revert the single PR. The seeded `agentaifeature` object stays `disabled` unless a DPO already enabled it; disabling it again (or reverting) removes the capability with no data migration, since this change persists no translation output.

## Open Questions

None — the approach mirrors an already-shipped, tested pattern in this repo.
