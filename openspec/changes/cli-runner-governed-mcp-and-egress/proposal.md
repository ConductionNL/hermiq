---
kind: code
depends_on:
  - cli-runner-credential-declaration
  - cli-runner-text-turn-dispatch
---

# Proposal: cli-runner-governed-mcp-and-egress

## Summary

The merged `llm-cli-runner-exapp` change specced a hardened ExApp that runs `claude -p` for a Hermiq
turn, and required Hermiq to dispatch a **tool schema** to `POST /run` and receive tool-call requests
back. That requirement is **not implementable**: the `claude` CLI has no Messages-API-style
`tools:[{name,description,input_schema}]` injection. `--tools` selects from the **built-in** set only;
`--allowedTools`/`--disallowedTools` filter tool *names*. Custom tools reach the CLI **only via MCP**.
The implementation matches the CLI, not the spec — and lies about it: `exapp/llm-runner/src/server.js:110`
destructures `{provider, model, messages, credentialEnv}` with **no `tools`**, while the comment at
`server.js:121` claims "`tools` is accepted and passed through". `runner.js:112` likewise has no `tools`
parameter, so `providers.js pickToolCalls()` can only ever return `[]`. This is a textbook
**orphaned-capability** defect: spec'd, implemented, tests green, nothing honours it.

This change corrects the spec to the CLI's real contract and builds the governed transport the corrected
contract demands — **two** Hermiq endpoints the CLI container may reach:

1. **Governed TOOLS (MCP server)** — serves *only* the tools a given agent is granted and executes every call
   through Hermiq's existing governed `ToolLoop`/`FacadeToolInvoker` path.
2. **Governed EGRESS (proxy PDP)** — enforces the allowed-URL list at the network layer, consulted by a
   forward proxy that is the container's only route off the box.

Combined with `--tools ""` (removes every built-in — `Bash`/`Read`/`Write`/`Edit` **and**
`WebFetch`/`WebSearch`) and `--strict-mcp-config` (Hermiq is the only MCP server), **all** tool use and **all**
agent internet access are forced through Hermiq's governance. The two layers are complementary: the MCP tool
answers *"may this agent fetch this URL — approved, redacted, audited?"*, while the proxy answers *"may any
process here reach this host at all, whatever it thinks it is doing?"*. Both terminate in the **same**
`WebResearchEgressGuard` — one policy, never forked — and both fail closed. The allowlist cannot be bypassed
and the CLI cannot touch the container filesystem.

## Motivation

1. **The current spec cannot be implemented and the code silently disagrees with it.** A `cli`-mode turn
   with tools would run tool-less. A tool-less agent looks healthy and simply never calls a tool — the
   fail-open trap Hermiq already guards against elsewhere by pinning `tools:['__none__']`.
2. **`cli` mode is currently dead.** `ProviderFactory::createAnthropicDriver()` (`lib/Service/Llm/ProviderFactory.php:1361-1368`)
   throws 503 on `executionMode:cli` outright. `ChatDriver` already carries the `executionMode` field
   (`lib/Service/Llm/ChatDriver.php:80`) with no consumer.
3. **Governance must not move into the container.** Hermiq owns guardrails, the approval gate, redaction,
   `TenantModelPolicyService`, evals and budgets (ADR-001). If the CLI reached OpenRegister's MCP server
   directly, every one of those would be bypassed. The CLI must never talk to OpenRegister's MCP.
4. **`claude -p` is the ToS-compliant path for a Claude Max/Pro subscription.** Anthropic hard-refuses a
   subscription OAuth token on the raw Messages API (HTTP 429 `rate_limit_error`, `anthropic-organization-id`
   present so it authenticates, but **no** `retry-after` and **no** `anthropic-ratelimit-*` counters,
   identical after 14h of zero usage — a categorical refusal, not a quota). Spoofing client identity is not
   acceptable. Running the official CLI is.

## Affected Projects

