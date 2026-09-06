# Test Plan: cli-runner-governed-mcp-and-egress

This capability has **no UI surface** — it is a machine-to-machine JSON-RPC route plus container CLI argv.
Every test below is therefore PHPUnit, an ExApp container test, or a manual live-verify. No persona or
accessibility cases apply. Each spec Scenario carries a reason-bearing `@e2e exclude` for gate-19.

## Test Cases

### TC-1: `tools/list` returns only the agent's granted tools
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run`
- **type**: security
- **persona**: n/a — machine-to-machine
- **preconditions**: An agent granting `openregister.contact.*` (read-only wildcard) and nothing else; a valid per-run token for its run
- **steps**: `POST /apps/hermiq/api/mcp/run` with `{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}`
- **expected result**: Only that schema's read verbs are listed. No write/destructive tool. No tool from any other schema. Output equals `ToolGrantResolver::resolve()` exactly. Repeat with an explicit `:write` grant and confirm the write verb appears only when named.
- **test command**: `composer test` — `tests/Unit/Controller/McpRunControllerTest.php`

### TC-2: `tools/call` is governed, and refusals reach the model
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run`
- **type**: security
- **persona**: n/a
- **preconditions**: A run whose agent is granted a tool the guardrail policy classifies `confirm`; a second tool it is not granted
- **steps**: Call `tools/call` for (a) the `confirm`-classified tool, (b) the ungranted tool, (c) a tool a guardrail denies
- **expected result**: (a) routes through `FacadeToolInvoker`, the approval gate fires, the result is redacted and the call lands on the run trace. (b) and (c) return HTTP 200 with `result.isError: true` and an explanatory message — a governed refusal is visible to the model, not a transport error — and nothing executes. No second tool-execution path is reachable.
- **test command**: `composer test` — `tests/Unit/Controller/McpRunControllerTest.php`

### TC-3: Token rejection, run-binding, and expiry
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token`
- **type**: security
- **persona**: n/a
- **preconditions**: A minted token for run A; a closed run B
- **steps**: Call the endpoint with (a) no token, (b) a malformed token, (c) an expired token, (d) a consumed token, (e) run A's token but a body naming run B's runId/agentId/userId, (f) a token whose run has closed
- **expected result**: (a)–(d) and (f) rejected 401/403 **before** any tool is resolved or invoked. (e) serves run A only — identity resolves from the token, never the body, so no id can redirect the run. All bodies are static and generic (ADR-005) with no token value and no internal detail. Comparison is constant-time.
- **test command**: `composer test` — `tests/Unit/Service/Llm/RunTokenServiceTest.php`

### TC-4: The governed CLI argv and the 0600 MCP config file
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-cli-is-locked-to-hermiqs-governance-by-its-invocation-flags`
- **type**: security
- **preconditions**: The runner is dispatched a governed turn with a stub CLI (`RUNNER_ANTHROPIC_BIN`)
- **steps**: Assemble the argv; inspect the spawned child's arguments and the scratch dir; then re-run with `--strict-mcp-config` removed
- **expected result**: argv contains exactly `--tools ""`, `--strict-mcp-config` and `--mcp-config <path>` — asserted verbatim so a rename or drop breaks the build rather than the security boundary (proposal.md Risk 5). No bearer token appears on argv. The config is a **file**, mode `0600`, inside the per-call scratch dir, removed by the existing `cleanup()` after the call. With a flag removed, the runner refuses to spawn and names the missing boundary.
- **test command**: `npm test` in `exapp/llm-runner` — `test/runner.mcp.test.js`

### TC-5: Fail loudly — never a silent tool-less run
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less`
- **type**: regression
- **preconditions**: A tool-requiring `cli`-mode turn
- **steps**: Induce each of the four failure modes: (a) the governed MCP endpoint is unreachable, (b) the token cannot be minted, (c) the MCP config cannot be written, (d) `ToolGrantResolver::resolve()` yields an **empty** set
- **expected result**: Each raises `ProviderUnavailableException` (503) naming the cause **before** the CLI is spawned. No turn runs text-only in any case. Explicitly assert the `cli` branch does **not** reproduce the fail-open at `ProviderFactory.php:624-635`, where the `http` path logs a warning and downgrades to text-only.
- **test command**: `composer test` — `tests/Unit/Service/Llm/ProviderFactoryTest.php`

### TC-6: Egress layer 1 — per-agent authorization at the tool layer
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy`
- **type**: security
- **preconditions**: An allowlist that excludes `internal.example`; the runner container running
- **steps**: Call `hermiq.webFetch` for a URL on `internal.example`, for a loopback/RFC1918 address, and for the cloud-metadata address
- **expected result**: `WebResearchEgressGuard` refuses each — no request leaves Hermiq — and the refusal returns to the model as a tool error it can adapt to. SSRF blocks catch the loopback/RFC1918 targets; the metadata address is blocked unconditionally. This layer sees the full URL.
- **test command**: `composer test` — the guard's existing suite plus the MCP path

