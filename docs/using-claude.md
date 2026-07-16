---
title: Using Claude (Anthropic)
sidebar_label: Using Claude
sidebar_position: 30
---

# Using Claude (Anthropic) with Hermiq

Hermiq can run its agents on Anthropic's Claude models. There are **two ways** to authenticate, and it matters which one you use:

| Auth mode | Credential | Scope | Set in |
|---|---|---|---|
| **API key** | An Anthropic API key from the [Anthropic Console](https://console.anthropic.com/) | Organisation (shared) | Admin settings |
| **Claude Max / Pro (OAuth)** | A subscription token you generate from the Claude CLI | **Personal only** | Your personal settings |

> ⚠️ **A Claude Max/Pro subscription is personal.** Per the [Anthropic Terms of Service](https://www.anthropic.com/legal/consumer-terms), a Max/Pro subscription may only be used by the individual who owns it. In Hermiq a Claude Max token may therefore be set **only as a personal token in your own personal settings** — never as an organisation-wide credential, and it is only ever used for *your* agent runs. For a shared, org-wide setup, use an **API key** instead.

The secret (key or token) is always held by the OpenRegister **credential broker** — Hermiq only stores a reference to it and never sees the raw value; the broker injects it into the request to Anthropic at egress.

---

## Option A — Claude Max / Pro subscription (personal)

### 1. Generate a subscription token from the Claude CLI

Install the Claude CLI and log in to your Max/Pro account, then run:

```bash
claude setup-token
```

This prints a long-lived **OAuth token** tied to your personal Claude subscription. Copy it.

> The token is what the Claude CLI itself uses. Note that these tokens **cannot be refreshed headlessly** — if it goes stale, run `claude setup-token` again and update the credential.

### 2. Store the token in your personal credentials

1. Go to **Settings → Personal → Additional settings** (the credential broker).
2. Add a new credential.
3. Choose the provider that matches how you will run it (see step 4 below):
   - **“Anthropic (Claude Max) — CLI subscription”** — for `executionMode: cli`, the ToS-sanctioned path for a
     subscription. Hermiq resolves this token and passes it to the runner's `claude` process environment.
   - **“Anthropic (Claude Max) — OAuth subscription”** — for `executionMode: http`. Note that Anthropic
     **refuses** a subscription token on the direct Messages API, so this rarely works; prefer the CLI entry.
4. Paste the token from step 1. Give it a recognisable name.
5. Save. The token stays in your credential vault; Hermiq stores only a reference to it.

> ⚠️ Keep this credential at **personal** scope. An organisation-scope Claude Max/Pro credential is **refused**
> — the subscription may serve only its owner.

### 3. Point Hermiq at it

1. Open the Hermiq **LLM provider** settings.
2. Set **Provider** to **Anthropic**.
3. Set **Authentication** to **Claude subscription (OAuth)**.
4. Under **Claude subscription (OAuth) credential**, select the credential you just created.
5. Set **Model** to a Claude model, e.g. `claude-opus-4-8`, `claude-sonnet-5`, `claude-haiku-4-5`, or `claude-fable-5`.
6. Save.

Your agents now run on Claude, authenticated with your personal subscription.

### 4. Choose how the subscription runs: `executionMode`

A Claude Max/Pro subscription token is **not entitled to Anthropic's direct Messages API** — Anthropic refuses
it there (an HTTP 429 carrying no rate-limit counters, which is a categorical refusal, not a quota you can wait
out). The Terms-of-Service-sanctioned way to run a subscription is the official `claude` CLI.

Hermiq's Anthropic provider therefore has two transports, selected by `executionMode` in the `anthropicConfig`
block of the LLM settings:

| `executionMode` | Transport | Use it for |
|---|---|---|
| `http` *(default)* | The direct Messages API | An Anthropic **API key** |
| `cli` | The `hermiq-llm-runner` ExApp, running the official `claude` CLI | A **Claude Max/Pro subscription** token |

`executionMode` is configuration, not a UI toggle — set it through the LLM settings API payload
(`anthropicConfig.executionMode`). Anything other than `cli` is treated as `http`.

Running `cli` requires the **AppAPI** app and the **`hermiq-llm-runner`** ExApp to be installed and enabled. If
either is missing, a `cli` turn fails with a 503 that names the missing component — it is never silently served
over `http` instead, because that would be a different transport using a different credential.

> ℹ️ **`cli` is text-only in this release.** The `claude` CLI accepts no tool schema, so a turn that carries
> tools is **refused** with a clear 503 rather than answered without its tools — an agent that silently stopped
> calling its tools would look perfectly healthy. **Tool-using agents must stay on `executionMode: http`** until
> governed MCP support lands. In this release the runner also has **no Nextcloud access at all**.

---

## Option B — Anthropic API key (organisation)

1. Create an API key in the [Anthropic Console](https://console.anthropic.com/).
2. In **Admin settings**, add a credential using the provider **“Anthropic (Claude) — API key”** and paste the key.
3. In the Hermiq **LLM provider** settings, set **Provider** to **Anthropic**, **Authentication** to **API key**, select the credential, and choose a model.

API-key usage is metered and billed to your organisation's Anthropic account, and can be shared org-wide (subject to Hermiq's per-organisation model policy).

---

## Notes

- **Models**: `claude-opus-4-8` (most capable Opus), `claude-sonnet-5` (balanced), `claude-haiku-4-5` (fast), `claude-fable-5` (most capable). Free text is allowed, so you can pin any current Claude model id.
- **Tool use**: on `executionMode: http`, Claude agents can call the same governed tools (MCP + built-ins) as the other providers — approval gates, redaction, per-tool policy, and budgets all apply. On `executionMode: cli` the turn is **text-only**: the `claude` CLI takes no tool schema, so a tool-carrying turn is refused rather than quietly answered without its tools. Keep tool-using agents on `http`.
- **Which to choose**: use the **API key** (`executionMode: http`) for a shared, always-on, metered setup that can use tools; use the **Claude Max subscription** (`executionMode: cli`) for your own personal, text-only use.
