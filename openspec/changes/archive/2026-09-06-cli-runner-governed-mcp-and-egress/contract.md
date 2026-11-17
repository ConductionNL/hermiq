# Contract: cli-runner-governed-mcp-and-egress

## Consumers

This change exposes **two** endpoints. Both are authenticated by the same per-run bearer token.

- `hermiq-llm-runner` (the ExApp sidecar, `exapp/llm-runner/`): the **only** intended consumer of Endpoint 1.
  It does not call it itself — it writes the URL and per-run token into the MCP config file it hands to
  `claude`, and the **CLI's own MCP client** speaks JSON-RPC to Hermiq.
- **The egress proxy sidecar (the PEP)**: the only consumer of Endpoint 2. It calls the PDP on every
  `CONNECT` and denies the tunnel unless Hermiq returns `allowed: true`.
- `hermiq` (PHP): mints the per-run token when dispatching an `executionMode:cli` turn and serves both
  endpoints. Not a cross-app consumer.
- **Not a consumer:** OpenRegister. The CLI must never reach OpenRegister's MCP server directly; every tool
  call arrives at Endpoint 1 and is dispatched through Hermiq's `FacadeToolInvoker`.

## Endpoints

### `POST /apps/hermiq/api/mcp/run`

Hermiq's governed MCP server — Streamable-HTTP MCP transport, JSON-RPC 2.0. Serves exactly one run.

**Auth**: `Authorization: Bearer <per-run token>`. **Not** a Nextcloud session — the caller is a container
with no session and no cookie jar. The token is minted per run, bound to (runId, agentId, userId),
single-run scoped, TTL-bounded, and consumed at run close. The route therefore carries `#[PublicPage]` +
`#[NoCSRFRequired]` with the token as the sole gate (see design.md "Authentication"). `OCS-APIRequest: true`
is required on JSON-RPC calls.

The token resolves the acting user; **all** OpenRegister RBAC and Hermiq guardrails then apply to that user
exactly as on the `http` path. The token grants nothing beyond the tools the run's agent was already granted.

#### `tools/list`

**Request:**
```json
{ "jsonrpc": "2.0", "id": 1, "method": "tools/list", "params": {} }
```

**Response (200):**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "tools": [
      {
        "name": "hermiq.webFetch",
        "description": "Fetch a URL. Governed by the configured egress allowlist.",
        "inputSchema": { "type": "object", "properties": { "url": { "type": "string" } }, "required": ["url"] }
      }
    ]
  }
}
```

Returns **only** the tools `ToolGrantResolver::resolve($agent->tools, $catalog)` yields for this run's agent.
An agent granted nothing yields `{"tools": []}` — which the runner treats as a **hard error** when the turn
requested tools (see design.md "Fail loudly"), never as a tool-less run.

#### `tools/call`

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": { "name": "hermiq.webFetch", "arguments": { "url": "https://example.org/" } }
}
```

**Response (200) — success:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": { "content": [{ "type": "text", "text": "<redacted tool result>" }], "isError": false }
}
```

**Response (200) — governed refusal.** A guardrail deny, a pending approval, a non-allowlisted URL or a
tool outside the agent's grants is a **tool-level** error, not a transport error: the model must see it and
adapt, so it returns `isError: true` rather than a JSON-RPC error.

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "content": [{ "type": "text", "text": "Denied: host 'internal.example' is not on the configured allowlist." }],
    "isError": true
  }
}
```

**Errors:**

| Code | Condition |
|------|-----------|
| 400  | Malformed JSON-RPC envelope, or an unsupported `method` |
| 401  | Missing, malformed, expired, consumed or unknown bearer token |
| 403  | Token valid but the run is closed, cancelled, or the tenant kill-switch is engaged |
| 404  | Route unreachable — the governed MCP surface is not enabled on this instance |
| 429  | The run's budget (`BudgetService`) is exhausted |

### `POST /apps/hermiq/api/egress/authorize`

