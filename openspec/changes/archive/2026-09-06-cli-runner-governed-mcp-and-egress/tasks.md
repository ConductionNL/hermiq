# Tasks: cli-runner-governed-mcp-and-egress

Chain link 3 of 3 (ADR-032). Predecessors — `cli-runner-credential-declaration` (`config`) and
`cli-runner-text-turn-dispatch` (`code`) — MUST be closed first; this change assumes the `anthropic-cli`
provider exists and a text-only `cli` turn already dispatches.

## Implementation Tasks

### Task 1: Verify the CLI's http-MCP client behaviour BEFORE building against it
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run`
- **files**: `openspec/changes/cli-runner-governed-mcp-and-egress/discovery.md`
- **acceptance_criteria**:
  - Do this FIRST. `type:"http"` MCP transport is an ASSUMPTION, not a verified fact — this session has been burned three times by plausible-but-false assumptions (stripped headers, phantom tool support, the tool-schema premise). Do not build against it unverified.
  - GIVEN a throwaway http MCP server WHEN `claude -p --mcp-config <file> --strict-mcp-config --tools ""` runs against it THEN confirm the CLI connects, calls `tools/list`, and invokes `tools/call`
  - GIVEN the config declares `headers` WHEN the CLI connects THEN confirm `Authorization` and `OCS-APIRequest` actually arrive at the server — do NOT assume custom headers are forwarded
  - GIVEN `HTTPS_PROXY` is set WHEN the CLI makes its MCP call THEN record whether MCP traffic honours the proxy (this decides whether the Hermiq origins must be proxy-allowlisted or bypassed via `NO_PROXY`)
  - GIVEN the results WHEN they contradict the design THEN STOP and report — do not silently adapt the design
  - Record every finding in discovery.md as verified fact with the exact command used
- [x] Implement
- [x] Test

### Task 2: Correct the `llm-cli-runner-exapp` spec and remove the false passthrough comment
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run`
- **files**: `openspec/changes/llm-cli-runner-exapp/specs/llm-cli-runner-exapp/spec.md`, `exapp/llm-runner/src/server.js`
- **acceptance_criteria**:
  - GIVEN the CLI cannot accept a tool schema WHEN the spec is read THEN the tool-schema dispatch requirement is gone and custom-tools-via-MCP-only is stated
  - GIVEN the governed endpoint needs reachability WHEN "no Nextcloud access" is read THEN it is narrowed to exactly one token-gated Hermiq origin; all other hardening unchanged
  - GIVEN `server.js:110` destructures no `tools` WHEN `server.js:121` is read THEN the false "tools is accepted and passed through" comment is gone
- [x] Implement
- [x] Test

### Task 3: `EgressAuthorizeController` — the governed egress PDP (Endpoint 2)
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy`
- **files**: `lib/Controller/EgressAuthorizeController.php`, `appinfo/routes.php`, `tests/Unit/Controller/EgressAuthorizeControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a `{host, port}` and a valid per-run token WHEN the PDP is called THEN it returns `{allowed, code, message}` straight from `WebResearchEgressGuard::assertSafe()` — the SAME policy source `hermiq.webFetch` uses; do NOT fork a second allowlist
  - GIVEN `assertSafe()` is already public and dependency-free (`WebResearchEgressGuard.php:105`, no constructor, no injected state — verified against HEAD) WHEN wiring it THEN call it directly; no refactor of the guard is needed
  - GIVEN a CONNECT exposes only host:port WHEN deciding THEN decide at host granularity; do NOT attempt TLS interception (rejected — breaks cert pinning, exposes prompt plaintext)
  - GIVEN no/invalid/expired/foreign-run token WHEN called THEN 401/403 before any policy evaluation; the SAME token as Endpoint 1, never a second credential
  - GIVEN the controller WHEN inspected THEN `#[PublicPage]` + `#[NoCSRFRequired]`, with a docblock stating plainly that the per-run token IS the authorization, so gate-9/semantic-auth and the security reviewer read it as intentional