### TC-7: Egress layer 2 — the proxy backstop denies, fails closed, and survives a flag regression
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy`
- **type**: security
- **preconditions**: The runner on an `internal: true` network with no default route; the proxy (PEP) is its only path out; Hermiq's PDP reachable
- **steps**: (a) From inside the container, connect to a host that is neither `api.anthropic.com` nor a Hermiq origin. (b) Stop the PDP (or make it time out / return 500) and retry any connection. (c) Rebuild with `--tools ""` absent so the CLI's built-in `WebFetch` returns, and fetch a non-allowlisted host. (d) Remove a host from the allowlist in Hermiq's settings and retry it via both `hermiq.webFetch` and a raw connection.
- **expected result**: (a) The proxy consults the PDP, gets a deny, refuses the tunnel; no packet reaches the host. (b) **Fail closed** — the PEP denies; an unavailable PDP is never read as permission; only `allowed: true` permits. (c) The proxy still denies — proving the backstop does not depend on the CLI flags (the whole point of the two-layer design). (d) Both refuse, proving one policy source with no drift.
- **test command**: `npm test` in `exapp/llm-runner` — `test/egress.proxy.test.js`

### TC-8: The CLI's http-MCP client behaves as assumed (gates the build)
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run`
- **type**: functional
- **preconditions**: A throwaway http MCP server that logs the headers it receives; the real `claude` CLI
- **steps**: Run `claude -p --mcp-config <file> --strict-mcp-config --tools ""` against it. Confirm the CLI connects, calls `tools/list`, and invokes `tools/call`. Inspect whether the config's `headers` (`Authorization`, `OCS-APIRequest`) actually arrive. Set `HTTPS_PROXY` and record whether MCP traffic honours the proxy.
- **expected result**: The CLI speaks http MCP; custom headers arrive intact (if they are stripped, the whole per-run-token-over-MCP design is invalid and must be reworked — **stop and report, do not adapt silently**). The proxy finding decides whether the Hermiq origins need proxy-allowlisting or a `NO_PROXY` bypass. **Run this FIRST** — `type:"http"` is an assumption, and this session has been burned three times by plausible-but-false assumptions about this exact CLI.
- **test command**: manual — record in `discovery.md` with the exact command used

### TC-9: Live-verify a real governed turn end to end
- **spec_ref**: `openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run`
- **type**: functional
- **preconditions**: The runner ExApp installed and enabled (AppAPI 34.0.0); `anthropic` set to `executionMode: cli` with a **personal** Claude Max token via the `anthropic-cli` provider; an agent granted at least one tool
- **steps**: Run a tool-requiring agent turn through the UI and watch the run trace, the approval queue, and the container's outbound connections
- **expected result**: The real `claude` CLI produced the response; the tool call arrived over Hermiq's governed MCP endpoint (not OpenRegister's); the guardrail/approval path fired; the run trace recorded the call; the container reached only `api.anthropic.com` and the Hermiq host. Confirm no token value appears in Hermiq's logs, the runner's logs, or the CLI's process arguments.
- **test command**: manual — `/test-functional`

## Coverage Summary

| Requirement | Covered by | Status |
|---|---|---|
| Governed MCP endpoint scoped to a single run | TC-1, TC-2, TC-8, TC-9 | Covered |
| Short-lived run-scoped token | TC-3, TC-4 (no token on argv), TC-9 | Covered |
| CLI locked by its invocation flags | TC-4, TC-7(c), TC-9 | Covered |
| Allowed-URL governance, two layers, one policy | TC-6 (layer 1), TC-7 (layer 2 + fail-closed + no drift), TC-9 | Covered |
| Fails loudly, never tool-less | TC-5 | Covered |

Scenario-level: every Scenario in all five requirements maps to a case above. The highest-severity risks have
dedicated assertions — the token-on-argv leak (Risk 2) in TC-4, the empty-tool-list fail-open (Risk 4) in
TC-5, the flag regression (Risk 5) in TC-7(c), and the proxy fail-open (Risk 6) in TC-7(b). TC-8 gates the
whole build: it verifies the one remaining CLI-behaviour assumption before anything is built on it.

## Out of Scope

- **UI/Playwright and accessibility** — the capability has no rendered surface. Each spec Scenario carries a
  reason-bearing `@e2e exclude` so gate-19 passes on intent, not omission.
- **Newman/Postman** — the governed MCP route is machine-to-machine and token-gated; it is deliberately not an
  app API surface and is absent from `src/manifest.json`'s consumable surface (contract.md "Versioning").
- **`openai`/`grok` CLI tool support** — out of scope for the change; `providers.js` ships a `codex` adapter
  and an unverified `grok` placeholder.
- **The `anthropic-cli` provider and the manifest declaration** — predecessor `cli-runner-credential-declaration`.
- **The text-only `cli` dispatch** — predecessor `cli-runner-text-turn-dispatch`.
- **Anthropic's own ToS enforcement** — we comply by running the official CLI; we do not test Anthropic's
  behaviour. The Messages-API refusal of a Max token is recorded in discovery.md as a verified finding, not a
  test case.
