# Discovery: cli-runner-governed-mcp-and-egress

## Question

Can Hermiq dispatch a tool schema to the `claude` CLI and receive tool-call requests back, as
`openspec/changes/llm-cli-runner-exapp/specs/llm-cli-runner-exapp/spec.md` requires? And if not, how does an
agent running in the CLI container get tools and internet access **without** any of Hermiq's governance
moving out of Hermiq?

## Approach Taken

- Probed the real `claude` CLI's `--help` for tool/MCP/permission flags (2026-07-16).
- Probed the Anthropic Messages API with a Claude Max subscription OAuth token, twice, 14h apart.
- Read the runner implementation end to end: `exapp/llm-runner/src/{server.js,runner.js,providers.js,auth.js}`.
- Read the Hermiq governed tool path: `lib/Service/Engine/{ToolLoop,ToolGrantResolver,FacadeToolInvoker}.php`,
  `lib/Mcp/HermiqToolProvider.php`, `lib/Service/WebResearch/WebResearchEgressGuard.php`.
- Grepped for an existing MCP server surface: `grep -n "mcp" appinfo/routes.php`.
- Read `openregister/lib/Service/Credential/CredentialBrokerService.php:189-290` and
  `openregister/lib/Settings/credential-providers.json`.

## Findings

### 1. Tool-schema dispatch to the CLI is impossible — the spec is wrong

`claude -p` **cannot** accept arbitrary tool schemas. Verified flag semantics:

- `--tools <tools...>` — "Specify the list of available tools **from the built-in set**. Use `""` to disable
  all tools, `"default"` to use all tools, or specify tool names (e.g. `"Bash,Edit,Read"`)."
- `--allowedTools` / `--disallowedTools` — filter tool **names** only.
- There is **no** Messages-API-style `tools:[{name,description,input_schema}]` injection.