- [x] Project: `hermiq` — new governed MCP server endpoint (per-agent tool allowlist + governed execution);
      the `cli` dispatch gains its MCP/`--tools ""` posture; the corrected `llm-cli-runner-exapp` spec.
- [x] Project: `hermiq` (`exapp/llm-runner`) — the runner assembles `--mcp-config`/`--strict-mcp-config`/
      `--tools ""`, removes the false `tools`-passthrough comment, and gains its egress exception.

## Scope

### In Scope

- **Correct the `llm-cli-runner-exapp` spec** to the CLI's real contract: no tool-schema dispatch; custom
  tools via MCP only. Remove the false comment at `server.js:121`.
- **Governed MCP tools endpoint** in Hermiq. `tools/list` returns **only** what
  `ToolGrantResolver::resolve($agent->tools, $catalog)` yields for that agent. `tools/call` dispatches
  through `FacadeToolInvoker`, so guardrails, per-tool approval, redaction, model-policy, tracing, evals
  and budgets all stay in Hermiq. Hermiq is today only an MCP *provider* into OpenRegister's registry
  (`lib/Mcp/HermiqToolProvider.php`); it has **no** MCP server route (`grep -n mcp appinfo/routes.php` =
  zero hits). This is a new surface.
- **Governed egress endpoint (Endpoint 2)** — a Policy Decision Point Hermiq exposes, consulted by a forward
  proxy on every outbound connection from the container. The container loses its default route entirely
  (`internal: true`), so the proxy is the only path off the box. Enforces the allowed-URL list at the
  **network layer**, so it holds even if `--tools ""` regresses.
- **Governed agent internet access** via the existing `hermiq.webFetch`/`hermiq.webSearch` tools over
  Endpoint 1, gated by the existing `WebResearchEgressGuard` (SSRF + exact-hostname allowlist/denylist,
  `lib/Service/WebResearch/WebResearchEgressGuard.php:182-193`). Both endpoints terminate in that **same**
  guard — one policy, never forked.
- **Per-run authentication** for runner→Hermiq: a short-lived bearer token bound to (runId, agentId, userId),
  serving **both** endpoints. AppAPI's shared secret authenticates Hermiq→runner; the reverse direction has
  no credential today.
- **Runner CLI posture**: `--tools ""`, `--strict-mcp-config`, `--mcp-config <0600 scratch file>`, and
  `HTTPS_PROXY` pointed at the governed proxy.
- **Egress exception** for exactly the two Hermiq endpoints. Net allowlist becomes `api.anthropic.com` +
  the Hermiq tools origin + the Hermiq egress origin.

### Out of Scope

- **The `anthropic-cli` credential provider and the Hermiq manifest declaration** — deliberately deferred
  to the `cli-runner-credential-declaration` predecessor (`kind: config`, ADR-032: declarative JSON only).
- **The `cli` text-turn dispatch itself** — deferred to `cli-runner-text-turn-dispatch`.
- **`openai`/`grok` CLI tool support.** `providers.js` ships a `codex` adapter and a `grok` placeholder
  with no verified official CLI. Anthropic only.
- **TLS interception at the proxy.** Deliberately rejected — it would break certificate pinning, place a
  forged-cert authority inside the sandbox, and hand the proxy plaintext of every prompt. The consequence is
  that Endpoint 2 enforces **host** granularity; full-URL enforcement stays with Endpoint 1 (design.md,
  "The CONNECT limitation").
- **Autonomous coding loops.** One governed turn per `/run`.

## Approach

A three-link chain (ADR-032 — this envelope is `config` + `code` and would be `mixed`, an anti-pattern,
if kept whole):

