# Design: message-translation-delegate

## Architecture Overview

Mirrors the already-shipped `course-recommendations` AI feature exactly: a repair step seeds a disabled `agentaifeature` governance object, an engine gates on that object before doing any provider work, and a thin controller maps HTTP to the engine. `MessageTranslationEngine` is new; `AiFeatureService` (the gate) and `ProviderFactory` (the LLM call) are reused unchanged.

```
caller (portaliq / learniq, future)
  -> POST /api/translate
       -> MessageTranslationController (auth + input shape)
            -> MessageTranslationEngine::translate()
                 -> AiFeatureService::findBySlug('message-translation')  [gate]
                 -> ProviderFactory::generateText(prompt)                [LLM]
```

## API Design

### `POST /api/translate`
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
**Response (available, success):**
```json
{
  "available": true,
  "translatedText": "...",
  "targetLanguage": "ar",
  "machineTranslationNotice": "This text was translated automatically and may contain errors.",
  "provider": "openai"
}
```
**Response (feature not enabled):**
```json
{ "available": false, "reason": "feature-not-enabled" }
```
**Response (provider failure):**
```json
{ "available": false, "reason": "provider-error" }
```
**Response (validation failure, HTTP 400):**
```json
{ "error": "targetLanguage is required" }
```

## Database Changes

None. Reuses the existing `agentaifeature` schema (register `hermiq`) unchanged — this change adds one seeded row (`slug: message-translation`), not a schema change.

## Nextcloud Integration

- Controllers: `OCA\Hermiq\Controller\MessageTranslationController` (new)
- Services: `OCA\Hermiq\Service\MessageTranslationEngine` (new); `OCA\Hermiq\Service\AiFeatureService`, `OCA\Hermiq\Service\Llm\ProviderFactory` (reused, unchanged)
- Repair steps: `OCA\Hermiq\Repair\SeedMessageTranslationFeature` (new), registered in `appinfo/info.xml`'s `install` and `repair-steps` blocks alongside `SeedCourseRecommendationFeature`
- Mappers/Entities: none new — reads/writes go through OpenRegister's `ObjectService`, same as every other AiFeature-gated capability
- Events/Hooks: none

## Security Considerations

`POST /api/translate` requires `#[NoAdminRequired]` + an authenticated `IUserSession` (401 if absent) — any authenticated user of any co-installed app may call it, since translation carries no per-object authorization concern the way course recommendations (self-scoped to a learner) or delegation (agent-scoped) do: the caller supplies the text to translate directly, there is no cross-tenant object to leak. Input validation (`sourceText`, `targetLanguage` required) happens before the gate check and before any provider call, so a malformed request never reaches the LLM. The engine never logs `sourceText` at more than debug/info level with the full text — provider failures log the exception message only, not the source content, to avoid parent-message content sitting in application logs at warning level.

## NL Design System

Not applicable — no frontend ships in this change.

## File Structure

```
lib/
  Controller/
    MessageTranslationController.php   (new)
  Service/
    MessageTranslationEngine.php       (new)
  Repair/
    SeedMessageTranslationFeature.php  (new)
appinfo/
  routes.php                           (+1 route)
  info.xml                             (+1 repair step, both blocks)
tests/Unit/
  Controller/MessageTranslationControllerTest.php  (new)
  Service/MessageTranslationEngineTest.php          (new)
  Repair/SeedMessageTranslationFeatureTest.php       (new)
```

## Seed Data

Not applicable in the `_registers.json`/demo-object sense (ADR-016) — this change seeds exactly one **governance** object via a repair step (`SeedMessageTranslationFeature`, mirroring `SeedCourseRecommendationFeature`'s pattern precisely), not a demo content object. The seeded object:

| Field | Value |
|---|---|
| slug | `message-translation` |
| name | `Message translation` |
| description | Translates parent-facing messages and news into a target language, with a caller-supplied glossary for school-specific terms. Limited-risk under the EU AI Act: output must be disclosed as machine-translated (Art. 50), it does not decide anything about a person. |
| riskCategory | `limited` |
| lifecycle | `disabled` |
| tenantId | `''` (fleet-wide, matching `course-recommendations`' seed) |

## Trade-offs

**One-shot `generateText()` vs. a dedicated translation-specific provider call.** `ProviderFactory::generateText(prompt)` is a plain chat completion, not a purpose-built translation API (e.g. DeepL). Considered and rejected for this change: adding a second provider class (DeepL) duplicates the credential-broker/model-policy machinery `ProviderFactory` already owns for zero proven need yet — the corpus shows every parent-app competitor uses a generic LLM or an in-house model, not a dedicated translation vendor API, so matching that is not a gap. If translation quality becomes a measured problem, a `translate()` method can be added to `ProviderFactory` later without changing this engine's or the controller's contract.

**Self-contained gate check (`AiFeatureService::findBySlug`) vs. a shared "feature-gated engine" base class.** `course-recommendations` inlines the same three-line gate check rather than extracting a base class; this change follows that precedent rather than introducing a new abstraction the moment a second example appears — two data points do not yet justify a shared class, and the check is three lines.

**No caching of translated text.** A repeated identical `(sourceText, targetLanguage, glossary)` call re-invokes the LLM every time. Rejected for this change: message/news text is typically translated once per send, not repeatedly, so a cache adds complexity (a cache key, an invalidation story) for a cost pattern that doesn't exist yet.
