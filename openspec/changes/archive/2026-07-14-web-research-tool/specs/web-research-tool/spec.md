# Web Research Tool Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes:**
- `openspec/changes/web-research-tool/` — this change (kind: code)

## Purpose

Gives Hermiq agents the one capability the rest of the tool surface (nc-native-tools,
OpenRegister's MCP registry, OpenConnector's connector catalogue) cannot provide: looking
something up on the open web. Exposes `web.search` (query → ranked results) and
`web.fetch` (URL → extracted readable text) through `HermiqToolProvider`, backed by a
pluggable, sovereignty-respecting search endpoint an admin configures — never a hardcoded
call to a specific search vendor — and governed by an egress guard that treats every
outbound call as a potential SSRF/exfiltration vector by default.

## ADDED Requirements

### Requirement: Pluggable, admin-configured search backend
The system MUST let an admin configure a web-search backend (a base endpoint URL and a
provider shape: native SearXNG JSON, or a generic JSON API with an admin-supplied field
mapping) and MUST NOT contain any hardcoded call to a specific commercial search
provider. When no search backend is configured, `hermiq.webSearch` MUST report itself
unavailable via a structured error rather than failing silently or returning a fabricated
result.

#### Scenario: An admin configures a self-hosted SearXNG instance
- GIVEN an admin sets `searchProvider=searxng` and a `searchEndpoint` pointing at a
  self-hosted SearXNG instance
- WHEN an agent invokes `hermiq.webSearch` with a query
- THEN the system MUST call the configured endpoint and return ranked
  `{title, url, snippet}` results parsed from SearXNG's native JSON response shape

#### Scenario: No search backend is configured
- GIVEN no `searchProvider` has been configured (the default state)
- WHEN an agent invokes `hermiq.webSearch`
- THEN the system MUST return a structured error indicating the tool is unavailable
- AND the system MUST NOT attempt any outbound HTTP call
- AND the system MUST NOT fabricate or return placeholder search results

#### Scenario: An admin wires a non-SearXNG JSON search API
- GIVEN an admin sets `searchProvider=generic-json`, a `searchEndpoint`, and a
  `searchFieldMapping` describing where results/title/url/snippet live in that API's
  response shape
- WHEN an agent invokes `hermiq.webSearch`
- THEN the system MUST parse the response using the configured field mapping
- AND no new code deployment MUST be required to support that API

### Requirement: web.fetch extracts readable text with a content-type gate
The system MUST expose a `hermiq.webFetch` tool that retrieves a URL via HTTP GET,
accepts only `text/html`, `text/plain`, or `text/markdown` response content types,
extracts readable text from HTML (stripping script/style/navigation markup), and
truncates the result to a configured byte cap with a `truncated` flag — mirroring the
existing `hermiq.readFile` truncation convention.

#### Scenario: An agent fetches an HTML page
- GIVEN a URL that passes the egress guard (see below) and returns `text/html`
- WHEN an agent invokes `hermiq.webFetch` with that URL
- THEN the system MUST return the extracted readable text, the source URL, and a
  `truncated` boolean
- AND script/style/navigation markup MUST NOT appear in the extracted text

#### Scenario: An agent fetches a non-text resource
- GIVEN a URL that returns a `Content-Type` other than `text/html`, `text/plain`, or
  `text/markdown` (e.g. `application/pdf`, `image/png`)
- WHEN an agent invokes `hermiq.webFetch` with that URL
- THEN the system MUST return a structured error identifying the unsupported content type
- AND the system MUST NOT attempt to extract or return any binary content

#### Scenario: A response exceeds the configured size cap
- GIVEN a fetched response body larger than the configured `maxResponseBytes`
- WHEN `hermiq.webFetch` processes the response
- THEN the system MUST truncate the extracted text to the configured cap
- AND the result MUST set `truncated: true`

### Requirement: Fetched content is delimited as untrusted before reaching the LLM
Every successful `hermiq.webFetch` result MUST wrap the extracted text with an explicit,
fixed textual marker identifying it as untrusted external content, distinct from the
agent's own instructions or prior conversation.

#### Scenario: An agent's tool-call result is passed back into the conversation
- GIVEN a successful `hermiq.webFetch` call
- WHEN the result is returned to the LLM as a tool-result message
- THEN the extracted text MUST be wrapped between fixed begin/end markers identifying it
  as untrusted external content
- AND the source URL MUST be included alongside the extracted text

### Requirement: Egress guard blocks SSRF-shaped destinations for web.fetch
The system MUST validate every `hermiq.webFetch` target against an SSRF guard before
issuing any request: the guard MUST resolve the hostname and reject the request if any
resolved address is loopback, link-local, RFC 1918 private range, IPv6 unique-local, or
the cloud metadata address — checked against the resolved address, not the hostname
string, so a rebind after an earlier check cannot bypass it. The guard MUST also enforce
an admin-configured host allowlist/denylist and MUST reject non-HTTPS URLs unless the
admin has explicitly enabled an insecure-HTTP opt-in.

#### Scenario: An agent-chosen URL resolves to a private address
- GIVEN a `hermiq.webFetch` call with a URL whose hostname resolves to an RFC 1918
  private address (e.g. `10.0.0.5`)
