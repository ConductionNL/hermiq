# Contract: message-translation-delegate

## Consumers

- `portaliq` (future, out of scope for this change): planned `guardian-direct-messages` and `news-and-newsletter-authoring` changes, translating outgoing parent messages/news.
- `learniq` (future, out of scope for this change): any parent-facing message or news surface that wants translated output.

Both are soft, optional consumers. This change does not modify either app; it only opens the contract they can call once their own changes land.

## Endpoints

### `POST /api/translate`

**Auth**: Nextcloud session (`#[NoAdminRequired]`) — any authenticated user of any co-installed app calling through the internal API. No admin privilege required; no per-object authorization applies (see design.md, Security Considerations).

**Request:**
```json
{
  "sourceText": "De school is morgen gesloten wegens een studiedag.",
  "targetLanguage": "ar",
  "glossary": [
    { "term": "studiedag", "translation": "staff training day (school closed)" }
  ]
}
```
- `sourceText` (string, required): the text to translate.
- `targetLanguage` (string, required): a BCP-47 language tag (e.g. `ar`, `tr`, `pl`).
- `glossary` (array of `{term, translation}`, optional): school-specific terms the model must render verbatim using the given translation rather than translating freely.

**Response (200, available):**
```json
{
  "available": true,
  "translatedText": "...",
  "targetLanguage": "ar",
  "machineTranslationNotice": "This text was translated automatically and may contain errors.",
  "provider": "openai"
}
```

**Response (200, unavailable — feature not enabled or provider failure):**
```json
{ "available": false, "reason": "feature-not-enabled" }
```
`reason` is one of `feature-not-enabled` (the `message-translation` AiFeature is missing or `lifecycle: disabled`) or `provider-error` (the feature is enabled but the configured LLM provider failed). Both are 200 responses, not error statuses — an "unavailable" translation is a normal, expected outcome a caller must handle, not an exceptional one.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `sourceText` or `targetLanguage` missing from the request body |
| 401  | No authenticated Nextcloud session |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400  | Bad request | Required field missing before any gate check or provider call |
| 401  | Unauthenticated | No Nextcloud session |
| 200 + `available: false` | Feature unavailable | DPO has not enabled `message-translation`, or the provider failed |

## Versioning

Unversioned (`/api/translate`, no `/v1/` segment), matching every other Hermiq REST endpoint in this repo (`/api/recommendations`, `/api/health`, etc. — none carry a version segment). A breaking change to the request/response shape would need a new path segment; none is planned.

## Breaking Change Policy

A breaking change to this endpoint's request or response shape requires a PR description calling it out explicitly and, if any consumer has shipped its own change against this contract by then, coordination with that app's maintainers before merge — the same informal policy every other unversioned Hermiq endpoint already follows (no formal deprecation window exists fleet-wide yet).

## SLA

No formal SLA. Response time is bounded by the configured LLM provider's own latency (`ProviderFactory::generateText()`'s existing timeout budget); no new timeout is introduced by this change.
