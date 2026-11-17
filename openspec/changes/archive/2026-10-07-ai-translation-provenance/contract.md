# Contract: ai-translation-provenance

This change amends the `POST /api/translate` contract in `openspec/changes/message-translation-delegate/contract.md`. That file holds the full, current contract; this page states the delta so a reviewer can see what moved.

## Consumers

- `portaliq` (`translated-message-notice`): stores the provenance fields on a translated guardian message and renders the "Translated by AI from <language>" notice with a link to the original.
- `learniq`: any later parent-facing surface that translates through the delegate.

Both call through Nextcloud's internal API, duck-typed. A consumer on a hermiq without this change sees the older response and MUST treat a missing `translatedByAi` as "not labelled", never as "not AI".

## Endpoints

### `POST /api/translate` (amended)

**Auth**: unchanged, Nextcloud session (`@NoAdminRequired`). An in-process caller (portaliq serving a portal subject) may call `MessageTranslationEngine::translate()` without a session; the gate then reads the feature without RBAC (REQ-011), and the caller owns its subject's authorization.

**New optional request fields:**

| Field | Type | Rule |
|---|---|---|
| `sourceLanguage` | string | BCP-47 shaped tag. When absent, the delegate detects it. |
| `originalRef` | string | Up to 512 characters. Echoed back unchanged. Opaque to hermiq. |

**New validation:** `targetLanguage` and a given `sourceLanguage` must match `^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$`, else 400.

**Response (200, available), new fields in bold:**

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

- `translatedByAi`: always present; `true` on every success.
- `sourceLanguage`: the caller's tag, else the detected tag, else `und`.
- `sourceLanguageDetected`: `true` when hermiq detected the source language.
- `model`: `<chatProvider>` or `<chatProvider>/<chat model id>`. Never a secret, a URL or a credential id.
- `originalRef`: what the caller sent, or `""`.
- `disclosure`: a fixed sentence, never model output.
- `disclosureLanguage`: the language `disclosure` is written in; `en` when the table has no sentence for the target language.

**Response (200, unavailable):** `{ "available": false, "reason": "...", "translatedByAi": false }`.

## Error Codes

| Code | Condition |
|---|---|
| 400 | `sourceText` or `targetLanguage` missing (unchanged) |
| 400 | `targetLanguage` or a given `sourceLanguage` is not a BCP-47 shaped tag (new) |
| 400 | `originalRef` given but not a string, or longer than 512 characters (new) |
| 401 | No authenticated Nextcloud session (unchanged) |
| 200 + `available: false` | Feature disabled or provider failed (unchanged, now with `translatedByAi: false`) |

## Versioning

Unversioned path, unchanged. Every new response field is additive. The new 400 on a malformed tag narrows input that used to reach the prompt; no consumer had shipped against the delegate when this landed.

## Breaking Change Policy

Unchanged from the base contract. `machineTranslationNotice` and `provider` stay; removing them later is a breaking change and needs the consumers' sign-off.

## SLA

One extra provider call when `sourceLanguage` is omitted. Callers that know the language avoid it by passing `sourceLanguage`.
