---
kind: code
depends_on: []
---

# Proposal: llm-cli-runner-exapp

## Why

The `anthropic-agent-provider` change (merged) lets Hermiq talk to Claude over HTTP via the credential broker. That is the right lightweight default, but it has two limits the team wants to lift:

1. **ToS alignment for subscriptions.** Driving a Claude Max subscription over raw `POST /v1/messages` with an OAuth bearer is a grey area. Running the **actual `claude` CLI** — the tool Anthropic ships for subscription use — sticks much closer to Anthropic's terms. Hydra already proves the model: it runs `claude -p …` under a Max OAuth token in a hardened container with multi-account rotation.
2. **Hermiq is not a container.** It's an in-process PHP app, so it can't host a CLI. But Nextcloud's ExApp (AppAPI) mechanism lets us ship a **separate, optional Docker sidecar** that is a real container we control.

This change specs an **optional, hardened LLM-CLI-runner ExApp**: a network-isolated container that runs the vendor CLIs (`claude`, plus OpenAI and Grok CLIs) and executes a single LLM turn on request. Hermiq dispatches a fully-assembled turn (system prompt + message history + tool schema) to the runner; the runner shells out to the right CLI in non-interactive/print mode and returns the model's text / tool-calls. **Tool execution stays in Hermiq's governed engine** — the runner is only the LLM transport, so guardrails, per-tool approval, redaction, evals, model-policy, and budgets all still apply on Hermiq's side.

One container hosts multiple providers so a single hardened sandbox covers Claude/OpenAI/Grok CLI usage without each contacting the internet at large or touching our files.

## What Changes

- **New ExApp `hermiq-llm-runner`** (AppAPI Docker sidecar): a real container built from a minimal base with the vendor CLIs installed (`@anthropic-ai/claude-code`; OpenAI + Grok CLIs). Exposes a tiny internal HTTP endpoint — `POST /run` — that Hermiq (and only Hermiq, via the AppAPI shared secret) can call.
- **Hardened, Hydra-style**: runs **non-root**; **egress restricted to the LLM provider API hosts only** (`api.anthropic.com`, `api.openai.com`, Grok's API host) — no general internet; **no volume mounts of Nextcloud user data or the host filesystem** — the runner receives only the prompt payload and returns only the completion; credentials injected as env vars from a 0600 env-file for the duration of the call (never on the command line), mirroring Hydra's `credentials.sh` pattern.
- **New Hermiq chat driver path** `runner`: `ProviderFactory` gains a branch that, instead of calling the provider HTTP API directly, POSTs the assembled turn to the ExApp runner and maps the CLI's structured output back into the `ChatDriver` response + the six-event SSE envelope. Selectable per provider (`anthropic`/`openai`/`grok`) via an `executionMode: http | cli` setting; default stays `http` (the broker path). `cli` mode requires the ExApp to be installed and enabled.
- **Credential scope carries over**: personal Claude Max OAuth token → **personal scope**, injected into the runner only for that user's turn (per the Anthropic ToS rule from `anthropic-agent-provider`); org API keys → org scope. The runner is stateless between calls and never persists a token.
- **Docs + ToS**: document the two execution modes and the hardening guarantees; link the Anthropic (and OpenAI/xAI) ToS.

## Capabilities

### New Capabilities

- `llm-cli-runner-exapp`: an optional hardened ExApp sidecar that runs vendor LLM CLIs in a network-isolated, no-file-access sandbox, so Hermiq can back agents with the actual `claude`/OpenAI/Grok CLI (ToS-aligned) instead of only direct HTTP.

### Modified Capabilities

- `anthropic-agent-provider`: gains an `executionMode` (`http` default | `cli`); `cli` routes through the runner ExApp rather than `BrokerHttpClient`.

## Impact

- **New repo/app**: the `hermiq-llm-runner` ExApp (Dockerfile, AppAPI manifest, `/run` service, egress allowlist, non-root user). Modeled on Hydra's hardened image + `credentials.sh` env-file injection.
- **Hermiq PHP**: `ProviderFactory` runner branch + `executionMode` in `LlmSettingsHandler`; a small AppAPI client to reach the sidecar over the shared secret.
- **Ops**: the ExApp is **optional** — instances that don't install it keep the HTTP provider path unchanged. Installing it requires AppAPI/Docker on the deployment.
- **Governance unchanged**: tool loop, approval, redaction, model-policy, budgets remain in Hermiq; the runner never sees OpenRegister data or executes tools.
- **Non-goal**: this is not Claude Code's autonomous coding loop inside Nextcloud — the runner executes exactly one LLM turn per call and does no filesystem/tool work of its own.
