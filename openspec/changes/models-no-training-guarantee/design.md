# Design: models-no-training-guarantee

Kind: code. Size M. A second declaration next to residency, one policy field, one gate step on the existing pre-call path, and two form sections.

## Context at development db6b74dc

- `lib/Service/AiFeature/ProviderResidencyRegistry.php:52-92` the residency values and `CONFIG_KEY` `providerResidency`; `:111` `all()`; `:131-151` `forProvider()`; `:166-192` `declareResidency()` (validates the provider against `LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS`).
- `lib/Controller/Settings/ProviderResidencySettingsController.php:75-76` `get()`, `:105-106` `declareResidency()`, both `#[AuthorizedAdminSetting(AdminSettings::class)]`; routes `appinfo/routes.php:458`, `:465-470`.
- `lib/Service/AiFeature/FeatureProviderResolver.php:183-236` `enforceForRun()`; `:323` `disclosureFor()`; `:345-365` `disclosure()`.
- `lib/Service/Llm/ProviderFactory.php:587-653` `createChatDriver()`: the feature chain at `:639`, the model policy only at `:650`; `:672-696` `enforceModelPolicy()`.
- `lib/Service/TenantModelPolicyService.php:172` `effectivePolicyFor()`, `:227` `isAllowed()`, `:248` `upsertForOrganisation()`.
- `lib/Settings/hermiq_register.json:1172-1245` `ModelPolicy`.
- `lib/Service/Engine/RunTraceCollector.php:189-198` `recordProviderDisclosure()`; `lib/Service/Engine/ResponseGenerationHandler.php:294-303` records the disclosure only when the run names a feature.
- `src/modals/LlmProviderModal.vue` per-provider sections; `src/views/TenantOps.vue:148-171` the model policy section.

## D1. What a provider states about data use

A new `ProviderDataUseRegistry` beside `ProviderResidencyRegistry`, stored in `IAppConfig` key `providerDataUse`, keyed by provider id. Each entry: `dataUse` (`zero-retention`, `no-training`, `may-train`), `termsReference` (free text, for example "OpenAI API data usage policy, checked 2026-09-01" or "DPA Rekencentrum 2026-014"), `declaredBy` and `declaredAt`. A provider without an entry reads `undeclared`.

The value is administered, never inferred, for the same reason residency is (`a-provider-and-a-place-per-ai-feature` D3): a guessed term is one no privacy officer can rely on. `zero-retention` implies no training.

Rejected: one enum that merges residency and data use. They answer two different questions a tender asks separately, and a provider can be `eu` and `may-train` at once.

## D2. The organisation requires it on the model policy

`ModelPolicy` gains `requireNoTraining` (boolean, default false). The instance default policy may set it for every organisation without a policy of its own, through the resolution `effectivePolicyFor()` already does.

## D3. One more step, on every run

A `DataUseViolationException` with `STEP = 'data-use'`. The check runs directly after the model policy check, on both paths of `createChatDriver()`: inside `enforceForRun()` for a run that names a feature, and next to `enforceModelPolicy()` for one that does not. A tender requirement on training does not care whether the agent was tagged with a feature, so the check cannot live only on the feature path. Order: model policy, data use, residency, redaction.

The refusal reads "Refused by the data-use check: organisation 'Gemeente Voorbeeld' requires providers that never train on its data, and provider 'openai' has declared 'undeclared'." It is thrown before any request is built, so nothing is sent. Scheduled and flow runs record it as a failed run with that message, as a model policy refusal is recorded today. A chat user sees "This assistant cannot answer: your organisation only allows AI providers that never train on its data."

Every hop of a fallback chain and every reference model of an ensemble (`models-several-models-per-turn`) goes through the same step, because they go through `createChatDriver()`.

## D4. The term on the run

The disclosure gains `dataUse` and `termsReference`, copied at run time like `residency`, so declaring a new term next year does not rewrite what this year's runs say. The disclosure is recorded for every run, not only for runs with a feature (today `ResponseGenerationHandler.php:294-303` records it only when a feature is named), with `feature` empty when there is none.

## D5. The screens

The provider form in `LlmProviderModal.vue` gains two small sections under each hosted provider:

- "Where it runs": residency and location, saved through the existing `PUT /api/settings/provider-residency/{provider}`. This is the screen that route has lacked.
- "What it does with your data": data use and terms reference, saved through a new `PUT /api/settings/provider-data-use/{provider}`, `#[AuthorizedAdminSetting(AdminSettings::class)]`.

The model policy section on Tenant operations gains the switch "Only use providers that never train on our data". When it is on, the section lists every allowed provider whose declaration is not `no-training` or `zero-retention`, with "Runs on this provider will be refused."

## Declarative versus imperative

`requireNoTraining` is declared on `ModelPolicy` in the register. The provider declaration stays in app configuration beside the residency map, because providers are instance configuration, not register objects. The check is a pre-call gate on the provider path and stays in PHP.

## Seed data

- `providerDataUse`: `ollama` `{dataUse: "zero-retention", termsReference: "Own server, Rekencentrum Gemeente Voorbeeld"}`; `anthropic` `{dataUse: "no-training", termsReference: "Anthropic commercial terms, checked 2026-09-01"}`; `openai` left undeclared.
- `ModelPolicy` for "Gemeente Voorbeeld": `allowed` `[{provider: "anthropic", models: []}, {provider: "ollama", models: []}]`, `requireNoTraining: true`.

## Risks

- An admin declares a term the contract does not give. Mitigation: `declaredBy`, `declaredAt` and `termsReference` are kept and shown on every run, so the statement has an owner.
- Turning the switch on breaks running schedules. Mitigation: the policy section names the providers that will be refused before the admin saves, and the refusal message says exactly why.
- A provider's terms change. Mitigation: the run copies the term in force, and the provider form shows when it was last declared.