**Custom tools reach the CLI only via MCP.** Other verified flags: `--mcp-config <configs...>` ("Load MCP
servers from JSON files **or strings**" — inline per-call JSON works), `--strict-mcp-config` ("Only use MCP
servers from `--mcp-config`"), `--permission-mode`, `--settings`, `--append-system-prompt`, `--json-schema`,
`--model`, `--add-dir`, `--max-budget-usd`. `claude -p --output-format json` emits
`{type:"result", result:"<text>", usage:{...}}` — which `providers.js parseClaudeJson()` already parses
correctly.

### 2. The implementation silently disagrees with its own spec (orphaned capability)

- `server.js:110` — `const { provider: providerId, model, messages, credentialEnv } = payload;` — **no `tools`**.
- `server.js:121` — comment falsely claims "`tools` is accepted and passed through the assembled turn".
- `runner.js:112` — `function run({ provider, model, messages, credentialEnv })` — **no `tools`**.
- Therefore `providers.js pickToolCalls()` (`providers.js:119-122`) can only ever return `[]`.

A `cli`-mode turn with tools would run **tool-less** and look perfectly healthy. Currently masked because
`ProviderFactory::createAnthropicDriver()` throws 503 on `executionMode:cli`
(`lib/Service/Llm/ProviderFactory.php:1361-1368`), so the path is unreachable — the defect is latent, not live.

### 3. `--tools ""` is a stronger boundary than expected

`--tools ""` disables **all** built-ins — not just `Bash`/`Read`/`Write`/`Edit` but also **`WebFetch` and
`WebSearch`**. Combined with `--strict-mcp-config`, the CLI can reach *nothing* except the MCP servers Hermiq
names. This makes the container filesystem unreachable to the model **and** means the agent has no route to
the internet other than an MCP tool Hermiq serves. The security boundary is therefore the two flags plus the
MCP config — not the container's network rules alone.

### 4. Hermiq already owns everything the governed endpoint needs

- **Per-agent tool allowlist** — `ToolGrantResolver::resolve($grants, $catalog)` already expands `Agent.tools`
  grants (exact ids, `{app}.{schema}.*` read-only wildcards, `:write` opt-in) with default-deny on
  write/destructive tools and fail-closed on unclassifiable ids (`ToolGrantResolver.php:34-56, 116`).
  `Agent.tools` stays `string[]` — ADR-035 Decision 4 froze the shape. **No schema change is needed.**
- **Governed execution** — `FacadeToolInvoker` already enforces guardrail classification, the approval gate,
  redaction, dry-run neutralisation and run tracing.
- **Governed web access already exists** — `hermiq.webFetch`/`hermiq.webSearch` are registered in
  `lib/Mcp/HermiqToolProvider.php` and gated by `WebResearchEgressGuard`: SSRF blocks
  (loopback/link-local/RFC1918/ULA), an **exact-hostname allowlist** and a denylist, re-resolved per request
  to defeat DNS rebinding (`WebResearchEgressGuard.php:16-25, 182-193`). Both are `scope:'read'`, so they
  auto-allow under default-deny. The allowed-URL system the brief asks for **is already built and governed**.
- **Per-run secret minting has precedent** — `WebhookSecretService`, `ScheduleWebhookSecretService`.

**What does not exist:** an MCP **server** route. `grep -n "mcp" appinfo/routes.php` returns **zero hits**.
Hermiq is today only an MCP *provider* into OpenRegister's registry via `lib/Mcp/HermiqToolProvider.php`.
The server endpoint is genuinely new.

### 5. A Claude Max token is categorically refused by the Messages API

HTTP 429 `{"type":"error","error":{"type":"rate_limit_error","message":"Error"}}`, with
`anthropic-organization-id` present (so it **authenticates**) and `x-should-retry: true`, but **no**
`retry-after` and **no** `anthropic-ratelimit-*` counters — identical after 14h of zero usage. This is a
categorical refusal, not a quota. The official CLI is the only ToS-compliant path; spoofing client identity
is not acceptable and is not proposed.

### 6. The broker cannot proxy a CLI credential

`CredentialBrokerService::request()` **denies** an `inject_only` provider outright
(`CredentialBrokerService.php:189-191`) — an unbounded host is exactly what must never be proxied.
`resolveInjectable()` returns null unless `isInjectOnly()` (`:266`). So a CLI credential **must** be
`inject_only: true` with no `baseUrl`/`allowRules`, and the token necessarily leaves the vault into Hermiq's
PHP process. There is no proxy seam for a CLI — a CLI needs the token in its environment. `resolveInjectable()`
still enforces the owner and `allowedApps` guards.

## Recommendation

**Go**, with the spec corrected:

1. **Drop the tool-schema dispatch requirement** from `llm-cli-runner-exapp` — it is not implementable. Remove
   the false comment at `server.js:121`.
2. **Serve custom tools over MCP from a new Hermiq endpoint**, backed by `ToolGrantResolver` for the
   allowlist and `FacadeToolInvoker` for execution. Reusing both means governance provably cannot drift out
   of Hermiq — the CLI never talks to OpenRegister's MCP.
3. **Lock the CLI down with `--tools ""` + `--strict-mcp-config`**, and assert the exact argv in a test.
4. **Deliver governed internet access as `hermiq.webFetch`/`hermiq.webSearch` over the same endpoint**, not
   as a second forward-proxy endpoint. With `--tools ""` there is no client left in the container that would
   use a proxy for agent web access. **This is one endpoint where the brief asked for two — flagged in
   proposal.md Open Questions, not silently overridden.**
5. **Split the envelope into a chain** (ADR-032) — see proposal.md.

## Risks Uncovered

- **The MCP config carries a bearer token.** `--mcp-config` accepts files *or* strings; a string puts the
  token on `argv`, readable via `/proc`. Must be a **0600 file** in the existing scratch dir
  (`runner.js:118`), mirroring the credential rule at `runner.js:5-8`.
- **The hardening spec must be revised, not contradicted.** `llm-cli-runner-exapp` currently promises the
  runner has **no Nextcloud access**. This change narrows that to exactly one token-gated Hermiq origin.
  Leaving both statements in the specs would be a live contradiction.
- **The fail-open trap is already in the codebase.** `ProviderFactory::callAnthropicChat()` logs a warning and
  runs **text-only** when tools are present without an executor
  (`lib/Service/Llm/ProviderFactory.php:624-635`). The `cli` path must raise instead.

## Next Steps

Proceed to specs for this change (chain link 3). Scaffold the two predecessors —
`cli-runner-credential-declaration` (`config`) and `cli-runner-text-turn-dispatch` (`code`) — once the user
confirms the split and the one-vs-two-endpoints question.
