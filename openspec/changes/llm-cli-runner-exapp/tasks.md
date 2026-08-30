# Tasks: llm-cli-runner-exapp

## 1. The runner ExApp (new container)

- [ ] 1.1 Scaffold the `hermiq-llm-runner` ExApp: AppAPI manifest + minimal non-root Dockerfile installing the vendor CLIs (`@anthropic-ai/claude-code`; OpenAI + Grok CLIs). Model the image + 0600 env-file credential injection on Hydra's builder image + `scripts/lib/credentials.sh`.
- [ ] 1.2 Implement `POST /run`: accept `{provider, model, messages, tools, credentialEnv}`; require the AppAPI shared secret; run the matching CLI non-interactively (`claude -p …` / OpenAI / Grok equivalents) with the credential in the env only; return `{text, toolCalls, usage}`. No tool execution, no file writes.
- [ ] 1.3 Harden: run non-root; egress allowlist to provider API hosts only (`api.anthropic.com`, `api.openai.com`, Grok host) — deny all other outbound; mount no NC/user/host paths; never log token values.

## 2. Hermiq runner driver

- [ ] 2.1 `LlmSettingsHandler`: add `executionMode` (`http` default | `cli`) to each CLI-capable provider's config sub-block.
- [ ] 2.2 `ProviderFactory`: add the `cli`-mode branch that POSTs the assembled turn to the runner ExApp via a small AppAPI client, maps the response to `ChatDriver` + the six-event SSE envelope, and raises `ProviderUnavailableException` (503) when the ExApp is absent. Route the scoped credential (personal Claude Max OAuth / org API key) as the per-call env, per `anthropic-agent-provider` scope rules.

## 3. Tests + docs

- [ ] 3.1 Unit-test the `cli`-mode driver branch (dispatch shape, ExApp-absent 503, credential-scope routing, tool-calls returned-not-executed). Container test for the ExApp `/run` (secret required; egress-blocked to a non-allowlisted host; token via env only).
- [ ] 3.2 Docs: document the two execution modes, the hardening guarantees (no general internet, no file access), and the personal-only Claude Max rule; link the Anthropic / OpenAI / xAI ToS. Deploy via the `documentation` branch.
- [ ] 3.3 `composer check:strict` + PHPUnit green (hermiq); ExApp container tests green. Live-verify: install the runner ExApp, set an `anthropic` provider to `executionMode: cli` with a personal Claude Max token, run an agent turn, confirm the `claude` CLI produced the response and the container could reach only the provider host.
