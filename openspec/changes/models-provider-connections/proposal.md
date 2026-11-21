---
kind: code
depends_on: [taskprocessing-consume-ui, llm-cli-runner-exapp, cli-runner-credential-declaration]
---

# Proposal: models-provider-connections

## Summary

An admin can connect any OpenAI-compatible endpoint as a provider named for what it is, with its own base URL, and can give the OpenAI provider a base URL of its own, for example a regional or an Azure endpoint. Both can run in chat completions mode or in the provider's Responses API mode. A user can sign in with a Claude subscription, and with a ChatGPT subscription where the vendor's CLI offers it, through the vendor's own sign-in page instead of pasting a token. The token goes straight into OpenRegister's credential broker as a personal credential, and the personal-only rule for subscriptions stays.

## Why

Three rows, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:mo-openai-compat` | partial, built | build: three competitors rate yes for the missing half, a generic OpenAI-compatible provider and a base URL on the OpenAI provider |
| `hermiq:dm-responses-api` | no | build: a featureRequest demand row and three competitors rate yes |
| `hermiq:dm-subscription-login` | partial, built | build: the featureRequest demand row asks for the missing half, a sign-in flow beyond a pasted Claude token |

Demand and competitor cells rated yes, quoted from the pack:

- `mo-openai-compat`:
  - hermes-agent v2026.9.24: "hermes_cli/model_setup_flows_custom.py:1-3 custom OpenAI-compatible endpoint wizard (custom_providers / providers.<key>)".
  - n8n@2.40.7: "packages/nodes-base/credentials/OpenAiApi.credentials.ts:35-36 'Base URL' field, :80 baseURL taken from it, so the OpenAI chat model node talks to any OpenAI-compatible server".
  - open-webui v0.11.4: "backend/open_webui/routers/openai.py:865 GET /openai/models/{url_idx} over any configured base URL + key; src/lib/components/AddConnectionModal.svelte and admin/Settings/Connections/OpenAIConnection.svelte add arbitrary OpenAI-compatible endpoints".
- `dm-responses-api`: featureRequest https://github.com/open-webui/open-webui/discussions/11874.
  - hermes-agent v2026.9.24: "agent/codex_responses_adapter.py:1-2 'OpenAI Responses API (OpenAI Codex, xAI, GitHub Models and other compatible endpoints)' ... hermes_cli/config_defaults.py:747 api_mode chat_completions | anthropic_messages | codex_responses".
  - n8n@2.40.7: "packages/@n8n/nodes-langchain/nodes/llms/LMChatOpenAi/LmChatOpenAi.node.ts:255-260 'Use Responses API' option".
  - open-webui v0.11.4: "src/lib/components/AddConnectionModal.svelte:51 apiType '' or 'responses'; backend/open_webui/routers/openai.py:1589 is_responses, :1618-1634 posts to {url}/responses".
- `dm-subscription-login`: featureRequest https://community.n8n.io/t/285451 (OAuth2 sign-in for OpenAI).
  - hermes-agent v2026.9.24: "hermes_cli/auth_codex_browser.py:1-6 ChatGPT/Codex sign-in by browser with PKCE (`hermes auth add openai-codex --browser`); hermes_cli/web_routers/oauth.py:1 in-browser device-code login flows in the dashboard".

## What hermiq already has

- Five providers, fixed: `ALLOWED_CHAT_PROVIDERS = ['openai', 'ollama', 'fireworks', 'nextcloud', 'anthropic']` (`lib/Service/Llm/LlmSettingsHandler.php:51`). `openaiConfig` has no base URL (`:152-162`).
- `LlmProviderModal.vue` shows a free-text Base URL only for Fireworks (`src/modals/LlmProviderModal.vue:85-104`); OpenAI has model and credential only (`:50-70`).
- Every hosted call goes through `BrokerHttpClient`, which reduces the request to a path; the host is the credential's host-lock in OpenRegister's broker (`lib/Service/Llm/BrokerHttpClient.php:22-23`, `:188-196`). So the host typed in the Fireworks Base URL field is dropped: `callFireworksChat()` builds `{baseUrl}/chat/completions` (`lib/Service/Llm/ProviderFactory.php:762`) and only its path reaches the broker. Only Ollama calls an admin-typed URL directly (`:2468-2473`).
- Chat completions only: OpenAI through LLPhant's `OpenAIChat` (`ProviderFactory.php:2517-2589`), Fireworks at `/chat/completions`, Anthropic at `/messages` (`:982`). No Responses API.
- Subscriptions: `src/credentials.json:24-33` declares `anthropic-oauth` and `anthropic-cli` as personal credentials; the modal offers "Claude Max subscription (OAuth)" and warns it is personal-only (`LlmProviderModal.vue:107-160`, options at `:249-252`); `LlmSettingsController` refuses a subscription at organisation scope (`lib/Controller/Settings/LlmSettingsController.php:134-148`); the `cli` path refuses a non-personal credential (`ProviderFactory.php:1647`). The user obtains and pastes the token.
- The `hermiq-llm-runner` ExApp runs the vendor CLIs `claude` and `codex` (`exapp/llm-runner/src/providers.js:187-245`); the OpenAI adapter accepts `OPENAI_API_KEY` only (`:242`).

## What this change builds

1. A provider `openai-compatible`, labelled "OpenAI-compatible endpoint", with a base URL, a model and an optional credential.
2. An optional base URL on the OpenAI provider.
3. A way to reach an admin-chosen host without hermiq holding a key: with no credential hermiq calls the URL directly, as it does for Ollama; with a credential the call goes through the broker, locked to the host the admin chose.
4. A Responses API mode on the OpenAI and OpenAI-compatible providers, with tool calls through hermiq's governed tool loop.
5. The Fireworks form stops offering a host it cannot reach.
6. "Sign in with your Claude subscription" in the user's own settings: the runner runs the vendor CLI's own sign-in, and the token goes into the broker as the user's personal `anthropic-cli` credential.
7. The same for a ChatGPT subscription through the Codex CLI, where it offers a headless sign-in, as a personal `openai-cli` credential.

## Out of scope

- OpenRegister's side of the broker: a proxy credential whose host an admin sets once, and an inject-only `openai-cli` provider. Specified here as dependencies, built in OpenRegister, the same way `cli-runner-credential-declaration` did for `anthropic-cli`.
- A hermiq OAuth client for Anthropic or OpenAI. Hermiq does not register its own client id with a vendor or reuse the CLI's; the vendor CLI signs in as itself.
- Organisation-wide subscription credentials. A subscription stays personal (archived `anthropic-agent-provider`, credential scope requirement).
