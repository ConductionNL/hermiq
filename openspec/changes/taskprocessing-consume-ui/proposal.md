---
kind: code
depends_on: []
---

# Proposal: taskprocessing-consume-ui

## Why

SPECTR-NEXTCLOUD-PLAN.md §8 move 1 ("Consume") adds `nextcloud` as a 4th
`chatProvider` driver so Hermiq's background/non-interactive LLM work
(conversation titles, summaries) can run through Nextcloud's own TaskProcessing
API instead of always needing a directly-configured OpenAI/Ollama/Fireworks key.

The `agent-engine-port` change already shipped the whole **backend** of this move:
`ProviderFactory::generateViaNextcloud()`, `LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS`
already lists `nextcloud`, and `ConversationManagementHandler::generateTextViaConfiguredLlm()`
already branches on `provider === 'nextcloud'` for both title-generation and
summarisation. What is missing is a way for an admin to actually *select* the
provider: Hermiq's `hermiq.llm` config today is only reachable via `occ config:app:set`
— there is no settings surface at all (not for `nextcloud`, and not even for the
pre-existing `openai`/`ollama`/`fireworks` options; the manifest `Settings` page has
only a Version section and `AdminRoot.vue` is an empty placeholder). Without a UI the
driver is not genuinely "selectable", so move 1 is not complete.

This change adds the minimal admin surface that closes the gap: a read + patch
endpoint over `hermiq.llm` and an admin-panel provider picker.

## What Changes

- **`lib/Controller/Settings/LlmSettingsController.php`** (new) — `GET` + `PATCH`
  `/api/settings/llm`, both admin-only via `#[AuthorizedAdminSetting(AdminSettings::class)]`
  (the NC-idiomatic settings-panel guard, mirroring decidesk's `SettingsController`).
  `GET` returns the current `hermiq.llm` config with every credential field masked
  to a boolean "is set" flag (never echo a stored API key back to the browser).
  `PATCH` validates `chatProvider` against `LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS`
  and merges via the existing `updateLLMSettingsOnly()` PATCH semantics.
- **`appinfo/routes.php`** — the two routes above.
- **`src/views/AdminRoot.vue`** — the existing placeholder admin panel gains an LLM
  provider section: current provider display + an "Configure provider" button that
  opens the modal.
- **`src/modals/LlmProviderModal.vue`** (new, own file per the modal-isolation gate) —
  an `NcSelect` provider dropdown (openai / ollama / fireworks / nextcloud, each with
  an `inputLabel` per the nc-input-labels a11y gate) plus the selected provider's
  model/url/key fields, saving through `PATCH /api/settings/llm`.
- **`src/api/llm.js`** (new) — thin `getLlmSettings()` / `patchLlmSettings()` fetch
  helpers.

## Non-Goals

- OpenRegister's `test-chat` and `ollama-models` helper endpoints — deferred; `occ`
  or a follow-up can cover connection testing.
- The `nextcloud` driver's generation code path itself — already shipped by
  `agent-engine-port`; this change only makes it selectable.
- Streaming/embeddings through TaskProcessing — out of scope by design (TaskProcessing
  supports neither; LLPhant stays the path for SSE chat and embeddings).
