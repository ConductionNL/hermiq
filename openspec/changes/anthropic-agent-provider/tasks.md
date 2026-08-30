# Tasks: anthropic-agent-provider

## 1. Config surface (PHP)

- [ ] 1.1 `lib/Service/Llm/LlmSettingsHandler.php`: add `anthropic` to `ALLOWED_CHAT_PROVIDERS`; merge an `anthropicConfig` sub-block (`credentialId`, `chatModel`, `authMode`) in `getLLMSettingsOnly()` / save path, mirroring `openaiConfig`. Default `authMode` to `api_key`.

## 2. Anthropic driver (PHP)

- [ ] 2.1 `lib/Service/Llm/ProviderFactory.php`: add the `else if ($chatProvider === 'anthropic')` dispatch branch calling a new `createAnthropicDriver($anthropicConfig, $agentModel, $agentTemperature)`. Guard empty `credentialId` and `BrokerHttpClient::isAvailable()` with `ProviderUnavailableException` (503), same as `createOpenAiDriver`.
- [ ] 2.2 Implement `createAnthropicDriver()` Fireworks-style: return a `ChatDriver(provider:'anthropic', model:$model)` descriptor; generation goes through a new `callAnthropicChat()` that POSTs to `/v1/messages` via `BrokerHttpClient(credentialId, actingUserId)`. Build headers by `authMode`: `api_key` → `x-api-key` placeholder + `anthropic-version`; `oauth` → `Authorization: Bearer` placeholder + `anthropic-version` + `anthropic-beta: oauth-2025-04-20`. Map hermiq's message/tool shape to the Messages API request and the six-event SSE envelope back. `agentModel`/`agentTemperature` overrides apply before `enforceModelPolicy` (unchanged chokepoint).

## 3. Credential-broker provider descriptor (OpenRegister)

- [ ] 3.1 Ensure a `generic-*` **inject-only** Anthropic credential provider exists in OR (api-key variant injecting `x-api-key`; oauth variant injecting `Authorization: Bearer` + the `anthropic-beta: oauth-2025-04-20` header) so Hermiq stores only a `credentialRef`. If absent, add it (paired OR change) and reference its provider id here.

## 4. Settings UI (frontend) + credential scope

- [ ] 4.1 Hermiq LLM settings: add the `anthropic` provider option, a credential selector, a `chatModel` field seeded with `claude-opus-4-8` / `claude-sonnet-5` / `claude-haiku-4-5` / `claude-fable-5` suggestions, and an `authMode` toggle. **Admin settings** expose `organisation`-scope credentials (API keys). **Personal settings** expose `personal`-scope credentials (incl. Claude Max OAuth). The save path MUST reject `authMode: oauth` at `organisation` scope (Claude Max is personal-only per Anthropic ToS).
- [ ] 4.2 Docs + ToS: add a Hermiq docs page (docs/) describing the two scopes and the Claude-Max-personal-only rule, and **link the Anthropic Terms of Service** from both the personal-settings help text and the docs page. Deploy via the `documentation` branch.

## 5. Tests + validate

- [ ] 5.1 Unit test `createAnthropicDriver()` header selection for both `authMode`s (assert `x-api-key` vs `Authorization: Bearer` + oauth beta; assert broker placeholder, never a real secret); missing-credential 503; model-policy block for an out-of-policy Claude model.
- [ ] 5.2 `make check-strict` (or `composer check:strict`) + PHPUnit green in `oc-phpunit-83:local`; hermiq LLM settings lint green. Live-verify: configure an `anthropic` credential, point an agent at a Claude model, send a message through the AI companion, confirm a Claude response.
