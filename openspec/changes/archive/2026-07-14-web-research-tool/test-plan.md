# Test Plan: web-research-tool

## Test Cases

### TC-1: web.search reports unavailable with no backend configured
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-pluggable-admin-configured-search-backend`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator) — builds an agent expecting a clear, actionable error rather than a silent failure
- **preconditions**: `hermiq.webResearch.searchProvider` is empty (fresh install default)
- **steps**: invoke `hermiq.webSearch` with a query via the agent chat/tool-call surface
- **expected result**: a structured error `{"error": {"code": "search_unavailable", ...}}` is returned; no outbound HTTP request is made (verified via network log/mock)
- **test command**: `/test-functional`

### TC-2: web.search against a configured SearXNG instance returns ranked results
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-admin-configures-a-self-hosted-searxng-instance`
- **type**: functional
- **preconditions**: admin has configured `searchProvider=searxng` and a reachable test SearXNG endpoint
- **steps**: invoke `hermiq.webSearch` with a query
- **expected result**: response contains an array of `{title, url, snippet}` results parsed from the SearXNG JSON shape
- **test command**: `/test-functional`

### TC-3: web.fetch rejects a URL resolving to a private address
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-egress-guard-blocks-ssrf-shaped-destinations-for-webfetch`
- **type**: security
- **preconditions**: a test hostname resolving to `10.0.0.5` (or a hosts-file entry pointing a test domain at a private address)
- **steps**: invoke `hermiq.webFetch` with that URL
- **expected result**: the call is rejected with a structured error; no outbound TCP connection is attempted (verified via network capture/mock)
- **test command**: `/test-security`

### TC-4: web.fetch rejects the cloud metadata address unconditionally
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-chosen-url-resolves-to-the-cloud-metadata-address`
- **type**: security
- **preconditions**: `fetchAllowlist` contains a hostname that has been made to resolve to `169.254.169.254` (test double)
- **steps**: invoke `hermiq.webFetch` with that URL
- **expected result**: the call is rejected even though the hostname is allowlisted
- **test command**: `/test-security`

### TC-5: web.fetch does not follow redirects
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-a-fetch-target-returns-a-redirect`
- **type**: security
- **preconditions**: a test endpoint that returns HTTP 302 to a second URL
- **steps**: invoke `hermiq.webFetch` against the redirecting endpoint
- **expected result**: the response is a structured `redirect_not_followed` error naming the target; the second URL is never requested
- **test command**: `/test-security`

### TC-6: web.fetch rejects non-text content types
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-fetches-a-non-text-resource`
- **type**: functional
- **preconditions**: a test endpoint serving `application/pdf`
- **steps**: invoke `hermiq.webFetch` against that endpoint
- **expected result**: a structured `unsupported_content_type` error; no extraction attempted
- **test command**: `/test-functional`

### TC-7: web.fetch truncates an oversized response
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-a-response-exceeds-the-configured-size-cap`
- **type**: functional
- **preconditions**: `maxResponseBytes` set low (e.g. 500) in test config; a test endpoint serving a larger HTML page
- **steps**: invoke `hermiq.webFetch` against that endpoint
- **expected result**: extracted text length is capped at the configured limit and `truncated: true` is set
- **test command**: `/test-functional`

### TC-8: Extracted content is delimited as untrusted
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-fetched-content-is-delimited-as-untrusted-before-reaching-the-llm`
- **type**: functional
- **preconditions**: a reachable, allowlisted test HTML page
- **steps**: invoke `hermiq.webFetch` and inspect the raw tool-result payload before it is sent to the LLM
- **expected result**: the extracted text is wrapped between the fixed begin/end untrusted-content markers, with the source URL present
- **test command**: `/test-functional`

### TC-9: A paid search API credential is never stored in app config
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-paid-search-api-credentials-come-from-the-credential-broker`
- **type**: security
- **preconditions**: admin configures `searchProvider=generic-json` with a broker `searchCredentialId`
- **steps**: invoke `hermiq.webSearch`; inspect `oc_appconfig` for the `hermiq.webResearch` key and inspect outbound request headers
- **expected result**: no raw API key appears in `oc_appconfig`; the outbound `Authorization`-equivalent header is injected by the broker, not present in Hermiq's own config or code
- **test command**: `/test-security`

### TC-10: The search endpoint is exempt from the private-address block
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-the-admin-configured-search-endpoint-is-exempt-from-the-private-address-block`
- **type**: functional
- **preconditions**: `searchEndpoint` set to an internal Docker-network address, `allowInsecureHttp=true`
- **steps**: invoke `hermiq.webSearch`
- **expected result**: the call is NOT rejected for targeting a private address; results are returned (or a provider-level error unrelated to address validation)
- **test command**: `/test-functional`

### TC-11: A web-research tool call is visible in the run trace with its target
- **spec_ref**: `openspec/changes/web-research-tool/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — needs to see exactly what an agent reached on the open web
- **preconditions**: a scheduled agent run that calls `hermiq.webFetch` with a URL containing a query string
- **steps**: run the schedule; open the run's trace as the owner
- **expected result**: the `tool` step for that call shows host+path only (no query string) alongside timing and outcome
- **test command**: `/test-functional`

### TC-12: Admin settings UI configures the backend and allow/deny lists
- **spec_ref**: `openspec/changes/web-research-tool/specs/web-research-tool/spec.md#non-functional-requirements`
- **type**: accessibility
- **persona**: Mark (MKB Software Vendor) — configures Hermiq for a client instance
- **preconditions**: logged in as an instance admin
- **steps**: open the web-research settings modal; configure a provider, endpoint, and an allowlist entry; save
- **expected result**: fields are keyboard-navigable with proper labels (NcSelect/NcInput conventions); save round-trips correctly via `GET`
- **test command**: `/test-accessibility`

## Coverage Summary

- Pluggable admin-configured search backend — covered (TC-1, TC-2, TC-10)
- web.fetch content-type gate + truncation — covered (TC-6, TC-7)
- Untrusted-content delimiter — covered (TC-8)
- Egress guard (SSRF/allowlist/denylist/redirects) — covered (TC-3, TC-4, TC-5)
- Search endpoint private-address exemption — covered (TC-10)
- Credential broker sourcing — covered (TC-9)
- Trace/audit target extension — covered (TC-11)
- Admin settings UI — covered (TC-12)
- `generic-json` field-mapping parsing — not separately listed as a TC; covered as part of TC-2's provider-parsing assertion at the unit-test level (Task 4 acceptance criteria in tasks.md), not restated here as a duplicate persona-level test case

## Out of Scope

- Load/performance testing of the search backend itself (an external system Hermiq does
  not operate) — out of scope per proposal.md.
- Prompt-injection scanning effectiveness (that is `agent-guardrails`' test surface, not
  this change's).
- Byte-accurate mid-stream download abort — deferred per proposal.md Out of Scope; not
  tested here since it is not implemented in v1.
