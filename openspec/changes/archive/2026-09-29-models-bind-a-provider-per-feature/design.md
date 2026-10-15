# Design: models-bind-a-provider-per-feature

Read at hermiq development c91e637a.

## Where it lives

- `src/views/AiFeatureRegister.vue`: the table built from `listFeatureResidency()` (`:319-324`) and `runsOn()` (`:336`). Each row gets an action button "Change provider", shown only when the current user may bind (the page is mounted from `AdminRoot.vue`, so the viewer is an administrator; the server's `aifeature.bind` action check stays the authority and a 403 is shown as an error).
- New `src/modals/AiFeatureBindingModal.vue` (ADR-004): `NcSelect` for provider, model and residency, each with `inputLabel` (ADR-004 input labels). Options come from `getEffectiveModelPolicy()`; the residency options are the labels the register already renders through `residencyLabel()` (`:376`).
- `bindAiFeature(id, { provider, model, requiredResidency })` from `src/api/aiFeatures.js:143`. Clearing sends empty strings, which the controller documents as "returns the feature to the policy default".
- No PHP change.

## Decisions

- D1. The model select only offers models of the chosen provider that the policy allows. The server still checks, because a policy can change between loading and saving.
- D2. After saving, the page reloads the residency list rather than patching the row, so the row shows what the server resolved.
