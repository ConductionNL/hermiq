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

## Why this, not the Claude CLI in a container

Considered and rejected: installing the Claude Code CLI / Agent SDK "in the hermiq container" so agents run off Claude Max (the way Hydra's builder containers do). Two decisive reasons:

1. **Hermiq has no container of its own.** It is a standard in-process PHP Nextcloud app (vanilla `appinfo/info.xml`, namespace `Hermiq`, LLPhant), **not** an ExApp/sidecar — so "the hermiq container" is the shared fleet PHP container. Installing a Node agentic CLI there would push it onto the whole fleet.
2. **It would ship a second, ungoverned agent loop.** Claude Code's own tool loop / MCP / permissions would bypass Hermiq's existing engine governance (guardrails, per-tool approval, redaction, evals, per-tenant model policy, budgets). Hermiq already *has* the agent loop; it needs only a chat backend.

Hydra's CLI-in-container model works because each run is an ephemeral, single-identity CI job with 3-account Max-token rotation for weekly limits — the opposite of Hermiq's multi-tenant, per-org, metered design. So `anthropic` enters as a broker-injected chat provider behind the existing `createChatDriver()` chokepoint, inheriting all governance for free.

**Auth guidance — two credential SCOPES, and Claude Max is personal-only:**

The credential the driver uses has a **scope** as well as an `authMode`:

- **Organisation scope** — an admin-configured, org-shared credential (e.g. an Anthropic **API key** the org pays for), set in the app's **admin settings**. Metered, per-tenant attributable, fits `BudgetService`. This is the shipped default (`authMode: api_key`, organisation scope).
- **Personal scope** — a credential the individual user configures in their **personal settings**, used only for their own agent runs.

**Claude Max (`authMode: oauth`) MUST be personal-scope only.** Anthropic's terms permit a Claude Max/Pro subscription only for that individual's own use, so the Max OAuth token may be set **only as a personal token in personal settings** — never as an organisation-wide credential, and never used to serve other users. This keeps it ToS-compliant: the person's own subscription powers only that person's agents. The system MUST refuse an `oauth` credential configured at organisation scope. (Independently, OAuth tokens still can't refresh headlessly — `claude setup-token` is interactive — so a personal Max token may go stale; note this in the help text.)

Both scopes must be settable — API keys typically at organisation scope, personal tokens (incl. Claude Max) at personal scope. Document this in the Hermiq docs and **link the Anthropic Terms of Service** at both the personal-settings help text and the docs page.
