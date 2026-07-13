# Proposal: web-research-tool

## Summary

Adds two MCP tools to Hermiq's agent tool surface — `web.search` (query → ranked
title/url/snippet results) and `web.fetch` (URL → extracted readable text, size-capped) —
exposed through the existing `HermiqToolProvider`. The search backend is a pluggable,
admin-configured endpoint (a self-hosted SearXNG instance is the sovereign default; any
JSON search API can be wired via a field mapping) — never a hardcoded call to a US search
giant. Every outbound call is governed: an SSRF guard blocks private/loopback/link-local/
metadata addresses (checked against the *resolved* IP, not the hostname string, to defeat
DNS rebinding), an admin-configured host allowlist/denylist scopes what `web.fetch` may
reach, responses are size- and time-capped, and every call is recorded as a trace step
(with its target) folded into the run's existing `AuditTrail` entry. Fetched content is
treated as untrusted input and clearly delimited as external/untrusted in the tool result
handed back to the LLM.

## Motivation

Hermiq agents can act on Nextcloud data (`nc-native-tools`: Files, Contacts, Calendar,
Deck, email) and on OpenRegister objects, but cannot look anything up on the open web.
Two of the most-requested agent journeys from the competitive research — "monitor this
page and alert me when it changes" and "write me a weekly market briefing" — are
impossible today for want of exactly this capability. The evidence cluster (Spectr DB,
`competitor_features` WHERE `app_slug='hermiq'` AND `resolved_by LIKE '%web-research-tool%'`)
names Open WebUI's pluggable web-search integration and Khoj's research automation over
the web as the two directly-resolved rival features; OpenHands' browser tool, Manus'
real-time browsing, and Gemini's "Deep Research" agents are adjacent motivators but are
NOT this change's scope (see Out of Scope).

Without this, Hermiq's otherwise-complete tool surface (nc-native-tools + OpenRegister's
MCP registry + OpenConnector's connector catalogue) has exactly one hole: nothing reaches
the open internet. That hole is also the single riskiest thing to fill carelessly — an
outbound HTTP tool driven by an LLM's own choice of URL is a textbook SSRF and
exfiltration vector — which is why this proposal treats the egress-governance design as
load-bearing, not an afterthought.

## Affected Projects

- [ ] Project: `hermiq` — adds `web.search`/`web.fetch` tools to `HermiqToolProvider`, a
      new egress-governance layer, a pluggable search-backend client, a readable-text
      extractor, an admin settings surface (`hermiq.webResearch` config), and extends the
      existing run-trace/audit step shape to carry a redacted target for these two tools.

No other `apps-extra` project changes. OpenConnector, OpenRegister, and nc-vue are
referenced (credential broker, `IMcpToolProvider` registration, admin settings pattern)
but none of their code changes.

## Scope

### In Scope

- Two new tool descriptors on `HermiqToolProvider` (`hermiq.webSearch`, `hermiq.webFetch`),
  following the provider's existing no-throw / structured-error-envelope contract.
- An admin-configured, pluggable search backend: a base endpoint URL, a provider "shape"
  (`searxng` native JSON, or `generic-json` with an admin-supplied field mapping so any
  JSON search API can be wired without new code), and an optional broker credential for a
  paid API. No hardcoded default backend, no hardcoded vendor call. When unconfigured,
  `web.search` reports itself unavailable (never a silent no-op or a fabricated result).
- `web.fetch`: GET a URL, gate on content-type (text/html, text/plain only), extract
  readable text from HTML via PHP's native `DOMDocument`/`DOMXPath` (no new third-party
  readability library), truncate to a configured byte cap (mirrors the existing
  `HermiqToolProvider::readFile()` truncation pattern), and never follow redirects
  automatically (a 3xx is reported, not silently chased — see design.md).
- Egress governance for both tools: an SSRF guard (block private/loopback/link-local/
  cloud-metadata addresses, checked against the DNS-*resolved* address); an admin-managed
  host allowlist/denylist for `web.fetch`'s agent-chosen targets; a response size cap; a
  request timeout; HTTPS-only by default.