- [x] Implement
- [x] Test

### Task 4: `RunTokenService` — mint, verify, consume per-run tokens
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token`
- **files**: `lib/Service/Llm/RunTokenService.php`, `tests/Unit/Service/Llm/RunTokenServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a dispatched cli turn WHEN a token is minted THEN it is 256-bit via `ISecureRandom`, bound to (runId, agentId, userId), TTL = `RUNNER_TIMEOUT_MS` + 30s, stored in `ICache`
  - GIVEN a missing/malformed/expired/consumed token WHEN verified THEN it is rejected; comparison is constant-time (`hash_equals`)
  - GIVEN a run that closes (success, error, or timeout) WHEN the run ends THEN the token is consumed in a `finally` and later use is rejected
  - GIVEN any code path WHEN logs and error bodies are inspected THEN no token value appears
- [x] Implement
- [x] Test

### Task 5: `McpRunController` — governed JSON-RPC MCP server route
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run`
- **files**: `lib/Controller/McpRunController.php`, `appinfo/routes.php`, `tests/Unit/Controller/McpRunControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a valid token WHEN `tools/list` is called THEN it returns exactly `ToolGrantResolver::resolve($agent->tools, $catalog)` — wildcards read-only, writes only when named, default-deny honoured
  - GIVEN a valid token WHEN `tools/call` is called THEN it dispatches through `FacadeToolInvoker`, so guardrails, the approval gate, redaction, model-policy, budgets and tracing all apply — no second execution path
  - GIVEN a guardrail deny, a pending approval, or an ungranted tool WHEN it responds THEN HTTP 200 with `result.isError: true`, and nothing executed
  - GIVEN a request body naming a different runId/agentId/userId WHEN handled THEN identity is resolved from the token only; the body cannot redirect the run served
  - GIVEN no/invalid token WHEN handled THEN 401 before any tool is resolved; errors are static and generic per ADR-005
  - GIVEN the route WHEN inspected THEN `#[PublicPage]` + `#[NoCSRFRequired]`, and the body calls no `requireAdmin()`/`isAdmin()` (ADR-005 semantic-auth)
- [x] Implement
- [x] Test

### Task 6: `ProviderFactory` cli branch — mint the token, assemble the MCP config, fail loudly
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less`
- **files**: `lib/Service/Llm/ProviderFactory.php`, `tests/Unit/Service/Llm/ProviderFactoryTest.php`
- **acceptance_criteria**:
  - GIVEN a tool-requiring cli turn WHEN dispatched THEN a token is minted and the MCP server config travels to the runner
  - GIVEN the endpoint is unreachable, the token cannot be minted, the MCP config cannot be written, or the resolved tool set is EMPTY WHEN a tool-requiring turn is dispatched THEN `ProviderUnavailableException` (503) naming the cause — never a text-only downgrade
  - GIVEN `callAnthropicChat()` logs a warning and runs text-only at `ProviderFactory.php:624-635` WHEN the cli branch is written THEN it does NOT copy that fail-open behaviour
- [x] Implement
- [x] Test

### Task 7: Runner — governed CLI argv + 0600 MCP config file
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-cli-is-locked-to-hermiqs-governance-by-its-invocation-flags`
- **files**: `exapp/llm-runner/src/server.js`, `exapp/llm-runner/src/runner.js`, `exapp/llm-runner/src/providers.js`, `exapp/llm-runner/test/runner.mcp.test.js`
- **acceptance_criteria**:
  - GIVEN a governed turn WHEN argv is assembled THEN it contains `--tools ""`, `--strict-mcp-config` and `--mcp-config <path>` — asserted exactly, so a regression breaks the build not the boundary
  - GIVEN the config carries a live bearer token WHEN it is passed THEN it is a FILE in the existing scratch dir (`runner.js:118`) at mode 0600 — never an inline string; no token on argv; removed by the existing `cleanup()`
  - GIVEN `--tools ""` or `--strict-mcp-config` is absent WHEN spawning THEN the runner refuses and reports the missing boundary
  - GIVEN `run()` has no `tools` parameter today (`runner.js:112`) WHEN reworked THEN it accepts the governed-MCP parameters it structurally lacks
