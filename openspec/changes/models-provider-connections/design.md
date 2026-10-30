# Design: models-provider-connections

Kind: code. Size L. The provider list and its config, a direct-HTTP Responses path, the provider modal, a sign-in route on the llm-runner ExApp, and a sign-in button in the user's settings. Two declarative entries in `src/credentials.json` ride along; the matching OpenRegister catalogue entries are OpenRegister's.

## Context at development db6b74dc

- `lib/Service/Llm/LlmSettingsHandler.php:51` `ALLOWED_CHAT_PROVIDERS`; `:152-162` `openaiConfig`; `:168-174` `fireworksConfig` with `baseUrl`; `:180-192` `anthropicConfig` with `scope` and `executionMode`.
- `lib/Service/Llm/BrokerHttpClient.php:22-23` (the URI becomes a path, the host is the broker's host-lock), `:167-227` `sendRequest()`.
- `lib/Service/Llm/ProviderFactory.php`: `instantiateChatDriver()` `:461-496`; `callFireworksChat()` `:755` with the URL at `:762`; `callAnthropicChat()` `:955-1055` (the direct-HTTP tool loop hermiq already owns); `assertPersonalScopeCredential()` `:1647`; `createOllamaDriver()` `:2467` calling the configured URL directly (`:2468-2473`); `createOpenAiDriver()` `:2517-2589`.
- `lib/Controller/Settings/LlmSettingsController.php:85`, `:112` (`#[AuthorizedAdminSetting]`), `:134-148` the organisation-scope refusal for a subscription.
- `src/modals/LlmProviderModal.vue:50-70` OpenAI, `:85-104` Fireworks with Base URL, `:107-160` Anthropic with the subscription warning, `:249-252` auth modes.
- `src/credentials.json:1-34` the declared broker providers, loaded by `src/App.vue:153`.
- `exapp/llm-runner/src/server.js:403-488` routes `/heartbeat`, `/enabled`, `/stage`, `/run`; `exapp/llm-runner/src/providers.js:187-245` adapters, `:230` and `:242` the credential env keys.
- Open changes: `taskprocessing-consume-ui` (the modal and settings controller, present in the code although its tasks are unchecked), `llm-cli-runner-exapp` (the runner), `cli-runner-credential-declaration` (the `anthropic-cli` inject-only provider).

## D1. A provider named for what it is

`openai-compatible` joins `ALLOWED_CHAT_PROVIDERS`, with `openaiCompatibleConfig`: `baseUrl`, `chatModel`, `credentialId` (optional), `apiMode`, and a `label` the admin chooses ("vLLM Rekencentrum", "LiteLLM gateway"). The modal lists it as "OpenAI-compatible endpoint". The `ModelPolicy` provider enum and the residency registry accept it like any other provider, so a policy and a residency label can name it.

Rejected: keep using Fireworks for this. Its Base URL field cannot change the host (D3), and a provider labelled "Fireworks AI" that points at a municipal vLLM server misleads every admin who reads the config later.

## D2. A base URL on the OpenAI provider

`openaiConfig` gains an optional `baseUrl`. Empty means `https://api.openai.com/v1`, today's behaviour. A set base URL follows the same transport rule as D3.

## D3. The host an admin chooses, without hermiq holding a key

`BrokerHttpClient` sends only a path; the broker sends it to the host its catalogue locked for that credential's provider. That is correct for a key, and it is why a typed host cannot work today. Two transports cover a chosen host:

1. No credential (a local server without a key, as Ollama already works): hermiq calls the base URL directly through `IClientService`, the way `createOllamaDriver()` uses its URL. The base URL is admin-set only.
2. A credential: the call goes through the broker, and the broker locks it to the host the admin set when creating the credential. OpenRegister's catalogue needs a proxy provider `openai-compatible` whose host is taken from the credential at creation and cannot change afterwards, with allow-rules for `POST /chat/completions`, `POST /responses` and `GET /models` under that base. Hermiq declares it in `src/credentials.json`. Until OpenRegister ships it, the modal offers the keyless transport only and says "A key needs OpenRegister's OpenAI-compatible credential type."

Rejected: an inject-only credential whose raw key hermiq sends itself. Hermiq kept cleartext keys once, and `BrokerHttpClient` exists to end that.

The Fireworks form keeps its field but calls it "API path" with the host shown read-only from the credential, so it no longer suggests a host it will not use.

## D4. Responses API mode

`apiMode` is `chat_completions` (default) or `responses` on `openaiConfig` and `openaiCompatibleConfig`. In `responses` mode hermiq posts to `{base}/responses` through the same transport, with `instructions` from the system blocks, `input` from the history, and `tools` as function tools. A `function_call` output item runs through the same governed executor `callAnthropicChat()` uses (guardrails, approval gate, redaction, trace) and returns as a `function_call_output` item, looping up to `MAX_TOOL_ITERATIONS`. The final text is the `output_text` of the message items. It is a direct-HTTP path next to `callAnthropicChat()` because that path is already hermiq's and already governed; it does not depend on LLPhant supporting the endpoint.

Hermiq sends `store: false`, so the provider keeps no conversation state and hermiq's own session stays the only record.

## D5. Sign in through the vendor's own CLI

A subscription can only be used through the vendor's CLI (the reason `anthropic-cli` exists), so the sign-in runs that CLI too. Hermiq never becomes an OAuth client of Anthropic or OpenAI.

1. In the user's own settings, next to the credentials list, "Sign in with your Claude subscription" calls `POST /api/subscription-login` with `provider: anthropic` (`#[NoAdminRequired]`, the session user only).
2. Hermiq calls a new runner route `POST /login` over AppAPI. The runner starts `claude setup-token` in a fresh throwaway home and returns a login id and the sign-in URL the CLI prints.
3. The user opens the vendor's page, signs in, and pastes the one-time code the page shows into hermiq's dialog. Hermiq passes it to `POST /login/{id}/code`.
4. The CLI completes the exchange. The runner returns the token once, in that response, and wipes the home directory.
5. Hermiq writes the token into the broker as the session user's personal `anthropic-cli` credential and keeps it nowhere else: not in an object, a config value, a log or the response to the browser. The dialog shows "Signed in. Your Claude subscription is saved as a personal credential."

For OpenAI the same flow runs the Codex CLI's ChatGPT sign-in in its device mode (the user enters a code on OpenAI's page, no paste back), and stores a personal `openai-cli` credential. The runner's OpenAI adapter then writes that credential into the turn's throwaway home as the CLI's auth file (mode 0600), the way it already writes the governed MCP config as a file. When the pinned Codex CLI has no headless sign-in, the button is not shown.