- A narrow, explicit exception to `nc-native-tools`' "remote calls route through
  OpenConnector's `CallService`" rule for exactly these two tools (see design.md
  Decisions and the `nc-native-tools` MODIFIED requirement) — `CallService` is built
  around admin-pre-registered `Source` entities with a fixed `location`, and is
  structurally incapable of fetching a URL an LLM only learns of at call time.
- Recording each `web.search`/`web.fetch` invocation as a `tool` step in the run's
  existing trace/`AuditTrail` timeline, carrying the redacted target (host+path, with any
  known-sensitive query parameter masked) alongside the existing name/timing/outcome.
- Wrapping fetched content with an explicit "external, untrusted content" delimiter in the
  tool result returned to the LLM — a cheap, immediate mitigation while the dedicated
  prompt-injection filtering work lands separately.
- Credentials for a paid search API sourced from OpenRegister's credential broker
  (`BrokerHttpClient`, already shipped for LLM providers), never app config.
- An admin settings surface (backend `IAppConfig` blob `hermiq.webResearch` + a small
  settings controller, mirroring the existing `hermiq.llm`/`LlmSettingsController`
  pattern) and its Vue admin UI.

### Out of Scope

- A full headless-browser / computer-use tool (JS-rendered pages, clicking, form-filling).
  That is OpenHands/Manus territory — a heavy sandboxing concern that belongs to the
  blocked `hermiq-exec` ExApp, not a lightweight MCP tool.
- Crawling or indexing the web into a knowledge base. That is OpenRegister's RAG/vector
  surface, not Hermiq's.
- Full prompt-injection scanning of fetched content. This change applies a plain textual
  "untrusted content" delimiter as a stopgap; the actual filtering pass is the natural
  companion `agent-guardrails` change (input guardrails) — this change does not
  hard-depend on it and does not implement it.
- Byte-accurate mid-stream download abort (a true streaming cap that stops the TCP
  transfer the instant the cap is hit). V1 downloads the full response then truncates,
  bounded by the request timeout as the practical backstop — see Risks.
- A general "raw HTTP" tool for arbitrary methods/bodies. `web.fetch` is GET-only,
  read-only, text-only.

## Approach

Add `web.search`/`web.fetch` as two more entries in `HermiqToolProvider::TOOL_DESCRIPTORS`
and `invokeTool()`'s switch, exactly like the existing five NC-native tools. Behind them,
three small collaborators: a `WebResearchSettingsHandler` (reads/writes the
`hermiq.webResearch` `IAppConfig` blob, mirroring `LlmSettingsHandler`), a guard service
that performs the SSRF/allowlist/denylist check against a resolved IP before any request
is made, and two thin clients (`WebSearchClient`, `WebFetchService`) that call out via
`OCP\Http\Client\IClientService` — never raw Guzzle, matching what `SetupController`
already does elsewhere in this app. The guard runs identically whether the destination is
the admin-configured search endpoint or an agent-chosen fetch URL, except that the
private/loopback range block is intentionally NOT applied to the admin-configured search
endpoint (an admin may legitimately run SearXNG on an internal Docker/LAN address); the
allowlist/denylist and cloud-metadata block still apply to it. Design details, the SSRF
algorithm, the trace/audit wiring, and the field-mapping shape for `generic-json` search
providers are in design.md.

## New Dependencies

None. `OCP\Http\Client\IClientService` (NC core, already used by `SetupController` in
this app) covers outbound HTTP; `DOMDocument`/`DOMXPath` (PHP core `ext-dom`, already a
PHP requirement) covers HTML text extraction; `BrokerHttpClient` (already shipped,
`llm-keys-via-broker`) covers the optional paid-search-API credential path.

## Impact

- `lib/Mcp/HermiqToolProvider.php` — two new tool descriptors + dispatch cases.
- New: `lib/Service/WebResearch/` — settings handler, SSRF guard, search client, fetch
  service, readable-text extractor.
- New: `lib/Controller/Settings/WebResearchSettingsController.php` +
  `appinfo/routes.php` entries (`GET`/`PATCH /api/settings/web-research`).
