# Tasks: llm-cli-runner-exapp

## 1. The runner ExApp (new container)

- [x] 1.1 Scaffold the `hermiq-llm-runner` ExApp: AppAPI manifest + minimal non-root Dockerfile installing the vendor CLIs (`@anthropic-ai/claude-code`; OpenAI + Grok CLIs). Model the image + 0600 env-file credential injection on Hydra's builder image + `scripts/lib/credentials.sh`.
      > Built: `exapp/llm-runner/` (AppAPI manifest `appinfo/info.xml`; `Dockerfile` installs `@anthropic-ai/claude-code` and `@openai/codex`, runs as `USER node`; a Grok CLI is used when one is supplied, `RUNNER_GROK_BIN`).
- [x] 1.2 Implement `POST /run`: accept `{provider, model, messages, tools, credentialEnv}`; require the AppAPI shared secret; run the matching CLI non-interactively (`claude -p …` / OpenAI / Grok equivalents) with the credential in the env only; return `{text, toolCalls, usage}`. No tool execution, no file writes.
      > Built: `src/server.js` `POST /run`, AppAPI shared-secret check in `src/auth.js`, the CLI spawned per turn with the credential in the child environment only (`src/runner.js`, `selectCredentialEnv`). Tests: `server.run.golden`, `auth.verify.golden`, `runner.env`.
- [x] 1.3 Harden: run non-root; egress allowlist to provider API hosts only (`api.anthropic.com`, `api.openai.com`, Grok host) — deny all other outbound; mount no NC/user/host paths; never log token values.
      > Built: `deploy/egress-proxy` with `egress-allowlist.md`, non-root, read-only root, no mounts. Tests: `egress.proxy`, `deploy.posture` (27 passed in `npm test`, 2026-11-13).

## 2. Hermiq runner driver

- [x] 2.1 `LlmSettingsHandler`: add `executionMode` (`http` default | `cli`) to each CLI-capable provider's config sub-block.
      > Built: `executionMode` (`http` default) on `openaiConfig` and `anthropicConfig` in `LlmSettingsHandler::DEFAULT_CONFIG`. hermiq has no Grok chat provider, so there is no Grok config block to carry it.
- [x] 2.2 `ProviderFactory`: add the `cli`-mode branch that POSTs the assembled turn to the runner ExApp via a small AppAPI client, maps the response to `ChatDriver` + the six-event SSE envelope, and raises `ProviderUnavailableException` (503) when the ExApp is absent. Route the scoped credential (personal Claude Max OAuth / org API key) as the per-call env, per `anthropic-agent-provider` scope rules.
      > Built: the `cli` branch in `ProviderFactory` (AppAPI detection, `CLI_REFUSALS` naming each missing component as a 503, personal-scope enforcement for a Claude Max credential).

## 3. Tests + docs

- [x] 3.1 Unit-test the `cli`-mode driver branch (dispatch shape, ExApp-absent 503, credential-scope routing, tool-calls returned-not-executed). Container test for the ExApp `/run` (secret required; egress-blocked to a non-allowlisted host; token via env only).
      > Built: `ProviderFactoryTest` (`testAnthropicDriverCarriesCliExecutionMode`, `testCliWithoutAppManagerNamesTheMissingComponent`, `testCliRefusesAnOrganisationScopeCredential`, `testCliAcceptsAPersonalScopeCredential`, the completion-mapping cases) and `LlmSettingsHandlerTest`, 67 tests green on 2026-11-13; the runner's container tests in `exapp/llm-runner/test`.
- [x] 3.2 Docs: document the two execution modes, the hardening guarantees (no general internet, no file access), and the personal-only Claude Max rule; link the Anthropic / OpenAI / xAI ToS. Deploy via the `documentation` branch.
      > Built: `docs/exapp-runner.md` and `docs/using-claude.md` (the two modes, the hardening, the personal-only Claude Max rule with the Anthropic terms linked).
- [ ] 3.3 `composer check:strict` + PHPUnit green (hermiq); ExApp container tests green. Live-verify: install the runner ExApp, set an `anthropic` provider to `executionMode: cli` with a personal Claude Max token, run an agent turn, confirm the `claude` CLI produced the response and the container could reach only the provider host.

3.3 stays open: the live check (install the runner ExApp, run a Claude Max turn in `cli` mode, confirm the container reaches only the provider host) needs an instance with AppAPI and Docker.