| # | Change | `kind` | Ships |
|---|---|---|---|
| 1 | `cli-runner-credential-declaration` | `config` | OpenRegister `anthropic-cli` inject-only provider + Hermiq `src/manifest.json` credential entry |
| 2 | `cli-runner-text-turn-dispatch` | `code` | `executionMode:cli` → AppAPI `/run`; **text-only, fail loudly if the turn requests tools**; the false-comment removal |
| 3 | **`cli-runner-governed-mcp-and-egress`** (this change) | `code` | Governed MCP endpoint + per-run auth + `--tools ""`/`--strict-mcp-config` + governed web access |

Link 2 is shippable and useful on its own (a text-only `cli` turn), which is the staging the brief asked
for. This change is link 3. Links 1 and 2 are scaffolded and specced; both MUST close before this one starts.

## New Dependencies

None. The MCP server endpoint is hand-rolled JSON-RPC over the existing route stack; the governed tool path,
the egress guard and the secret-minting pattern (`WebhookSecretService`, `ScheduleWebhookSecretService`) all
already exist.

## Impact

- **Two new Hermiq routes + controllers** — the first MCP *server* surface in Hermiq, plus the egress PDP.
  Per ADR-005/ADR-016 each needs an explicit auth posture; both are authenticated by the per-run token,
  **not** an NC session (`#[PublicPage]` + `#[NoCSRFRequired]` with the token as the sole gate — see
  design.md).
- **`exapp/llm-runner/src/{server.js,runner.js,providers.js}`** — MCP config assembly; `run()` gains the
  governed-MCP parameters it structurally lacks today; proxy env wiring.
- **`exapp/llm-runner/deploy/*` — an operator-visible change.** The `internal: true` + egress-proxy topology
  (today "Option B", optional) becomes the **required** posture, and its static allowlist is replaced by a
  live Hermiq PDP callout. Deployers currently on the iptables jail (Option A) must migrate.
- **`WebResearchEgressGuard` is UNCHANGED** — `assertSafe()` is already public and dependency-free, so both
  endpoints reuse it as-is. Verified against HEAD; no refactor task needed.
- **Unchanged**: OpenRegister's MCP server, `Agent.tools` (ADR-035 Decision 4 froze the shape —
  `ToolGrantResolver` already resolves grants, wildcards and default-deny), and every existing `http`-mode
  provider path.

## Cross-Project Dependencies

- **OpenRegister** — consumed only through existing abstractions (`ToolRegistryFacade::listTools()`, the
  credential broker). The `anthropic-cli` provider entry is predecessor #1's job, not this change's.
- **AppAPI 34.0.0** — installed on the dev instance.

## Risks

### Risk 1: The per-run token is a new authentication mechanism outside Nextcloud's session

**Severity:** High — **Mitigation:** ADR-005 says "Nextcloud built-in ONLY. NO custom login, sessions,
tokens." This is a deliberate, narrow exception: the caller is a container with no NC session, and AppAPI's
shared secret only signs the Hermiq→runner direction. The token is single-run, TTL-bounded to the run's max
duration, bound to (runId, agentId, userId), consumed at run close, and grants **nothing** beyond that
run's already-granted tool set. It never authenticates a user — it re-enters an *already authorized* run.
Hermiq has precedent: `WebhookSecretService` and `ScheduleWebhookSecretService`. Design.md details
lifetime, replay and scope.

### Risk 2: The token could leak via the CLI process table

**Severity:** High — **Mitigation:** `--mcp-config` accepts **files or strings**; an inline JSON string puts
the bearer token on `argv`, visible to any process that can read `/proc`. The runner MUST write the MCP
config to a **0600 file in the existing throwaway scratch dir** (`runner.js:118`) and pass the path. This
mirrors the rule already enforced for the provider credential (`runner.js:5-8`: env only, never argv).

### Risk 3: Reachability — the runner must reach Hermiq, which the current hardening forbids

**Severity:** Medium — **Mitigation:** the spec today says the runner has **no Nextcloud access** at all.
This change narrows that to: no *unrestricted* Nextcloud access; exactly one origin, reachable only by a
caller holding a valid per-run token, serving only that agent's granted tools. State the revision plainly
rather than leaving two specs in contradiction.

