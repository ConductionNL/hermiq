## 1. Admin LLM settings endpoint

- [x] 1.1 Create `lib/Controller/Settings/LlmSettingsController.php` — `GET`
      (`get()`) + `PATCH` (`update()`) `/api/settings/llm`, both guarded by
      `#[AuthorizedAdminSetting(\OCA\Hermiq\Settings\AdminSettings::class)]`.
      > Already on development: `lib/Controller/Settings/LlmSettingsController.php`, `get()` and `update()` with `#[AuthorizedAdminSetting(AdminSettings::class)]`.
- [x] 1.2 `get()` returns the current `hermiq.llm` config with every credential
      field (`openaiConfig.apiKey`, `fireworksConfig.apiKey`) replaced by a boolean
      `*Set` flag — a stored secret is never echoed back to the browser.
      > Already on development: `maskCredentials()`; `LlmSettingsControllerTest::testGetMasksCredentials`, `testGetStripsALegacyCleartextApiKey`.
- [x] 1.3 `update()` validates `chatProvider` against
      `LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS` (422 on an unknown provider) and
      merges via `LlmSettingsHandler::updateLLMSettingsOnly()` (PATCH semantics);
      an empty-string credential in the payload is dropped so a blank field never
      wipes a previously-saved key.
      > Already on development: `refusalFor()` (422) and `dropBlankCredentials()`; `testUpdateRejectsUnknownProvider`, `testAllAllowedProvidersAccepted`, `testUpdateDropsBlankCredential`.
- [x] 1.4 Register both routes in `appinfo/routes.php`.
      > Already on development: `appinfo/routes.php`: `Settings\LlmSettings#get` (GET) and `#update` (PATCH) on `/api/settings/llm`.

## 2. Admin provider picker UI

- [x] 2.1 `src/api/llm.js` — `getLlmSettings()` / `patchLlmSettings(payload)` over
      the two endpoints (generateUrl + axios).
      > Already on development: `src/api/llm.js`: `getLlmSettings()`, `patchLlmSettings()`.
- [x] 2.2 `src/modals/LlmProviderModal.vue` (own file, modal-isolation gate) — an
      `NcSelect` provider dropdown with an `inputLabel` (nc-input-labels gate), the
      selected provider's model/url/key fields, and a save that PATCHes and emits
      `saved`. `nextcloud` shows an explanatory hint (background-only, needs a
      TaskProcessing provider installed) and no credential fields.
      > Already on development: `src/modals/LlmProviderModal.vue`, `NcSelect` with `inputLabel`, the `nextcloud` hint without credential fields.
- [x] 2.3 `src/views/AdminRoot.vue` — an LLM section showing the current provider +
      a button opening the modal; refreshes on `saved`.
      > Already on development: `src/views/AdminRoot.vue` opens `LlmProviderModal` and refreshes on `saved`.

## 3. Tests

- [x] 3.1 `tests/Unit/Controller/Settings/LlmSettingsControllerTest.php` — `get()`
      masks credentials to booleans; `update()` rejects an unknown provider (422),
      accepts each allowed provider, and drops empty credential strings.
      > Already on development: `tests/Unit/Controller/Settings/LlmSettingsControllerTest.php` (with `LlmSettingsConnectionReportTest` and `LlmSettingsModelCapabilitiesTest`: 23 tests, green on development).
