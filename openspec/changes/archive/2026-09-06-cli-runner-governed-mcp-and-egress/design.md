# Design: cli-runner-governed-mcp-and-egress

## Architecture Overview

Today `ProviderFactory::callAnthropicChat()` runs the tool loop **in Hermiq**: it calls `postToAnthropic()`
(`lib/Service/Llm/ProviderFactory.php:651`) inside a `for` loop, parses `stop_reason: tool_use`, invokes the
`$toolExecutor` callback (Hermiq's governed engine), and feeds results back. Hermiq is the MCP *client*.

The CLI inverts that. `claude -p` owns its own agent loop and its own MCP client, so Hermiq becomes the MCP
**server**. The governance does not move — only the direction of the call does. Every tool call still lands
in `FacadeToolInvoker`; the same guardrails, approval gate, redaction, tracing and budget apply.

```
   ┌──────────────────────────── Nextcloud host ─────────────────────────────┐
   │  Hermiq (PHP)                                                           │
   │   ProviderFactory ──(1) AppAPI POST /run ──────────────────────┐        │
   │     ├─ resolveInjectable() ─ token ───────────────┐            │        │
   │     └─ RunTokenService::mint(runId,...)           │            │        │
   │                                                   ▼            ▼        │
   │  ENDPOINT 1 — McpRunController ◄──(3) JSON-RPC ────────────────┼────┐   │
   │     ├─ ToolGrantResolver::resolve()  → tools/list              │    │   │
   │     └─ FacadeToolInvoker             → tools/call              │    │   │
   │           ├─ GuardrailPolicyService / ApprovalService          │    │   │
   │           ├─ RedactionService / RunTraceCollector              │    │   │
   │           └─ webFetch/webSearch ─┐                             │    │   │
   │                                  ▼                             │    │   │
   │                       WebResearchEgressGuard::assertSafe()     │    │   │
   │                        (THE shared policy source)              │    │   │
   │                                  ▲                             │    │   │
   │  ENDPOINT 2 — EgressAuthorizeController ◄──(4) allow? ─────────┼──┐ │   │
   └────────────────────────────────────────────────────────────────┼──┼─┼───┘
                                                                    ▼  │ │
   ┌───────────── hermiq-llm-runner (ExApp container) ──────────────┼──┼─┼──┐
   │  server.js /run → runner.js                                    │  │ │  │
   │    writes scratch/mcp.json (0600)                              │  │ │  │
   │    spawn claude -p --output-format json \                      │  │ │  │
   │      --tools "" --strict-mcp-config --mcp-config <path>        │  │ │  │
   │      env: HTTPS_PROXY=http://<runToken>@egress-proxy:3128      │  │ │  │
   │           │                                                    │  │ │  │
   │           └─── ALL outbound ──► egress-proxy (PEP) ────(4) ────┼──┘ │  │
   │                                   │  asks Hermiq per CONNECT   │    │  │
   │                                   ├──(2) api.anthropic.com ────┘    │  │
   │                                   └──(3) MCP → Hermiq ──────────────┘  │
   │   (no default route: `internal: true` network — the proxy is the ONLY   │
   │    path off this container, so there is nothing to bypass)             │
   └────────────────────────────────────────────────────────────────────────┘
   proxy allowlist = api.anthropic.com + <hermiq-tools> + <hermiq-egress>. Nothing else.
```

### Two governed endpoints, deliberately layered

They are **complementary, not redundant**, and both fail closed:

| | **Endpoint 1 — governed TOOLS (MCP)** | **Endpoint 2 — governed EGRESS (proxy PDP)** |
|---|---|---|
| Question it answers | "May **this agent** fetch this URL at all — approved, redacted, audited?" | "May **any process in this container** reach this host, whatever it thinks it is doing?" |
| Layer | Per-agent **authorization** | Network-layer **backstop** |
| Granularity | Full URL + method + agent grants | Host:port (see "The CONNECT limitation") |
| Enforced by | `FacadeToolInvoker` → `WebResearchEgressGuard` | Proxy sidecar (PEP) → Hermiq PDP → same guard |
| Depends on `--tools ""` staying correct? | **Yes** | **No** — this is the point |

**Why both.** `--tools ""` + `--strict-mcp-config` forces every *model-initiated* tool call and web request
through Endpoint 1. That is a strong boundary — but it is a **flag-shaped** boundary. If a flag is dropped, a
future CLI renames `--tools`, or some component makes its own HTTP request, the model silently regains
ungoverned internet. Endpoint 2 removes that dependency: the container has **no default route**
(`internal: true`), so the proxy is the only way off the box, and the proxy asks Hermiq about every
connection. A regression in the flags becomes a *blocked connection*, not a silent governance hole.

**Policy is shared, never forked.** Both paths terminate in the **same**
`WebResearchEgressGuard::assertSafe()` — one allowlist, one denylist, one set of SSRF blocks, one
DNS-rebinding defence. There is exactly one place to change the policy and exactly one place to get it wrong.

**No refactor is needed to share it.** `assertSafe()` (`WebResearchEgressGuard.php:105-147`) is already a
public, dependency-free function taking `(url, isAdminConfiguredEndpoint, allowlist, denylist,
allowInsecureHttp)` and returning `{allowed, code, message}`. The guard has **no constructor and no injected
state**, so the new PDP controller can call it directly. Verified against HEAD — no task is needed to make it
reusable.

### The CONNECT limitation — stated plainly, not papered over

An HTTPS forward proxy sees only `CONNECT host:443`. Once the TLS tunnel is established the proxy **cannot**
see the path, so **Endpoint 2 enforces the allowed-URL list at host granularity, not full-URL granularity**.
Full-URL enforcement would require TLS interception with a MITM CA in the container — deliberately rejected:
it would break certificate pinning, put a forged-cert authority inside the sandbox, and give the proxy
plaintext of every prompt and response.

This is exactly why the two layers are complementary rather than one being redundant: **Endpoint 1 enforces
the full URL** (it sees the request before TLS), **Endpoint 2 enforces the host** (it sees every connection,
including ones Endpoint 1 never hears about). Neither alone is sufficient; together they are.

### Declarative-vs-imperative decision (ADR-031)

**Not applicable, deliberately.** ADR-031's declarative default (`x-openregister-*` in
`lib/Settings/hermiq_register.json`) governs OpenRegister **object behaviour** — lifecycle/state machines,
aggregations, derived fields, notifications, declarative relations, dashboard widgets. This change touches
none of those. It is transport and security infrastructure: a JSON-RPC route, a token lifecycle, and CLI argv.
None of it is expressible as schema-declared object behaviour, and none of it fires on an object write.

