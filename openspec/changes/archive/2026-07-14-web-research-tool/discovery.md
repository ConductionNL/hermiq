# Discovery: web-research-tool

## Question

Hermiq's own `nc-native-tools` spec has a hard requirement: "The system MUST NOT
implement direct HTTP/API calls to third-party or remote systems inside Hermiq's tool
providers; such calls MUST route through OpenConnector's `CallService`." Does
OpenConnector's `CallService` actually fit `web.fetch`'s use case — fetching an
arbitrary, agent/LLM-chosen URL discovered at call time (e.g. a URL that appeared in a
`web.search` result the agent has never seen before) — or is it structurally built
around something else, forcing this change to carve an exception?

## Approach Taken

Read OpenConnector's `CallService` at HEAD (`apps-extra/openconnector/lib/Service/CallService.php`)
and its `Source` entity/schema, plus its existing SSRF-relevant guard code, plus how
Hermiq's own `SkillMarketplaceService` already uses `CallService` as a precedent for how
Hermiq apps are expected to consume it.

## Findings

- `CallService::call()` signature is `call(ObjectEntity $source, string $endpoint='',
  string $method='GET', array $config=[], ...): ObjectEntity` — it takes a **Source
  object**, not a URL. Internally the request URL is built as
  `$sourceData['location'] . $endpoint` — the base host comes exclusively from the
  Source's stored `location`; a caller only ever supplies a relative path appended to
  that fixed base. `guardCallPreconditions()` hard-fails when the Source has no
  `location` or is disabled. There is no code path for "no Source, just call this URL."
- `Source` (`docs/schema/Source.json`) is an **admin-registered** object: `location`
  (base URL), `auth`/`authenticationConfig` (apikey/jwt/oauth/etc.), `isEnabled`,
  rate-limit fields. Sources are created ahead of time via the admin UI or seed
  fragments (`lib/Settings/register.d/*.json`, e.g. `kvk-source.json`,
  `opencorporates-source.json`), each with a fixed, admin-owned `location`. Several of
  those seed comments state explicitly that the design's entire SSRF-safety argument
  rests on the base URL being admin-owned and fixed, not end-user/agent-supplied.
- No "raw/arbitrary URL" fetch primitive exists anywhere in OpenConnector.
  `SourcesController::test()` is the closest thing to a generic call, and it still takes
  a Source id and resolves through the same `location`-bound path — it is a "run this
  pre-registered source now" button, not a host-agnostic fetch.
- Hermiq's own `SkillMarketplaceService` (existing code) is the precedent for how Hermiq
  is expected to use `CallService`: it calls through a pre-configured "hub source" and
  explicitly returns an error when no hub source is configured, rather than falling back
  to any raw-URL path. This is further evidence that the intended Hermiq/OpenConnector
  contract is "pre-registered destination," not "whatever URL a tool call names."
- A reusable SSRF building block does exist —
  `OpenConnector\Service\AuthenticationService::assertSafeTokenUrl()` blocks
  loopback/link-local/RFC-1918/cloud-metadata hosts — but it validates an **admin-entered**
  OAuth `tokenUrl` on a Source at configuration time, not a runtime, agent-supplied
  destination, and `CallService::call()` does not invoke it (or any equivalent) against
  its own request target at all.

## Recommendation

Do not route `web.fetch`/`web.search` through `CallService`. The two are structurally
incompatible: `CallService` guarantees safety by requiring the destination host to be
fixed and admin-owned ahead of time; `web.fetch`'s entire value is fetching a host the
agent only learns of at call time (typically from a `web.search` result). Forcing this
through `CallService` would mean either (a) requiring an admin to pre-register every
possible URL an agent might ever want to read — defeating the feature — or (b) adding a
"create-a-Source-on-the-fly" escape hatch to `CallService` that would then need its own
SSRF guard anyway, duplicating the work in a different app's codebase instead of Hermiq's.

Instead: carve a narrow, explicit, named exception into the `nc-native-tools` requirement
for exactly these two tool ids, and build a dedicated SSRF/allowlist guard inside Hermiq
that reuses `assertSafeTokenUrl()`'s block-list categories (loopback/link-local/RFC-1918/
metadata, HTTPS-only) as a **citable precedent for what to block**, not as a directly
reusable dependency (it is private to OpenConnector's `AuthenticationService` and shaped
for config-time validation, not per-call runtime validation). See the `nc-native-tools`
MODIFIED requirement in `specs/nc-native-tools/spec.md` and the SSRF algorithm in
design.md.

## Risks Uncovered

- The residual DNS-rebinding TOCTOU window noted in proposal.md Risk 1 exists precisely
  because Hermiq must now own this guard itself rather than inheriting a hardened,
  shared implementation from OpenConnector. Tracked as an open question, not a blocker.

## Next Steps

Proceed to design.md and the `nc-native-tools`/`run-audit-log` MODIFIED requirements with
this exception explicitly justified and scoped to `hermiq.webSearch`/`hermiq.webFetch`
only — no other Hermiq tool gains a direct-HTTP exception by implication.
