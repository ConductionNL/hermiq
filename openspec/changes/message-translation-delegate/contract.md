# Contract: message-translation-delegate

## Consumers

- `portaliq` (future, out of scope for this change): planned `guardian-direct-messages` and `news-and-newsletter-authoring` changes, translating outgoing parent messages/news.
- `learniq` (future, out of scope for this change): any parent-facing message or news surface that wants translated output.

Both are soft, optional consumers. This change does not modify either app; it only opens the contract they can call once their own changes land.

> **Amended by `ai-translation-provenance`** (decision D24, "AI-made translations are visible"): two optional request fields (`sourceLanguage`, `originalRef`), a tag-shape check, and the provenance fields on every answer. The delta is in `openspec/changes/ai-translation-provenance/contract.md`; this page is the full current contract.

## Endpoints

### `POST /api/translate`

**Auth**: Nextcloud session (`#[NoAdminRequired]`) — any authenticated user of any co-installed app calling through the internal API. No admin privilege required; no per-object authorization applies (see design.md, Security Considerations).

In-process callers without a session (portaliq serving a portal subject) call `MessageTranslationEngine::translate()` directly. Its gate reads the feature without RBAC (ai-translation-provenance REQ-011), and the caller owns the authorization of its own subject.

**Request:**
```json
{
  "sourceText": "De school is morgen gesloten wegens een studiedag.",
  "targetLanguage": "ar",
  "glossary": [
    { "term": "studiedag", "translation": "staff training day (school closed)" }
  ],
  "sourceLanguage": "nl",
  "originalRef": "portaliq:message:00000000-0000-0000-0000-000000000000"
}
```
- `sourceText` (string, required): the text to translate.
- `targetLanguage` (string, required): a BCP-47 language tag (e.g. `ar`, `tr`, `pl`).
- `glossary` (array of `{term, translation}`, optional): school-specific terms the model must render verbatim using the given translation rather than translating freely.
- `sourceLanguage` (string, optional, ai-translation-provenance): a BCP-47 tag for the language of `sourceText`. When absent, hermiq asks the provider for the tag of the first 500 characters and marks the answer `sourceLanguageDetected: true`; an unusable answer becomes `und`.
- `originalRef` (string, optional, at most 512 characters, ai-translation-provenance): whatever the caller uses to identify the original. Opaque to hermiq and echoed back unchanged, so the consumer can link the reader to the original text.

`targetLanguage` and a given `sourceLanguage` MUST match `^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$`; both are interpolated into a prompt, so anything else is a 400.

**Response (200, available):**
```json
{
  "available": true,
  "translatedText": "...",
  "targetLanguage": "ar",
  "machineTranslationNotice": "This text was translated automatically and may contain errors.",
  "provider": "openai",
  "translatedByAi": true,
  "sourceLanguage": "nl",
  "sourceLanguageDetected": false,
  "model": "openai/gpt-4o-mini",
  "originalRef": "portaliq:message:00000000-0000-0000-0000-000000000000",
  "disclosure": "<the Arabic disclosure sentence>",
  "disclosureLanguage": "ar"
}
```

Provenance fields (ai-translation-provenance):

| Field | Meaning |
|---|---|
| `translatedByAi` | Always present. `true` on every success, `false` on every unavailable answer. A missing field (an older hermiq) means "not labelled", never "not AI". |
| `sourceLanguage` | The caller's tag, else the detected tag, else `und`. |
| `sourceLanguageDetected` | `true` when hermiq detected the source language. |
| `model` | `<chatProvider>` or `<chatProvider>/<chat model id>`, filtered to a model-id character set, at most 120 characters. Never a credential id, organisation id, base URL or key. |
| `originalRef` | The caller's reference, or `""`. |
| `disclosure` | A fixed sentence ("Translated by AI from Dutch. This translation may contain errors.") from a reviewed per-language table, never model output. |
| `disclosureLanguage` | The primary subtag of the language `disclosure` is written in; `en` when the table has no sentence for the target language. |

`machineTranslationNotice` and `provider` stay for compatibility.

**Response (200, unavailable — feature not enabled or provider failure):**
```json
{ "available": false, "reason": "feature-not-enabled", "translatedByAi": false }
```
`reason` is one of `feature-not-enabled` (the `message-translation` AiFeature is missing or `lifecycle: disabled`) or `provider-error` (the feature is enabled but the configured LLM provider failed). Both are 200 responses, not error statuses — an "unavailable" translation is a normal, expected outcome a caller must handle, not an exceptional one.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `sourceText` or `targetLanguage` missing from the request body |
| 400  | `targetLanguage` or a given `sourceLanguage` is not a BCP-47 shaped tag (ai-translation-provenance) |
| 400  | `originalRef` given but not a string, or longer than 512 characters (ai-translation-provenance) |
| 401  | No authenticated Nextcloud session |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400  | Bad request | Required field missing, a malformed language tag, or an invalid `originalRef`, before any gate check or provider call |
| 401  | Unauthenticated | No Nextcloud session |
| 200 + `available: false` | Feature unavailable | DPO has not enabled `message-translation`, or the provider failed |

## Versioning

Unversioned (`/api/translate`, no `/v1/` segment), matching every other Hermiq REST endpoint in this repo (`/api/recommendations`, `/api/health`, etc. — none carry a version segment). A breaking change to the request/response shape would need a new path segment; none is planned.

## Breaking Change Policy

A breaking change to this endpoint's request or response shape requires a PR description calling it out explicitly and, if any consumer has shipped its own change against this contract by then, coordination with that app's maintainers before merge — the same informal policy every other unversioned Hermiq endpoint already follows (no formal deprecation window exists fleet-wide yet).

## SLA

No formal SLA. Response time is bounded by the configured LLM provider's own latency (`ProviderFactory::generateText()`'s existing timeout budget); no new timeout is introduced by this change.