The **inputs** to the decisions here remain declarative, which is the spirit of ADR-031 and matches the
precedent `ToolGrantResolver` already set (its own design.md: "the *rule* is code, the *inputs* — grants and
the catalog — are declarative"):

- The per-agent tool allowlist is `Agent.tools` — declarative data on an OpenRegister object.
- The allowed-URL list is admin-configured `IAppConfig` via `WebResearchSettingsHandler`.
- The guardrail policy is `GuardrailPolicyService`'s existing declarative config.

No new Service class is introduced to hold business logic that a schema could declare. The two new classes
(`McpRunController`, `RunTokenService`) are transport and credential plumbing — neither has an
`x-openregister-*` equivalent.

## API Design

Fully specified in `contract.md`. Two endpoints, both bearer-authenticated by the same per-run token:

1. `POST /apps/hermiq/api/mcp/run` — Endpoint 1, the governed tools MCP server. JSON-RPC 2.0 over
   Streamable-HTTP MCP; `initialize`, `tools/list`, `tools/call`.
2. `POST /apps/hermiq/api/egress/authorize` — Endpoint 2, the governed egress PDP. Takes a host:port and
   returns an allow/deny verdict from `WebResearchEgressGuard::assertSafe()`. Consulted by the proxy PEP on
   every `CONNECT`. Fails closed.

## Database Changes

**None.** Hermiq owns no tables (ADR-001 — thin client over OpenRegister).

`Agent.tools` is unchanged: ADR-035 Decision 4 froze it as `string[]` and `ToolGrantResolver` already resolves
exact ids, `{app}.{schema}.*` read-only wildcards, `:write` opt-in, and default-deny on write/destructive
tools. The per-agent allowlist this change needs **already exists** — nothing to add.

The allowed-URL list is already `IAppConfig` state via `WebResearchSettingsHandler`, not an OR schema.

Per-run tokens live in `ICache` (see "Token storage"), not in an OR object or a table.

→ `migration.md` is **skipped**: no tables, no columns, no OpenRegister schema definitions, no data
transformations.

## Nextcloud Integration

- **Controllers**:
  - `OCA\Hermiq\Controller\McpRunController` — new (Endpoint 1). `#[PublicPage]` + `#[NoCSRFRequired]`,
    route in `appinfo/routes.php`. See "Security Considerations" for why this is the correct — and only
    viable — posture, and how it satisfies ADR-005's semantic-auth rule.
  - `OCA\Hermiq\Controller\EgressAuthorizeController` — new (Endpoint 2, the PDP). Same auth posture and the
    same per-run token. Its docblock MUST state plainly that **the per-run token IS the authorization** for
    both controllers, so gate-9/semantic-auth and the security reviewer read `#[PublicPage]` as intentional
    rather than a missed guard.
- **Services**:
  - `OCA\Hermiq\Service\Llm\RunTokenService` — new. Mints, verifies, and consumes per-run tokens. Serves
    both endpoints.
  - Reused unchanged: `Engine\ToolGrantResolver`, `Engine\FacadeToolInvoker`, `Engine\ToolLoop`,
    `Engine\RunTraceCollector`, `GuardrailPolicyService`, `ApprovalService`, `RedactionService`,
    `TenantModelPolicyService`, `TenantControlService`, `BudgetService`, `WebResearch\WebResearchEgressGuard`.
- **OCP interfaces**: `OCP\ICacheFactory` (token store), `OCP\ISession`-free by design, `OCP\IUserManager`
  (resolve the token's user to an `IUser` for the invoker), `OCP\Security\ISecureRandom` (token entropy),
  `OCP\AppFramework\Utility\ITimeFactory` (TTL).
- **Mappers/Entities**: none — Hermiq owns no tables.
- **Events/Hooks**: none.

## Security Considerations

### Authentication — the per-run token

AppAPI's shared secret authenticates **Hermiq→runner** (`exapp/llm-runner/src/auth.js:70-90`: `EX-APP-ID` +
`AUTHORIZATION-APP-API` + an HMAC-SHA256 `AA-SIGNATURE` over the raw body). The **runner→Hermiq** direction
has no credential today, and cannot borrow that one: the CLI's MCP client is a third party that knows nothing
about AppAPI signing, and the container holds no NC session or cookie jar.

`RunTokenService` mints a token when `ProviderFactory` dispatches a `cli` turn:

| Property | Decision | Rationale |
|---|---|---|
| **Entropy** | 256-bit via `ISecureRandom::generate(43, CHAR_ALPHANUMERIC)` | Unguessable; matches `WebhookSecretService` precedent |
| **Binding** | `(runId, agentId, userId, conversationId)` | The token *re-enters an already-authorized run*; it never authenticates a user or elevates anything |
| **Scope** | Exactly the tools `ToolGrantResolver::resolve()` already yields for that agent | Grants nothing new. A stolen token is worth no more than the run it belongs to |
| **Lifetime** | TTL = `RUNNER_TIMEOUT_MS` + 30s slack (default 150s) | The CLI is SIGKILLed at `RUNNER_TIMEOUT_MS` (`runner.js:28,154-158`), so a token outliving the turn has no legitimate caller |
| **Replay** | Valid only while its run is active; **consumed at run close** (success, error, or timeout), in a `finally` | Bounds the window to the run itself. Not single-*use* — one turn legitimately makes many `tools/call`s |
| **Storage** | `ICache` (distributed, TTL-native, auto-expiring) | Wrong place for an OR object: it is ephemeral, high-churn, and must not be auditable-by-object |
| **Transport** | `Authorization: Bearer`, TLS | — |
| **Comparison** | Constant-time (`hash_equals`) | No timing oracle |
| **Logging** | Never logged, never in an error body | ADR-005 |

**ADR-005 deviation, stated plainly.** ADR-005 says "Auth: Nextcloud built-in ONLY. NO custom login, sessions,
tokens." This is a **narrow, deliberate exception** and the design.md is its record. Justification: the caller
is a container with no NC session; the token authenticates a *machine re-entering a run*, not a user; it is
TTL- and run-bounded; and Hermiq already has two precedents for exactly this shape (`WebhookSecretService`,
`ScheduleWebhookSecretService`) for the same reason — an external caller with no session. The alternative
(minting an NC app-password for the runner) is **worse**: it would be long-lived, user-wide, and would grant
the container Hermiq's whole authenticated API surface rather than one run's tool set.

**Semantic-auth (ADR-005 / gate-9) compliance.** `#[PublicPage]` normally forbids a body auth check. Here the
route is genuinely unauthenticated *at the Nextcloud layer* — there is no NC session to check — and the token
gate is the endpoint's actual and only authorization. This mirrors the ADR's own named `#[PublicPage]`
exemplars (OAuth callbacks, webhook receivers). The body MUST NOT call `requireAdmin()`/`isAdmin()`.
`#[NoCSRFRequired]` is correct because there is no cookie-authenticated session for CSRF to attack — the
bearer token is not ambient credential. Per ADR-005's `@NoCSRFRequired` co-change rule, no frontend caller
exists to update; the only caller is the CLI's MCP client.

**IDOR guard.** The token *is* the object reference — the caller never names a runId, so there is no id to
tamper with. `tools/call` resolves the agent and user **from the token**, never from the request body. A
token for run A can never reach run B's tools. All downstream OpenRegister RBAC then applies to the resolved
user exactly as on the `http` path.

### Authenticating Endpoint 2 (the egress PDP) — one token, two endpoints

Endpoint 2 is a **second auth surface** — the honest cost of the two-endpoint design, and the reason the
one-endpoint variant was originally proposed. It is paid for deliberately (see "Two governed endpoints").

**Decision: the same per-run token serves both endpoints. No second credential is minted.**

The token reaches the proxy as **proxy credentials in the proxy URL**, which `runner.js:36-40` already passes
through to the CLI child untouched:

```
HTTPS_PROXY=http://run:YOUR_RUN_TOKEN_HERE@egress-proxy:3128
NO_PROXY=127.0.0.1,localhost
```

The CLI turns that into a `Proxy-Authorization: Basic base64("run:<token>")` header on every `CONNECT`. The
proxy's PEP extracts it and asks Hermiq's PDP (Endpoint 2) whether *this run* may reach *this host*.

**Why one token rather than two:**

| Consideration | Verdict |
|---|---|
| **Scope** | Both endpoints answer questions about the *same run*. A second token would carry identical (runId, agentId, userId) binding and identical lifetime — two names for one authority is complexity without a security gain. |
| **Blast radius** | A leaked token already grants that run's tools via Endpoint 1, which is **strictly more powerful** than reaching an allowlisted host via Endpoint 2. A separate egress token would not reduce the worst case. |
| **Lifecycle** | One mint, one `finally` that frees it. Two tokens means two lifecycles and a real chance of one outliving the run — the bug the TTL exists to prevent. |
| **Revocation** | Closing the run must instantly kill *both* capabilities. One token makes that atomic and impossible to get half-right. |
| **Transport** | It rides in env (`HTTPS_PROXY`), never argv — the same rule `runner.js:5-8` already enforces for the provider credential. |

**Consequence, recorded:** the token now appears in an env var the CLI child reads, so it is visible in the
child's environment (`/proc/<pid>/environ`, readable by that uid). This is the same exposure the provider
credential already accepts by design (`runner.js:122-134`) and is bounded by the same facts: non-root,
single-tenant container, no other user processes, ~150s TTL. It is **not** on argv, which is the exposure
that actually matters (world-readable via `/proc/<pid>/cmdline`).

**The proxy must fail closed.** If the PDP is unreachable, returns a non-200, or times out, the PEP MUST deny
the connection. A proxy that fails open on a PDP outage would silently restore unrestricted internet — the
precise failure mode Endpoint 2 exists to prevent. Configure the PEP's default action as DENY, so an absent
or broken PDP blocks rather than permits.

### The token must not reach the process table

`--mcp-config` accepts "JSON files **or strings**". The inline-string form would put the bearer token on
`argv`, readable by anything that can stat `/proc`. **The runner MUST write the MCP config to a 0600 file in
the existing throwaway scratch dir** (`runner.js:118` already `mkdtemp`s one, already `cleanup()`s it at
`runner.js:226-232`) and pass the path. This mirrors the rule `runner.js:5-8` already states for the provider
credential: env only, never argv, never a log line.

The file contains a live bearer token for the run's lifetime, so: `0600`, owned by the non-root runner user,
inside the per-call scratch dir, and removed by the existing `cleanup()` in the `close`/`error` handlers.

```json
{
  "mcpServers": {
    "hermiq": {
      "type": "http",
      "url": "https://<hermiq-host>/apps/hermiq/api/mcp/run",
      "headers": {
        "Authorization": "Bearer YOUR_RUN_TOKEN_HERE",
        "OCS-APIRequest": "true"
      }
    }
  }
}
```

### The credential trade-off — a conscious weakening, recorded

The `anthropic-cli` provider (predecessor #1) is `inject_only: true` with **no** `baseUrl` and **no**
`allowRules`. This is forced, not chosen: `CredentialBrokerService::request()` **denies** an `inject_only`
provider outright (`CredentialBrokerService.php:189-191`) because an unbounded host must never be proxied, and
`resolveInjectable()` returns null unless `isInjectOnly()` (`:266`). A CLI needs the token in its
**environment** — there is no proxy seam to interpose.

**Consequence, stated plainly:** the Claude Max token leaves the vault into Hermiq's PHP process per call, and
then into the ExApp's environment. This **weakens the broker's central "the app never sees the secret"
property**, which the host-locked proxy providers (`anthropic`, `anthropic-oauth`) do preserve. It is accepted
because there is no alternative for a CLI, and it is bounded:

- `resolveInjectable()` still enforces the **owner/IDOR guard** and the **`allowedApps` guard**.
- The secret still lives in Doriath; the app config holds only a `credentialRef` — never store a secret in a
  schema, which is the whole point of the broker.
- The runner injects it via env only (`runner.js:5-8, 122-134`), allowlists the env var names
  (`selectCredentialEnv()`, `runner.js:89-100` — the caller cannot smuggle `PATH`/`LD_PRELOAD`), never
  persists it between calls, and redacts token-shaped strings from surfaced errors (`runner.js:214-218`).
- Blast radius is one user's subscription token, not an org key.

**Claude Max/Pro OAuth is PERSONAL-SCOPE ONLY per the Anthropic ToS** — reject at organisation scope; it
serves only its owner. Carried forward unchanged from `anthropic-agent-provider`.

### Fail loudly — never a silent tool-less run

The known trap: **a tool-less agent looks completely healthy and simply never calls a tool.** The codebase
already contains this exact bug — `ProviderFactory::callAnthropicChat()` logs a warning and *runs the turn
text-only* when tools are present without an executor (`lib/Service/Llm/ProviderFactory.php:624-635`). Hermiq
pins `tools:['__none__']` elsewhere for precisely this reason. The `cli` path MUST NOT copy it.

Every one of these MUST raise (`ProviderUnavailableException`, 503) before the CLI is spawned or the turn is
accepted — never degrade:

1. The turn requests tools but the governed MCP endpoint is not reachable from the runner.
2. The turn requests tools but the MCP config could not be written or the token could not be minted.
3. The turn requests tools but `ToolGrantResolver::resolve()` yields an **empty** set (an agent granted no
   tools cannot honour a tool-requiring turn).
4. `--tools ""` or `--strict-mcp-config` is absent from the assembled argv (the boundary is gone).

### Input validation

Every `tools/call` `arguments` payload is passed through the **existing** `FacadeToolInvoker` path — the same
validation the `http` tool loop uses. No new validation seam, deliberately: a second path would drift.
`hermiq.webFetch` URLs hit `WebResearchEgressGuard` (SSRF: loopback/link-local/RFC1918/ULA; exact-hostname
allowlist; denylist; re-resolved per request to defeat DNS rebinding — `WebResearchEgressGuard.php:16-25`).

### The hardening spec is revised, not contradicted

`llm-cli-runner-exapp` currently promises the runner has **no Nextcloud access** ("Scenario: no Nextcloud data
is reachable"). That requirement is **MODIFIED**, not quietly broken: the runner gains exactly **two** Hermiq
origins — the governed tools endpoint and the governed egress PDP — each reachable only with a valid per-run
token, serving only that run's granted tools and allowlisted hosts, executed under Hermiq's governance.
Everything else stays denied: no host mounts, no user data, non-root, no general internet.

Net egress allowlist = `api.anthropic.com` + `<hermiq-tools origin>` + `<hermiq-egress origin>`. Nothing else.

Note this is a *narrowing* of container capability overall, not a widening: the container moves from
"unrestricted default route, provider hosts allowlisted by iptables" to "**no default route at all**
(`internal: true`), every connection brokered by a proxy that asks Hermiq". Adding two reachable Hermiq
origins buys the removal of the container's independent path to the internet.

## File Structure

```
lib/
  Controller/
    McpRunController.php            NEW — Endpoint 1. JSON-RPC: initialize / tools/list / tools/call
    EgressAuthorizeController.php   NEW — Endpoint 2 (PDP). Answers allow/deny per CONNECT
  Service/
    Llm/
      RunTokenService.php           NEW — mint / verify / consume per-run tokens (serves BOTH endpoints)
      ProviderFactory.php           MOD — cli branch mints a token, assembles MCP config + proxy env, fails loudly
  Service/WebResearch/
    WebResearchEgressGuard.php      UNCHANGED — already reusable (public, no DI); THE shared policy source
appinfo/
  routes.php                        MOD — POST /api/mcp/run + POST /api/egress/authorize
exapp/llm-runner/
  src/server.js                     MOD — accept mcpConfig+toolsRequired; DELETE the false comment at :121
  src/runner.js                     MOD — write scratch/mcp.json 0600; --tools "" --strict-mcp-config; proxy env
  src/providers.js                  MOD — anthropic args() gains the governed-MCP argv
  deploy/egress-allowlist.md        MOD — Option B becomes REQUIRED; PDP-backed; 3-host allowlist
  deploy/docker-compose.yml         MOD — `internal: true` + egress-proxy sidecar as the required posture
  deploy/egress-proxy/              NEW — proxy (PEP) config + the PDP helper; default action DENY
  README.md                         MOD — the revised two-endpoint hardening model
tests/
  Unit/Controller/McpRunControllerTest.php            NEW
  Unit/Controller/EgressAuthorizeControllerTest.php   NEW
  Unit/Service/Llm/RunTokenServiceTest.php            NEW
exapp/llm-runner/test/
  runner.mcp.test.js                NEW — assert the exact argv; assert 0600; assert no token on argv
  egress.proxy.test.js              NEW — fail-closed on PDP down; deny when --tools "" regresses
openspec/changes/llm-cli-runner-exapp/specs/llm-cli-runner-exapp/spec.md   MOD — corrected contract
```

## Seed Data

**Not applicable.** This change introduces and modifies **no OpenRegister schemas** (see "Database Changes" —
`Agent.tools` is unchanged and already carries the per-agent allowlist; the allowed-URL list is `IAppConfig`;
tokens are `ICache`). ADR-001's seed-data requirement applies to schemas this change introduces or modifies,
of which there are none — so there is nothing to seed and `tasks.md` carries no seed-data task.

The `anthropic-cli` credential provider entry is also not seed data: it is a declarative provider registration
in OpenRegister's `lib/Settings/credential-providers.json`, owned by predecessor
`cli-runner-credential-declaration`.

## Trade-offs

### Governed MCP server in Hermiq vs. pointing the CLI at OpenRegister's MCP

**Rejected: OpenRegister's MCP.** It would be far less work — OR already ships `McpServerController` and a
JSON-RPC server. But it bypasses **every** Hermiq control: guardrails, per-tool approval, redaction,
`TenantModelPolicyService`, evals, budgets, run tracing. The agent would get OR's full tool catalog subject to
OR RBAC alone, ignoring `Agent.tools` entirely. That inverts ADR-001, under which Hermiq owns the agent core
and its governance. **The CLI must never talk to OpenRegister's MCP directly.**

### Two governed endpoints vs. one (governed web access as an MCP tool only)

**Chosen: two — a governed tools (MCP) endpoint AND a governed egress (proxy PDP) endpoint.** Decided by the
user after this design initially proposed one; recorded here with both arguments, because the rejected
alternative is reasonable and the reasoning matters more than the verdict.

**The case for one** (originally proposed, now rejected): `--tools ""` removes the CLI's built-in
`WebFetch`/`WebSearch`, so no *model-initiated* client remains that would use a proxy for web access. The
CLI's own calls to `api.anthropic.com` are LLM transport, not agent internet access. The MCP-tool route already
reuses `WebResearchEgressGuard` and needs no second auth surface, no second token, and no second thing to get
wrong.

**The case for two** (chosen): that argument makes the entire internet boundary depend on `--tools ""`
**remaining correct forever** — a flag-shaped guarantee about a third-party CLI we do not control. If the flag
is dropped in a refactor, renamed upstream, or if any component makes its own HTTP request, the boundary is
silently gone and a healthy-looking agent has unrestricted internet. A forward proxy is **transport-level**:
it catches any egress regardless of what the CLI believes it is doing, and it keeps working when the flags
regress. Given that this change exists precisely *because* a plausible assumption about CLI tool behaviour
turned out to be false, refusing to stake the internet boundary on a second CLI-behaviour assumption is the
consistent call.

**Cost, paid knowingly:** a second endpoint, a second auth surface (mitigated — the same per-run token serves
both; see "Authenticating Endpoint 2"), a proxy sidecar the deployer must run, and host-only granularity on
that layer (see "The CONNECT limitation"). The mitigation for all of it is that **policy is not forked**:
both endpoints terminate in the same `WebResearchEgressGuard::assertSafe()`.

This is cheaper than it looks: `runner.js:36-40` **already** passes `HTTPS_PROXY`/`HTTP_PROXY`/`NO_PROXY`
through to the CLI child, and `deploy/docker-compose.yml` **already** documents the `internal: true` +
egress-proxy topology as "Option B". The proxy is not new infrastructure — it is Option B, promoted from an
alternative to the required posture, with its static allowlist replaced by a live Hermiq PDP.

### Proxy as PDP/PEP vs. a static proxy allowlist

**Chosen: the proxy is a Policy Enforcement Point that asks Hermiq (the Policy Decision Point) per
connection.** The alternative — a Squid config with a hard-coded host list — would fork the policy: an admin
changing the allowlist in Hermiq's settings would not change what the container can reach, and the two would
drift apart silently. Consulting Hermiq per `CONNECT` (via Squid `external_acl_type` or the equivalent) means
`WebResearchEgressGuard::assertSafe()` stays the single source of truth, and the decision is bound to a
specific run rather than being ambient.

A static allowlist remains the correct **fallback** for `api.anthropic.com` itself: the LLM transport host is
fixed, is not agent-chosen, and must work even mid-incident, so pinning it statically avoids making every
turn depend on the PDP being up.

### MCP transport: `type:"http"` vs `stdio`

**Chosen: `http`.** `stdio` would need a shim binary inside the container that proxies to Hermiq — a second
process, a second place for the token to live, and a harder thing to test. `http` is one Hermiq route the CLI
dials directly. **Assumption flagged** in proposal.md Open Questions.

### Token in `ICache` vs. an OpenRegister object

**Chosen: `ICache`.** Tokens are ephemeral, high-churn, and secret. An OR object would make them auditable
artifacts (wrong — a secret must not be persisted as an object), add write amplification per run, and need a
cleanup job. `ICache` is distributed and TTL-native, so expiry is free and correct. The run itself is already
audited via `RunTraceCollector`; the token needs no independent audit trail.

### Chain vs. one envelope (ADR-032)

**Chosen: a three-link chain.** One envelope would be `kind: mixed` — declarative JSON (OR's
`credential-providers.json`, Hermiq's `src/manifest.json`) plus substantial PHP and Node. ADR-032 rejects
`mixed` outright: the two Stage-A specs that failed burned a full 200-turn budget without producing a PR
because a mixed envelope exercises both reviewer surfaces against one budget. The thin-glue exception does not
apply — this is far past ≤20 LOC across ≤2 files. The chain also buys the staging the brief asked for: link 2
(a text-only `cli` turn) ships and is useful before this link lands.

**Mixed-spec rationale: not invoked.** This change is `kind: code` and touches no declarative JSON — the
config surfaces live entirely in predecessor #1.
