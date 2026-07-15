# hermiq-llm-runner

The **container half** of the `llm-cli-runner-exapp` change: an optional,
hardened Nextcloud **AppAPI ExApp** sidecar that runs the actual vendor LLM CLIs
(`claude`, OpenAI Codex, and — when a CLI is supplied — Grok) as a stateless LLM
**transport** for Hermiq.

> The Hermiq-side driver (the `cli` `executionMode` branch in `ProviderFactory`
> + `LlmSettingsHandler`) is a **separate PR**. This directory is only the
> ExApp container, its `/run` service, the AppAPI manifest, hardening
> artifacts, and tests — all new files.

## Why

Driving a Claude Max subscription over raw `POST /v1/messages` is a grey area;
running the **actual `claude` CLI** (the tool Anthropic ships for subscription
use) sticks much closer to the provider ToS. Hermiq is an in-process PHP app and
cannot host a CLI, so this ExApp is a real container we control that does.

Hermiq POSTs a fully-assembled turn to `POST /run`; the runner shells out to the
matching CLI in non-interactive/print mode with the credential injected via the
process **environment only**, and returns `{text, toolCalls, usage}`.
**Tool execution stays in Hermiq's governed engine** — the runner is only the
transport, so guardrails, per-tool approval, redaction, evals, model-policy, and
budgets all still apply on Hermiq's side.

## The `/run` contract

`POST /run` — request body:

```json
{
  "provider": "anthropic",              // anthropic | openai | grok
  "model": "claude-opus-4-8",           // optional; provider default if omitted
  "messages": [                          // assembled turn (system + history)
    {"role": "system", "content": "..."},
    {"role": "user", "content": "..."}
  ],
  "tools": [ ... ],                      // schemas passed through; NEVER executed here
  "credentialEnv": {                     // per-call credential, env-injected only
    "CLAUDE_CODE_OAUTH_TOKEN": "..."
  }
}
```

Response:

```json
{ "text": "...", "toolCalls": [ ... ], "usage": { ... } }
```

- `toolCalls` are the model's tool-call **requests**, returned to Hermiq's
  `ToolLoop` for governed execution. The runner never executes them.
- `GET /heartbeat` → `{"status":"ok"}` (AppAPI health; no auth, no CLI).

### Authentication

Every `/run` call is validated against the **AppAPI shared secret**
(`APP_SECRET`, minted by Nextcloud at registration) **before any CLI is
spawned** (`src/auth.js`):

- `EX-APP-ID` must equal `APP_ID`;
- `AUTHORIZATION-APP-API` must be present (AppAPI's acting-user header);
- `AA-SIGNATURE` must be `HMAC-SHA256(rawBody, APP_SECRET)` (hex), compared in
  constant time.

Missing headers → **401**; present-but-invalid → **403**. An unset `APP_SECRET`
fails closed (401 for everything).

## Providers

| Provider   | CLI package / binary            | Print mode        | Credential env                          | API host           |
|------------|---------------------------------|-------------------|-----------------------------------------|--------------------|
| `anthropic`| `@anthropic-ai/claude-code`     | `claude -p`       | `CLAUDE_CODE_OAUTH_TOKEN` / `ANTHROPIC_API_KEY` | `api.anthropic.com` |
| `openai`   | `@openai/codex`                 | `codex exec`      | `OPENAI_API_KEY`                        | `api.openai.com`   |
| `grok`     | **PLACEHOLDER** (`RUNNER_GROK_BIN`) | `--print`      | `XAI_API_KEY` / `GROK_API_KEY`          | `api.x.ai`         |

> **Grok:** xAI ships no verified official CLI on npm at build time, so no Grok
> package is installed — guessing a package name would be wrong. The `grok`
> provider is wired end-to-end but expects the deployer to supply a CLI binary
> and point `RUNNER_GROK_BIN` at it (its print/non-interactive flag may differ
> from the default in `src/providers.js`). Until then the `grok` provider
> returns a 502.

## Hardening guarantees

1. **Non-root.** The runner process runs as the unprivileged `node` user
   (UID 1000). The default `CMD` never runs privileged; the optional egress jail
   starts root only to install iptables, then `exec gosu node`.
2. **Egress allowlist — provider API hosts only.** No general internet. Two
   enforceable options (see `deploy/`):
   - **A (in-container iptables jail):** `deploy/egress-entrypoint.sh` sets
     `OUTPUT DROP` and allows only DNS + 443 to the resolved provider IPs.
     Needs `--cap-add=NET_ADMIN`.
   - **B (network-layer):** an `internal: true` Docker network (no gateway) plus
     an explicit egress proxy allowlisting the provider hosts.
   The `test.sh` egress checks assert default-deny + no wildcard ACCEPT and that
   a non-allowlisted host is denied.
3. **No Nextcloud / host access.** The container declares **no volumes**. It
   cannot read user files, the OpenRegister object store, or any host path. Its
   only writable surface is a per-call `tmpfs` scratch dir, wiped on exit.
4. **Credential handling.** The provider credential is injected into the CLI
   child process **environment only** — never on argv, never on stdin, never
   logged (error strings are redacted). `credentialEnv` is **allowlisted** to
   the provider's known credential keys, so a caller cannot smuggle `PATH` /
   `LD_PRELOAD` etc. through it. The runner is **stateless** — nothing is
   persisted between calls.
5. **One governed turn.** Exactly one CLI invocation per `/run`. No tool
   execution, no autonomous multi-step action, hard wall-clock timeout and
   output-size cap.

## Build & deploy

```bash
# Build
docker build -t ghcr.io/conductionnl/hermiq-llm-runner:latest exapp/llm-runner

# Register with Nextcloud AppAPI (info.xml declares the /run route + env vars).
# AppAPI mints APP_SECRET; pass it to the container. Then run with hardening:
APP_SECRET=<from-registration> docker compose -f exapp/llm-runner/deploy/docker-compose.yml up -d
```

The runner is **optional** — instances that don't install it keep Hermiq's
default `http` (broker) provider path unchanged.

## Test

No real provider tokens or network egress are needed — the CLIs are stubbed:

```bash
cd exapp/llm-runner && ./test/test.sh
```

Covers: (a) `/run` without the secret is rejected before any CLI runs;
(b) `/run` with the secret + a stubbed CLI returns a completion; (c) the
credential is env-only and never appears in argv/logs/response; (d) the
egress-allowlist mechanism denies a non-allowlisted host.

## Files

```
exapp/llm-runner/
├── Dockerfile              # lean non-root image; installs claude + codex CLIs
├── package.json            # zero runtime deps (pure Node http)
├── appinfo/info.xml        # AppAPI ExApp manifest (/run route, shared-secret contract)
├── src/
│   ├── server.js           # POST /run + /heartbeat; auth-before-CLI
│   ├── auth.js             # AppAPI shared-secret (HMAC) validation
│   ├── providers.js        # provider→CLI adapters + output parsers
│   └── runner.js           # spawn CLI with credential env only; scratch dir
├── deploy/
│   ├── docker-compose.yml  # hardened deployment (iptables jail | proxy network)
│   ├── egress-entrypoint.sh# optional in-container egress jail (root→gosu node)
│   └── egress-allowlist.md # the allowlist + how the deployer enforces it
└── test/
    ├── test.sh             # runnable container/behaviour tests (all green)
    └── stub-cli/{claude,codex}  # stub CLIs (no real tokens/network)
```
