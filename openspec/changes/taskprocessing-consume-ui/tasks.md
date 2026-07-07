## 1. Admin LLM settings endpoint

- [ ] 1.1 Create `lib/Controller/Settings/LlmSettingsController.php` — `GET`
      (`get()`) + `PATCH` (`update()`) `/api/settings/llm`, both guarded by
      `#[AuthorizedAdminSetting(\OCA\Hermiq\Settings\AdminSettings::class)]`.
- [ ] 1.2 `get()` returns the current `hermiq.llm` config with every credential
      field (`openaiConfig.apiKey`, `fireworksConfig.apiKey`) replaced by a boolean
      `*Set` flag — a stored secret is never echoed back to the browser.
- [ ] 1.3 `update()` validates `chatProvider` against
      `LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS` (422 on an unknown provider) and
      merges via `LlmSettingsHandler::updateLLMSettingsOnly()` (PATCH semantics);
      an empty-string credential in the payload is dropped so a blank field never
      wipes a previously-saved key.
- [ ] 1.4 Register both routes in `appinfo/routes.php`.

## 2. Admin provider picker UI

- [ ] 2.1 `src/api/llm.js` — `getLlmSettings()` / `patchLlmSettings(payload)` over
      the two endpoints (generateUrl + axios).
- [ ] 2.2 `src/modals/LlmProviderModal.vue` (own file, modal-isolation gate) — an
      `NcSelect` provider dropdown with an `inputLabel` (nc-input-labels gate), the
      selected provider's model/url/key fields, and a save that PATCHes and emits
      `saved`. `nextcloud` shows an explanatory hint (background-only, needs a
      TaskProcessing provider installed) and no credential fields.
- [ ] 2.3 `src/views/AdminRoot.vue` — an LLM section showing the current provider +
      a button opening the modal; refreshes on `saved`.

## 3. Tests

- [ ] 3.1 `tests/Unit/Controller/Settings/LlmSettingsControllerTest.php` — `get()`
      masks credentials to booleans; `update()` rejects an unknown provider (422),
      accepts each allowed provider, and drops empty credential strings.