- `lib/Service/Engine/RunTraceCollector.php` / `FacadeToolInvoker.php` — an optional,
  additive metadata field on a `tool` step (only these two tool ids populate it); no
  change to the existing name/timing/outcome shape other tools rely on.
- New Vue admin settings surface + `src/api/webResearch.js`.
- `l10n/en.json` / `l10n/nl.json` — new admin-facing strings.
- No `hermiq_register.json` schema change and no `appinfo/info.xml` version bump: the new
  config is an `IAppConfig` JSON blob (`hermiq.webResearch`), not an OpenRegister schema —
  the same shape `hermiq.llm` already uses, which needed neither.

## Cross-Project Dependencies

- OpenRegister's credential broker (`CredentialBrokerService` via `BrokerHttpClient`) —
  reused as-is, no changes requested.
- OpenRegister's `IMcpToolProvider`/`McpToolsService` — the two new tools register through
  the existing alias; no changes requested.
- `nc-native-tools` (Hermiq's own spec) — one MODIFIED requirement carving the narrow
  exception described above.
- `run-audit-log` (Hermiq's own spec) — one MODIFIED requirement extending the tool-step
  shape with an optional redacted target.
- `agent-guardrails` (proposed, not yet built) — named in prose as the natural companion
  for full prompt-injection filtering of fetched content. Not a hard dependency: this
  change ships its own minimal "untrusted content" delimiter regardless of whether/when
  `agent-guardrails` lands.

## Risks

### Risk 1: SSRF via DNS rebinding between validation and connection
**Severity:** High — **Mitigation:** the guard resolves the hostname and validates the
*resolved* address (not the string), immediately before the request; automatic redirect
-following is disabled entirely for `web.fetch` (a 3xx response is surfaced, not chased),
which removes the most practical rebinding vector (a page 302-ing to an internal address
after the initial check passed). A residual, narrow TOCTOU window between the guard's
resolution and the underlying HTTP client's own connection-time resolution is not fully
closed in v1 (NC's `IClientService` does not expose IP-pinning per request) — documented
as an accepted residual risk pending investigation of pinning options, tracked as an open
question below rather than blocking this change (the same risk already exists, unaddressed,
in any Nextcloud app calling an admin-configured URL).

### Risk 2: A malicious or compromised search result leads the agent to exfiltrate data
**Severity:** Medium — **Mitigation:** `web.fetch` is read-only (GET, no request body);
`web.search`/`web.fetch` cannot themselves send data anywhere — a follow-on action (e.g.
`hermiq.sendMail`) would be a separate, separately-audited tool call. Fetched content is
delimited as untrusted before reaching the LLM. Full prompt-injection scanning is
`agent-guardrails`' job, not this change's, but is called out explicitly in Notes.

### Risk 3: Large or slow responses degrade run performance
**Severity:** Low — **Mitigation:** a configurable timeout (default ~10s) and a
configurable byte cap with truncation (mirroring the existing `readFile` truncation
pattern) bound the worst case; true mid-stream abort is deferred (see Out of Scope).

## Rollback Strategy

Both tools are pure additions behind an admin-configured, off-by-default backend
(`web.search` self-reports unavailable with no backend configured; `web.fetch` can be
disabled by leaving the host allowlist empty in a "deny-all-unless-listed" admin mode —
see design.md). Reverting is a plain revert of the PR(s): remove the two tool descriptors
and dispatch cases from `HermiqToolProvider`, drop the new service classes and controller,
and remove the `hermiq.webResearch` `IAppConfig` key (orphaned config, no migration to
reverse). No data migration, no schema to roll back.

## Open Questions

- Does `OCP\Http\Client\IClientService`'s underlying Guzzle configuration expose a
  per-request IP-pinning option (e.g. a curl `resolve` equivalent) that would close the
  residual DNS-rebinding TOCTOU window in Risk 1? Needs a build-time spike against the
  installed Guzzle/curl handler version; if unavailable, the redirect-disabled mitigation
  is the accepted v1 posture.
- Should the host allowlist support wildcard/subdomain patterns (e.g. `*.wikipedia.org`)
  or exact hostnames only for v1? Leaning exact-hostname-only for a simpler, more
  auditable first cut; deferred to design.md for the builder to confirm.