- [x] Implement
- [x] Test

### Task 8: Egress exception + hardening docs
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy`
- **files**: `exapp/llm-runner/deploy/egress-proxy/`, `exapp/llm-runner/deploy/docker-compose.yml`, `exapp/llm-runner/deploy/egress-allowlist.md`, `exapp/llm-runner/src/runner.js`, `exapp/llm-runner/test/egress.proxy.test.js`, `exapp/llm-runner/README.md`, `docs/`
- **acceptance_criteria**:
  - GIVEN today's "Option B" (`internal: true` + egress proxy) is optional WHEN reworked THEN it becomes the REQUIRED posture and its static allowlist is replaced by a per-CONNECT callout to Hermiq's PDP; the container gets NO default route, so the proxy is its only path out. This is an operator-visible migration off the Option A iptables jail — say so in the docs
  - GIVEN the proxy needs the run identity WHEN the runner spawns the CLI THEN it sets `HTTPS_PROXY=http://run:<token>@egress-proxy:3128` (env, never argv — `runner.js:36-40` already passes proxy vars through) and the PEP forwards the token to the PDP
  - GIVEN the PDP is unreachable/erroring/timing out WHEN a connection is attempted THEN the PEP DENIES — default action DENY; `allowed: true` is the only permit signal. Covered by an explicit fail-closed test
  - GIVEN a build with `--tools ""` absent WHEN the built-in WebFetch hits a non-allowlisted host THEN the proxy still denies it — proving the backstop does not depend on the CLI flags
  - GIVEN egress is configured WHEN the allowlist is read THEN it is exactly `api.anthropic.com` + the Hermiq tools origin + the Hermiq egress origin; every other host is blocked
  - GIVEN the docs WHEN read THEN they state the revised model: non-root, no host/user mounts, NO default route, per-call env-only credentials, two token-gated Hermiq origins, the two complementary layers (per-agent authorization vs network backstop), the CONNECT host-granularity limitation, and that Claude Max is PERSONAL-SCOPE ONLY per the Anthropic ToS
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`); runner changes covered by the ExApp's own container tests
- `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and PHPUnit green
- No Newman/Postman coverage: both governed routes are machine-to-machine and token-gated, not app API surfaces
- Task 1 gates the rest: if the CLI's http-MCP behaviour contradicts the design, STOP and report rather than adapting silently
- `WebResearchEgressGuard` is the ONE policy source for both endpoints — if you find yourself writing a second allowlist, stop
- No Playwright coverage: this change has no UI surface (each spec Scenario carries a reason-bearing `@e2e exclude` for gate-19)
- Every changed public/protected method carries an `@spec` tag (gate-16). Point `@spec` at the canonical `openspec/specs/` path once archived — never at a change dir
- Dutch (`nl_NL`) and English (`en_US`) strings for any operator-facing error surfaced from a failed `cli` turn (ADR-007). Tool errors returned to the model are NOT translated — an LLM consumes them
- Feature documentation updated in `docs/` (ADR-010); deploy via the `documentation` branch
- No seed data task: this change introduces and modifies no OpenRegister schemas (design.md "Seed Data")
- Placeholder safety: any example token/UUID in docs MUST be obviously fake (`YOUR_TOKEN_HERE`, nil UUID) — gitleaks flags entropic-looking values
- Live-verify: install the runner, set `anthropic` to `executionMode: cli` with a personal Claude Max token, run a tool-requiring agent turn, confirm the tool call arrived via Hermiq's governed MCP endpoint, the guardrail/approval path fired, the proxy brokered every connection, and the container reached only `api.anthropic.com` + the two Hermiq origins
- `openspec validate` passes