### Risk 4: A governed tool endpoint that returns an empty tool list fails open

**Severity:** Medium — **Mitigation:** an empty `tools/list` makes the agent look healthy while never
calling a tool. If a turn requests tools and the transport cannot honour them, **raise** — never downgrade
to text-only. Note `ProviderFactory::callAnthropicChat()` currently does exactly the wrong thing at
`lib/Service/Llm/ProviderFactory.php:624-635`: it logs a warning and runs text-only when tools are present
without an executor. The `cli` path must not copy that.

### Risk 5: A flag regression silently restores ungoverned internet

**Severity:** Medium — **Mitigation:** this risk is **why the design has two endpoints**. If `--tools ""` or
`--strict-mcp-config` is dropped in a refactor or renamed upstream, built-in `Bash`/`WebFetch` silently
return. Two independent defences: (a) assert the exact argv in a runner test, so a change breaks the build
rather than the boundary; (b) the governed egress proxy is **transport-level** and does not depend on the
flags at all — with no default route out of the container, a flag regression produces a *blocked connection*,
not a governance hole. Note (b) does not cover the filesystem: a `--tools ""` regression would restore
`Bash`/`Read`/`Write` against the container's own scratch, which the proxy cannot see. That is bounded by the
container being read-only, non-root, and mounting no user data or host paths.

### Risk 6: The egress proxy is a second auth surface and a new fail-open opportunity

**Severity:** Medium — **Mitigation:** the honest cost of two endpoints. Three mitigations: (a) **no second
credential** — the same per-run token authenticates both endpoints, carried as proxy credentials in
`HTTPS_PROXY` (env, never argv), so there is one lifecycle and one revocation; (b) **no forked policy** —
both endpoints terminate in the same `WebResearchEgressGuard::assertSafe()`; (c) **the PEP defaults to
DENY** — if the PDP is unreachable, errors, or times out, the connection is refused. A proxy that failed open
on a PDP outage would restore unrestricted internet, which is exactly what it exists to prevent. Covered by a
dedicated fail-closed test.

### Risk 7: Claude Max/Pro OAuth is personal-scope only

**Severity:** Low — **Mitigation:** per the Anthropic ToS, reject at organisation scope; the token serves
only its owner. Already the rule from `anthropic-agent-provider`; carried forward unchanged.

## Rollback Strategy

Revert in reverse chain order. Dropping this change alone leaves link 2's text-only `cli` mode working;
the governed MCP endpoint has no other consumer. Failing that, `executionMode:http` (the default) is
untouched throughout, so every existing config is unaffected — set any `cli` provider back to `http`.

## Open Questions

**Resolved** (decided 2026-07-16, recorded here so the reasoning survives):

1. ~~Two endpoints or one?~~ **Two** — a governed tools (MCP) endpoint and a governed egress (proxy PDP)
   endpoint. The one-endpoint variant staked the whole internet boundary on `--tools ""` remaining correct
   forever, which is a flag-shaped guarantee about a third-party CLI. Since this change exists precisely
   *because* a plausible assumption about CLI tool behaviour proved false, that bet was declined. See
   design.md "Two governed endpoints, deliberately layered".
2. ~~Chain split?~~ **Accepted.** Both predecessors are scaffolded.
3. ~~MCP transport?~~ **`type:"http"`** over `stdio` — but this is *not yet verified against a live CLI MCP
   client*, so Task 7 verifies it **before** anything is built against it.

**Open:**

4. **Which proxy implementation, and does the deployer accept running it?** The design requires a PEP sidecar
   supporting a per-connection PDP callout (Squid `external_acl_type`, or an equivalent). `deploy/docker-compose.yml`
   already sketches this topology as "Option B"; this change promotes it from an alternative to the required
   posture, which is an operator-visible change. See DEFERRED_QUESTIONS.
