# llm-cli-runner-exapp Specification

## Purpose

An optional, hardened Nextcloud ExApp sidecar that runs vendor LLM CLIs (`claude`, OpenAI, Grok) in a network-isolated, no-file-access container, so Hermiq can back agents with the actual vendor CLI (closer to each provider's ToS) instead of only direct HTTP — without the container being able to reach the internet at large or touch Nextcloud data.

## ADDED Requirements

### Requirement: Optional CLI execution mode routes turns through the runner ExApp

Each Hermiq chat provider that supports a vendor CLI (`anthropic`, `openai`, `grok`) MUST accept an `executionMode` of `http` (default — the existing `BrokerHttpClient` path) or `cli`. In `cli` mode, `ProviderFactory` MUST dispatch the fully-assembled turn (system prompt + message history) to the `hermiq-llm-runner` ExApp's `POST /run` endpoint and map the CLI's structured output back into the `ChatDriver` response and the six-event SSE envelope. `cli` mode MUST fail with a clear `ProviderUnavailableException` (503) when the ExApp is not installed/enabled.

**Corrected 2026-07-16** — this requirement originally said the dispatched turn carries a **tool schema**. It cannot: `claude -p` accepts no tool schema (`--tools` selects from the CLI's BUILT-IN set; `--allowedTools`/`--disallowedTools` only filter names). Verified against the real CLI. Custom tools reach a vendor CLI **only via MCP**, which is why tool-carrying `cli` turns are served by the governed MCP endpoint in `cli-runner-governed-mcp-and-egress`, not by a `tools` field on this payload.

#### Scenario: default execution mode is http

- **GIVEN** an `anthropic` provider with no `executionMode` set
- **WHEN** the driver is resolved
- **THEN** it uses the `BrokerHttpClient` HTTP path (no dependency on the ExApp)
@e2e exclude ExApp sidecar transport — covered by PHPUnit (driver) + the ExApp's own container tests

#### Scenario: cli mode dispatches to the runner

- **GIVEN** `executionMode: cli` and the `hermiq-llm-runner` ExApp installed and enabled
- **WHEN** an agent turn runs
- **THEN** Hermiq POSTs the assembled turn to the ExApp `/run`, and the CLI's completion is returned as the agent response

#### Scenario: cli mode without the ExApp fails clearly

- **GIVEN** `executionMode: cli` but the ExApp is not installed
- **WHEN** the driver is resolved
- **THEN** a `ProviderUnavailableException` (503) is raised naming the missing runner ExApp — no turn is attempted

### Requirement: The runner is hardened — no general internet, no file/host access

The `hermiq-llm-runner` container MUST run **non-root**; MUST restrict network egress to the configured LLM provider API hosts (e.g. `api.anthropic.com`, `api.openai.com`, the Grok API host) **plus exactly the token-gated Hermiq origins that serve governed tools and governed egress**, with **no general internet access**; and MUST NOT mount Nextcloud user data or the host filesystem. It MUST NOT read OpenRegister objects, the user's files, or any host path directly — any tool it invokes goes through Hermiq's governed MCP endpoint, where the per-agent grant, guardrails, approval gate and redaction apply. Its `/run` endpoint MUST require the AppAPI shared secret so only Hermiq can call it.

**Corrected 2026-07-16** — this requirement originally said the container reaches **no Nextcloud host at all**. That is narrowed to exactly the token-gated Hermiq origins introduced by `cli-runner-governed-mcp-and-egress`: without a reachable Hermiq origin the CLI can have no governed tools. All other hardening (non-root, no mounts, no general internet, env-only credentials) is unchanged, and the container still reaches nothing else.

#### Scenario: egress is allowlisted to provider hosts

- **GIVEN** the runner container is running
- **WHEN** any process in it attempts an outbound connection to a host other than an allowlisted provider API host
- **THEN** the connection is blocked

#### Scenario: no Nextcloud data is reachable

- **GIVEN** a `/run` call carrying a turn payload
- **WHEN** the CLI executes
- **THEN** it operates only on the payload; no Nextcloud file, object store, or host path is mounted or readable

#### Scenario: only Hermiq can invoke /run

- **GIVEN** a request to the runner `/run` endpoint without the AppAPI shared secret
- **THEN** it is rejected (401/403) before any CLI is invoked

### Requirement: Credentials are per-turn, scope-correct, and never persisted

The runner MUST receive the provider credential as an environment variable for the duration of a single `/run` call (injected from a 0600 env-file, never on the command line), and MUST NOT persist it between calls. A **Claude Max OAuth token MUST be personal-scope** (the caller's own token, per the `anthropic-agent-provider` ToS rule) and used only for that user's turn; organisation API keys are org-scope. The runner MUST NOT log token values.

#### Scenario: Claude Max token is personal and per-turn

- **GIVEN** a user with a personal Claude Max OAuth token runs a `cli`-mode turn
- **WHEN** Hermiq dispatches to the runner
- **THEN** the runner receives that user's token as a per-call env var, runs `claude` non-interactively, and the token is not persisted or reused for another user

#### Scenario: token never hits the command line or logs

- **GIVEN** any `/run` invocation
- **WHEN** the CLI is executed
- **THEN** the credential is passed via environment only, and no token value appears in process args or logs

### Requirement: The runner executes exactly one governed LLM turn — it does no tool work

The runner MUST execute a single LLM turn per `/run` call (the vendor CLI in non-interactive/print mode) and return the model's text and any tool-call requests. It MUST NOT execute tools, write files, or take autonomous multi-step action. All tool execution, approval gates, redaction, model-policy, evals, and budgets remain in Hermiq's engine.

#### Scenario: tool-calls are returned, not executed

- **GIVEN** the model requests a tool call during a `cli`-mode turn
- **WHEN** the runner returns
- **THEN** the tool-call request is handed back to Hermiq's ToolLoop for governed execution; the runner does not execute it