During a sign-in the runner's egress allowlist adds only that vendor's sign-in hosts, for that login id, and drops them when the login ends or after ten minutes.

## D6. Subscriptions stay personal

A signed-in credential is always personal scope. The admin modal never offers a sign-in, and `LlmSettingsController`'s refusal of an organisation-scope subscription (`:134-148`) and `assertPersonalScopeCredential()` stay as they are. The sign-in dialog links the vendor's terms, as the modal already does for Claude.

## Declarative versus imperative

`src/credentials.json` gains `openai-compatible` and `openai-cli` declarations, and the `ModelPolicy` provider enum gains `openai-compatible`. Both are declarative. The catalogue entries themselves are OpenRegister's. Transport choice, the Responses loop and the sign-in are provider-layer code and stay in PHP and in the runner.

## Seed data

- `hermiq.llm` example (documentation, not seeded on install): `chatProvider: "openai-compatible"`, `openaiCompatibleConfig` `{label: "vLLM Rekencentrum", baseUrl: "https://llm.rekencentrum.example.nl/v1", chatModel: "mistral-small-3.2", credentialId: "", apiMode: "chat_completions"}`.
- Residency declaration for it: `on-premise`, location "Rekencentrum Gemeente Voorbeeld".
- No credential is seeded. A key is always entered in the broker by an admin, never shipped: `YOUR_API_KEY_HERE` in the docs example.

## Risks

- A base URL pointed at an internal address. Mitigation: only an admin sets it, and the keyless transport uses the same private-address refusal as `WebResearchEgressGuard` unless the admin ticks "This is a server on our own network".
- A vendor changes its CLI sign-in. Mitigation: the runner pins the CLI version; the button hides when the pinned CLI lacks the flow; pasting a token stays possible.
- A token in transit through hermiq. Mitigation: it passes in one response and one broker write, and PHPUnit asserts it is never logged or returned to the browser.
- Responses API features hermiq does not map (built-in hosted tools, background mode). Mitigation: hermiq sends function tools only and `store: false`; anything else is refused with a clear error.