**Endpoint 2 — the governed egress Policy Decision Point.** The proxy sidecar (the PEP) calls this on every
`CONNECT` before opening a tunnel. Hermiq answers from `WebResearchEgressGuard::assertSafe()` — the **same**
policy source Endpoint 1's `hermiq.webFetch` uses, so there is one allowlist and no drift.

**Auth**: `Authorization: Bearer <per-run token>` — the **same token** as Endpoint 1 (design.md,
"Authenticating Endpoint 2"). The PEP extracts it from the `Proxy-Authorization: Basic base64("run:<token>")`
header the CLI sends, because the runner sets `HTTPS_PROXY=http://run:<token>@egress-proxy:3128`.

Because a `CONNECT` exposes only host and port — never the path, which is inside the TLS tunnel — this
endpoint decides at **host granularity**. Full-URL enforcement lives on Endpoint 1. This is a deliberate
limitation, not an oversight: see design.md "The CONNECT limitation".

**Request:**
```json
{ "host": "api.anthropic.com", "port": 443 }
```

**Response (200) — allow:**
```json
{ "allowed": true, "code": null, "message": null }
```

**Response (200) — deny:**
```json
{ "allowed": false, "code": "not_allowlisted", "message": "Host is not on the configured allowlist." }
```

`code` mirrors `WebResearchEgressGuard`'s vocabulary: `not_allowlisted`, `denylisted_host`, `private_address`,
`metadata_address`, `dns_resolution_failed`, `invalid_url`.

**Errors:**

| Code | Condition |
|------|-----------|
| 400  | Missing or malformed `host`/`port` |
| 401  | Missing, malformed, expired, consumed or unknown bearer token |
| 403  | Token valid but the run is closed, cancelled, or the tenant kill-switch is engaged |
| 404  | Governed egress not enabled on this instance |

**The PEP MUST fail closed.** On any non-200, a timeout, a connection error, or an unparseable body, the PEP
MUST deny the tunnel. It MUST NOT treat "PDP unavailable" as "allow" — that would silently restore
unrestricted internet, the exact failure this endpoint exists to prevent. `allowed: true` is the *only*
permit signal.

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | `invalid_request` | Malformed JSON-RPC 2.0 envelope or unsupported method; missing `host`/`port` on the PDP |
| 401 | `invalid_token` | Bearer token missing, expired, already consumed, or not recognised |
| 403 | `run_not_active` | Token authentic but its run is closed/cancelled, or the tenant kill-switch is on |
| 404 | `not_enabled` | Governed MCP endpoint disabled on this instance |
| 429 | `budget_exhausted` | The run exceeded its budget |
| — | `isError: true` (HTTP 200) | Governed refusal: guardrail deny, approval pending, tool not granted, URL not allowlisted |

Per ADR-005, error bodies are static and generic. Never return `$e->getMessage()`; log server-side with
`$this->logger->error()`. A token value MUST NOT appear in any log line or error body.

## Versioning

MCP protocol version is negotiated per the MCP spec during `initialize`. The endpoint is internal to the
Hermiq↔runner pair and shipped from one repo — the runner image and the Hermiq app version move together.
No public API stability is offered or implied. The route is **not** an app-to-app integration surface and is
deliberately absent from `src/manifest.json`'s consumable surface.

## Breaking Change Policy

Because both sides ship from `Conduction/hermiq`, a breaking change is coordinated by version-locking the
runner image to the Hermiq app version. On mismatch the runner MUST refuse to start rather than degrade —
a runner that silently loses its MCP config would run tool-less, which is the exact fail-open this change
exists to prevent. `executionMode:http` is unaffected by any change here.

## SLA

Availability tracks the Hermiq app itself; no separate target. A `tools/call` must return within the run's
remaining budget — `runner.js:28` caps a turn at `RUNNER_TIMEOUT_MS` (default 120000ms) and SIGKILLs the CLI
on expiry, so a tool call that outlives the turn is moot. Per-run tokens expire with the run (design.md),
so a slow call cannot extend the token's life. No availability guarantee is offered to any consumer other
than the runner, which is the only intended caller.
