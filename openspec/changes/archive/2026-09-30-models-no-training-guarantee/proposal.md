---
kind: code
depends_on: [a-provider-and-a-place-per-ai-feature]
---

# Proposal: models-no-training-guarantee

## Summary

An admin states, for each configured provider, what it does with the data it is sent: zero retention, no training, may train, or not declared, with a reference to the terms or contract that says so. An organisation admin can switch on "Only use providers that never train on our data" in the model policy. A run that would send data to a provider without that term is refused before the call, and the refusal is on the run record. Every run records the data-use term in force at the time, so a privacy officer can show a tender panel that nothing was sent to a provider that trains on it.

## Why

One row, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:td-no-training` | partial, built | build: tender demand for the missing half, a recorded no-training term per provider |

Demand and competitor cells rated yes, quoted from the pack:

- Tender https://www.tenderned.nl/aankondigingen/overzicht/364027: "Gemeente Zaanstad zaaksysteem (2025-01-17) requirement 17539; also UWV 415648 requirement 123258".
- copilot-studio, docs-only, https://learn.microsoft.com/en-us/microsoft-copilot-studio/system-service-card-copilot-studio and https://learn.microsoft.com/en-us/microsoft-copilot-studio/data-privacy-security-web-search: "Microsoft does not train foundation models using customer data". The pack notes that third-party models there run under their own terms.

## What hermiq already has

- A model policy per organisation that limits providers and models, shown on Tenant operations (`src/views/TenantOps.vue:148-171`, `src/api/modelPolicy.js:47`, `:59`, `appinfo/routes.php:291-294`), enforced before every call (`lib/Service/Llm/ProviderFactory.php:650`, `:672-696`). An organisation can already restrict itself to local models this way. Nothing records a no-training term for a hosted provider.
- A residency label per provider, administered and never inferred (`lib/Service/AiFeature/ProviderResidencyRegistry.php:52-92` values, `:131-151` `forProvider()`, `:166-192` `declareResidency()`), stored in `IAppConfig` key `providerResidency`. It is declared through `PUT /api/settings/provider-residency/{provider}` (`appinfo/routes.php:465-470`, `lib/Controller/Settings/ProviderResidencySettingsController.php:105-106`); no screen in `src/` calls that route, so today it is set through the API only.
- A pre-call gate chain with a named step per refusal: model policy, then residency, then redaction (`lib/Service/AiFeature/FeatureProviderResolver.php:183-236`; `ModelPolicyViolationException::STEP` `model-policy`, `ResidencyViolationException::STEP` `residency`). That chain runs only for a run that names an AI feature (`ProviderFactory.php:639`); other runs get the model policy check alone (`:650`).
- The run keeps a copied disclosure of feature, provider, model, residency and location (`lib/Service/Engine/RunTraceCollector.php:189`).

## What this change builds

1. A data-use declaration per provider: `zero-retention`, `no-training`, `may-train` or `undeclared`, with a terms reference. Administered, never inferred, stored next to the residency.
2. `requireNoTraining` on `ModelPolicy`. When it is on, only providers declared `no-training` or `zero-retention` may run for that organisation.
3. A `data-use` step in the pre-call checks, for every run whether or not it names an AI feature, refusing before any data leaves.
4. The data-use term on the run's disclosure, copied at the time of the run.
5. The provider form gains "Where it runs" and "What it does with your data", which also gives the residency declaration the screen it lacks.
6. The model policy section gains the switch, and names the allowed providers that the switch would refuse.

## Out of scope

- Checking a vendor's terms. Hermiq records what an admin states and who stated it; it cannot verify a contract.
- Negotiating zero retention with a vendor. That is procurement.
- The TaskProcessing provider's own terms. The `nextcloud` provider reads `undeclared` until an admin declares what the installed TaskProcessing app does.
