---
kind: code
depends_on: []
---

# Proposal: models-bind-a-provider-per-feature

## Summary

In the AI-feature governance register an administrator can choose, per AI feature, which provider and model it runs on and which residency it requires, for example a local model for summaries and a hosted one for translation. The register already shows where each feature runs; this change adds the control that sets it.

## Why

One row of hermiq's capability matrix that is `building`, decided in the build-all pass of 2026-09-28.

| row | own rating | decision |
|---|---|---|
| `hermiq:mo-per-feature` | partial, building | build: six competitors rate yes |

Competitor cells rated yes, quoted from the matrix evidence:

- Hermes Agent v2026.9.24: "hermes_cli/config_defaults.py:715-790 auxiliary.<task> provider/model per feature".
- Open WebUI v0.11.4: "backend/open_webui/config.py:2180 TASK_MODEL and :2182 TASK_MODEL_EXTERNAL for titles, tags, queries and autocomplete, set in src/lib/components/admin/Settings/Interface.svelte:180".
- Dify 1.17.1: "api/controllers/console/workspace/models.py:205 default-model per model type".
- Copilot Studio: https://learn.microsoft.com/en-us/microsoft-copilot-studio/prompt-model-settings "separate model settings for orchestration, deep reasoning, generative responses".

The matrix note on the built half: "grep -rn 'bindAiFeature(' src/ finds only the export in aiFeatures.js, no caller anywhere in src/views, src/modals or src/components: the write endpoint exists server-side with no UI control to invoke it."

## What hermiq already has

- The open change `a-provider-and-a-place-per-ai-feature` (every task ticked, merged in #896, not yet archived): `AiFeature` carries an optional provider, model and `requiredResidency`; `PUT /api/ai-features/{id}/binding` (`aiFeature#bind`, `appinfo/routes.php:457`, `lib/Controller/AiFeatureController.php:174`) writes it behind the `aifeature.bind` action and refuses a binding outside the organisation's model policy with 422.
- `src/api/aiFeatures.js:126-149`: `listFeatureResidency()` and `bindAiFeature()`.
- `src/views/AiFeatureRegister.vue`: the table with provider, model and residency per feature, read-only.
- `src/api/modelPolicy.js:25` `getEffectiveModelPolicy()`: the providers and models the organisation allows.

## What this change builds

1. A "Change provider" action on each row of the AI-feature register.
2. A modal `src/modals/AiFeatureBindingModal.vue` with a provider select and a model select filled from the effective model policy, a residency select, and "Use the organisation default" to clear the binding.
3. The 422 from the policy check shown in the modal in the server's words; on success the row shows the new provider, model and residency.

## Out of scope

- Changing the binding rules or the policy check. Both exist.
- Per-user choice of provider.
