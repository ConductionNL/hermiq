# Design: forms-smart-paste-fill

Read at hermiq development `5ac16d3` on 28 September 2026, with buildiq's `ai-smart-paste-into-forms` and nextcloud-vue's `form-smart-paste` on their development branches.

## Context

- The governed delegate pattern buildiq names is `lesson-authoring-ai-delegate` (open change, built):
  - `lib/Controller/LessonAuthoringController.php`: `#[NoAdminRequired]` and `#[UserRateLimit(limit: 30, period: 60)]` on each action (`:124-126`), only allowlisted request keys read, errors mapped in one place (`:397-436`).
  - `lib/Service/LessonAuthoringEngine.php`: the gate, `execute()` (`:330`): `AiFeatureService::findBySlug()` and a `lifecycle` of `enabled`, else `unavailable` with reason `feature-not-enabled` and no provider call; the model call through `ProviderFactory::generateText()` (`:339-340`); `logCall()` (`:402`) writes action, outcome, user, provider and input sizes, never content; a fixed draft notice (`:124`) and a material instruction that frames the input as data (`:139`).
  - `lib/Repair/SeedLessonAuthoringFeature.php`: seeds the `AiFeature` idempotently with `riskCategory: limited` and `lifecycle: disabled`; `AiFeatureService::acknowledge()` records the acknowledgement an admin needs before `enable()`.
- Routes: `appinfo/routes.php:550` `assistant#converse`, `:556` `assistant#detectPii`, `:652-655` `lessonAuthoring#*`. Nothing fills a form.
- The renderer contract (nextcloud-vue `form-smart-paste` D1): the handler gets `(text, fields)` where each field is `{key, label, type, options}` and returns `{values}`; `available()` decides whether "Paste to fill" shows. Only the pasted text and the allowed fields' key, label, type and options leave the form.

## Decisions

### D1. Same gate, same log, new slug

`FormFillEngine` follows `LessonAuthoringEngine::execute()`: the `form-fill` feature must be `enabled`, the model runs through `ProviderFactory`, and each call logs `action: form-fill`, the outcome, the user, the provider, the text length and the field count. A repair step `SeedFormFillFeature` seeds the feature disabled at limited risk, with a description that says what leaves the form. No new governance.

### D2. The endpoint and its allowlist

`FormFillController::fill()` (`POST /api/assistant/form-fill`, `#[NoAdminRequired]`, `#[UserRateLimit(limit: 30, period: 60)]`) reads only `text` (1 to 10,000 characters), `fields` (1 to 50 entries of `{key, label, type, options?}`, `key` matching `^[A-Za-z0-9_.-]{1,64}$`) and `language`. Anything else is ignored; an input outside the limits answers 400 before the gate. `available()` (`GET /api/assistant/form-fill/available`) answers `{available: bool}` from the gate alone, with no model call.

### D3. The prompt treats the text as material

The prompt lists the fields with label, type and options, puts the pasted text between `<pasted>` and `</pasted>` with the instruction that it is material, not instructions, and asks for one JSON object keyed by field key with only the values the text states. A value the text does not state is left out, never guessed.

### D4. The answer is checked against the fields

`FormFillEngine` keeps a proposed value only when its key is one of the given fields and it fits the type: a string for text, a number for a number field, an ISO date for a date field, one of `options` for a field with options (matched case-insensitively and returned in the option's own spelling), `true` or `false` for a boolean. Anything else is dropped. The response is `{values, draft: true, notice}` with the notice "These values were proposed by AI from the text you pasted. Check them before you save." Unusable model output answers `unavailable` with reason `provider-error`, as the lesson delegate does.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| the `form-fill` feature | declarative, an `AiFeature` object seeded off | the governance register |
| allowlist and limits | imperative, the controller | request validation |
| gate, model call, checks, log | imperative, `FormFillEngine` | the delegate pattern |

## Risks

- A pasted text that tries to instruct the model: framed as material (D3), and a value can only land in an allowed field of the right type (D4).
- Personal data in the pasted text goes to the configured model. The feature is off until an admin acknowledges it; the log never holds the text.