- WHEN the egress guard validates the target
- THEN the system MUST reject the call with a structured error
- AND the system MUST NOT issue any outbound request to that address

#### Scenario: An agent-chosen URL resolves to the cloud metadata address
- GIVEN a `hermiq.webFetch` call with a URL whose hostname resolves to `169.254.169.254`
- WHEN the egress guard validates the target
- THEN the system MUST reject the call unconditionally, regardless of any allowlist entry

#### Scenario: A denylisted host is requested
- GIVEN an admin has added a hostname to `fetchDenylist`
- WHEN `hermiq.webFetch` is called with a URL on that host
- THEN the system MUST reject the call before any DNS resolution or request is made

#### Scenario: An allowlist is configured and the target is not on it
- GIVEN an admin has configured a non-empty `fetchAllowlist`
- WHEN `hermiq.webFetch` is called with a URL whose host is not in that allowlist
- THEN the system MUST reject the call, even if the host would otherwise pass the SSRF
  address checks

#### Scenario: A fetch target returns a redirect
- GIVEN a `hermiq.webFetch` target that passes the guard but responds with an HTTP 3xx
- WHEN the system processes the response
- THEN the system MUST NOT automatically follow the redirect
- AND the system MUST return a structured error naming the redirect target, so the caller
  can choose to retry with that URL explicitly (subject to the same guard)

### Requirement: The admin-configured search endpoint is exempt from the private-address block
The system MUST NOT apply the loopback/link-local/RFC-1918 private-address block to the
admin-configured `searchEndpoint` itself (an admin may legitimately run a self-hosted
search backend on an internal address), while still enforcing the cloud-metadata block,
HTTPS-or-explicit-opt-in, response size cap, and timeout on that endpoint.

#### Scenario: A self-hosted SearXNG instance is on an internal Docker network address
- GIVEN an admin configures `searchEndpoint=http://searxng:8080` (an internal hostname)
  with `allowInsecureHttp=true`
- WHEN an agent invokes `hermiq.webSearch`
- THEN the system MUST NOT reject the call for targeting a private/internal address
- AND the system MUST still enforce the configured timeout and response size cap

### Requirement: Paid search API credentials come from the credential broker
When a search backend requires authentication, the system MUST source the credential
from OpenRegister's credential broker (via a broker-mediated HTTP client) and MUST NOT
store or accept the raw API key in Hermiq's own app configuration.

#### Scenario: An admin configures a paid search API with a broker credential
- GIVEN an admin selects a broker credential id for `searchCredentialId`
- WHEN `hermiq.webSearch` calls that provider
- THEN the system MUST route the call through the credential broker, which injects the
  secret server-side
- AND Hermiq's own configuration MUST NOT contain the raw API key at any point

### Requirement: Every web-research tool call is traced with its target
Every `hermiq.webSearch`/`hermiq.webFetch` invocation MUST be recorded as a step in the
run's trace/audit timeline, carrying the target (search query or fetch URL, reduced to
host+path with no query string) in addition to the existing name/timing/outcome fields
every tool step already carries.

#### Scenario: A run that calls web.fetch is auditable
- GIVEN a scheduled agent run that calls `hermiq.webFetch` once
- WHEN the run completes and its trace is retrieved by the owner
- THEN the trace's `tool` step for that call MUST show the fetched host+path alongside
  its timing and outcome

## Non-Functional Requirements

- **Performance:** a single `hermiq.webFetch`/`hermiq.webSearch` call MUST complete or
  time out within the admin-configured `timeoutSeconds` (default ~10s).
- **Accessibility:** the admin settings UI for configuring the search backend and
  allowlist/denylist MUST follow the same NcSelect/NcInput accessibility conventions as
  the existing LLM provider settings modal.
- **Internationalization:** Dutch and English MUST be supported for every new
  admin-facing string (ADR-005).

## Acceptance Criteria

- [ ] `hermiq.webSearch` and `hermiq.webFetch` are registered on `HermiqToolProvider` and
      appear in OR's tool registry.
- [ ] `hermiq.webSearch` reports unavailable (structured error, no fabricated result)
      when no search backend is configured.
- [ ] `hermiq.webFetch` rejects a URL resolving to a loopback/link-local/RFC-1918/
      metadata address, checked against the resolved IP.
- [ ] `hermiq.webFetch` never follows a redirect automatically.
- [ ] `hermiq.webFetch` rejects non-text content types before extraction.
- [ ] Extracted content is delimited as untrusted before being handed to the LLM.
- [ ] A paid search API credential is sourced from the broker, never app config.
- [ ] Every call is visible in the run trace with its target and in the run's
      `AuditTrail` entry.

## Notes

- `agent-guardrails` (proposed, not yet built) is the natural companion for real
  prompt-injection scanning of fetched content; this change's "untrusted content"
  delimiter is a stopgap, not a replacement.
- The `nc-native-tools` and `run-audit-log` specs carry the MODIFIED requirements this
  change needs from those capabilities (the CallService exception and the trace-target
  extension respectively) — see their delta specs in this change.
- Related: ADR-001 (Option C+, tool/governance delegation to OR), `discovery.md` (why
  `CallService` does not fit), `llm-keys-via-broker` (the `BrokerHttpClient` reused here).
