---
kind: code
depends_on: []
---

# Proposal: anthropic-agent-provider

## Why

Hermiq's agent engine supports four chat providers (`openai`, `ollama`, `fireworks`, `nextcloud`) but not Anthropic/Claude directly. Teams that hold a **Claude subscription (Claude Max)** or an Anthropic API key cannot point a Hermiq agent at Claude, so the Claude integration is untested and unavailable as an agent backend. Adding a first-class `anthropic` provider lets operators run agents on Claude models and validates the Claude path end-to-end (the AI companion widget, now defaulting to hermiq, would then be answerable by Claude).

Two Anthropic auth modes must both work, because Claude Max is not an API key:

- **API key** — `x-api-key: <key>` + `anthropic-version` header (standard Console/API key).
- **Claude Max / OAuth** — `Authorization: Bearer <oauth-token>` + `anthropic-beta: oauth-2025-04-20` header (the mechanism the Claude CLI uses; the subscription token, not an API key).

Both secrets are held by the OpenRegister credential broker (never in Hermiq), consistent with the openai/fireworks providers.

## What Changes

- **New `anthropic` chat provider** in `LlmSettingsHandler::ALLOWED_CHAT_PROVIDERS` and the `llm` config shape (`anthropicConfig` sub-block: `credentialId`, `chatModel`, `authMode` = `api_key` | `oauth`).
- **`ProviderFactory::createAnthropicDriver()`** — modeled on the existing **Fireworks driver** (direct HTTP through `BrokerHttpClient` to `POST /v1/messages`, no LLPhant `AnthropicChat` instance, because LLPhant's chat requires a concrete Guzzle client and gives no seam for OAuth headers). The driver builds the request with the auth headers for the selected `authMode`; the broker injects the real secret at egress. Adaptive thinking / effort and the six-event SSE envelope hermiq already emits are preserved.
- **Model-policy enforcement is already covered**: `createChatDriver()` calls `enforceModelPolicy(provider, model)` at the single chokepoint after driver construction; the new provider passes through it unchanged (per-org allowlists apply to `anthropic` models automatically).
- **Credential-broker provider descriptor** (OpenRegister side, `generic-*` inject-only): a provider that injects an arbitrary Anthropic key **or** OAuth token into the outbound request under `configuration.authentication`, so Hermiq stores only a `credentialRef` and the secret lives in Doriath (per the app-side credential-injection pattern). API-key mode injects `x-api-key`; OAuth mode injects `Authorization: Bearer` + the `anthropic-beta: oauth-2025-04-20` header.
- **LLM settings UI**: `anthropic` option in the provider picker, credential selector, model field, and an `authMode` toggle (API key vs Claude subscription).
- **Model list**: seed current Anthropic model IDs (`claude-opus-4-8`, `claude-sonnet-5`, `claude-haiku-4-5`, `claude-fable-5`) as suggestions; free-text allowed.

## Capabilities

### New Capabilities

- `anthropic-agent-provider`: Hermiq agents can run on Anthropic Claude models via an API key or a Claude Max OAuth subscription, with the secret held by the credential broker.

### Modified Capabilities

(none — additive to the existing provider set)

## Impact

- **PHP**: `lib/Service/Llm/LlmSettingsHandler.php` (ALLOWED list + config merge), `lib/Service/Llm/ProviderFactory.php` (`createAnthropicDriver()` + dispatch branch), tests under `tests/Unit/Service/Llm/`.
- **Frontend**: Hermiq LLM settings component (provider option + authMode toggle).
- **Cross-repo (OpenRegister)**: one `generic-*` inject-only credential-provider descriptor for Anthropic (api-key and oauth variants). Tracked as a paired change if it doesn't already exist.
- **Runtime**: operator configures the credential in the broker, selects `anthropic` + authMode in Hermiq LLM settings, sets an agent's model to a Claude model. No migration.
- **ToS note**: using a Claude Max subscription token programmatically is the operator's responsibility; Hermiq only provides the transport. Documented in the settings help text.
