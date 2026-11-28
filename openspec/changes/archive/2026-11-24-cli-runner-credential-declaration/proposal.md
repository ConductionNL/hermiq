---
kind: config
depends_on: []
chain:
  - cli-runner-credential-declaration   # this change
  - cli-runner-text-turn-dispatch       # next in chain
  - cli-runner-governed-mcp-and-egress  # last in chain
---

# Proposal: cli-runner-credential-declaration

## Summary

Hermiq's forthcoming `executionMode: cli` turn runs the official `claude` CLI inside the
`hermiq-llm-runner` ExApp, and a CLI needs its credential in its **environment** — there is no proxy
seam to interpose. OpenRegister's credential broker cannot express that today: every Anthropic entry in
`lib/Settings/credential-providers.json` is a **host-locked proxy** provider (`anthropic:166-179`,
`anthropic-oauth:180-193`), and a proxy credential's secret is deliberately unreachable app-side —
`CredentialBrokerService::resolveInjectable()` returns `null` for it
(`lib/Service/Credential/CredentialBrokerService.php:266-268`). So a `cli` turn has **no** compliant way
to obtain a token: the only alternative is the app keeping custody of the secret itself, which is exactly
what the broker exists to prevent. This change adds an `anthropic-cli` **inject-only** provider to the
catalogue and declares it in Hermiq's `src/manifest.json` so the Credentials tab offers it. Two purely
declarative JSON edits, no code.

## Motivation

1. **Without this entry, the `cli` chain cannot obtain a token at all.** `CredentialController::create()`
   rejects an unknown provider (recorded in the catalogue's own `$fleetComment`, line 3), so a user could
   not even *create* an `anthropic-cli` credential. The existing `anthropic-oauth` entry cannot be reused:
   it is a proxy provider, so `resolveInjectable()` returns `null` for it by design. The runner would be
   left holding a raw token — the precise compliance failure the `$injectOnlyComment` (line 4) describes
   as the gap the `generic-*` providers were introduced to close.
2. **The Credentials tab now filters to app-declared providers.** A catalogue entry alone is invisible;
   `src/manifest.json` must declare it, exactly as the `agent-credentials` capability already requires
   for `openai`, `fireworks`, `github`, `anthropic` and `anthropic-oauth`
   (`openspec/specs/agent-credentials/spec.md`, "Manifest-declared credential requirements").
3. **`claude -p` is the ToS-compliant path for a Claude Max/Pro subscription.** Anthropic hard-refuses a
   subscription OAuth token on the raw Messages API (HTTP 429 `rate_limit_error`,
   `anthropic-organization-id` present so it *authenticates*, but **no** `retry-after` and **no**
   `anthropic-ratelimit-*` counters, identical after 14h of zero usage — a categorical refusal, not a
   quota). Spoofing client identity is not acceptable. Running the official CLI is.
4. **ADR-032 forces this to be its own link.** The `cli` work as a whole is declarative JSON *plus*
   substantial PHP and Node — a `mixed` envelope, which ADR-032 rejects outright. The config surface is
   carved out here so links 2 and 3 are pure `kind: code`.

## Affected Projects

- [x] Project: `openregister` — `lib/Settings/credential-providers.json` gains an `anthropic-cli`
      inject-only provider entry (`inject_only: true`, no `baseUrl`, no `allowRules`) and a `version` bump.
- [x] Project: `hermiq` — `src/manifest.json` `credentials[]` gains an `anthropic-cli` entry with a
      `reason`, so the Credentials tab offers it.

## Scope

### In Scope

- **The `anthropic-cli` catalogue entry** in OpenRegister: `inject_only: true`, **no `baseUrl`**, **no
  `allowRules`**, and a `$comment` recording the trade-off in the house style of the existing `generic-*`
  entries (`credential-providers.json:255-304`).
- **The catalogue `version` bump** (`1.4.0` → `1.5.0`), matching the precedent the `$injectOnlyComment`
  set when `1.4.0` introduced the `generic-*` family.
- **The Hermiq manifest declaration** — one `{provider, reason, scopes}` entry matching the shape already
  in `src/manifest.json:7-35`.
- **The spec delta** on Hermiq's existing `agent-credentials` capability, extending its
  "Manifest-declared credential requirements" requirement to cover `anthropic-cli` and recording the
  inject-only trade-off as a normative requirement rather than a comment.

### Out of Scope

- **The `cli` dispatch itself** — deferred to `cli-runner-text-turn-dispatch` (link 2). Nothing in this
  change reads the credential; it only makes one creatable.
- **The governed MCP endpoint and governed egress proxy** — deferred to `cli-runner-governed-mcp-and-egress`
  (link 3).
- **Run-time credential resolution precedence for `anthropic-cli`.** The `agent-credentials` capability's
  precedence rule (personal → organisation → instance) names the `openai`/`fireworks` drivers; wiring
  `anthropic-cli` into a resolver is code and belongs to link 2.
- **Any broker capability change.** `resolveInjectable()` already exists and already does exactly what is
  needed (`CredentialBrokerService.php:250-276`). This change adds a catalogue row, not a code path.
- **`openai`/`grok` CLI providers.** No verified official CLI. Anthropic only.
- **Making `anthropic-oauth` inject-only.** It stays a host-locked proxy provider, unchanged — the `http`
  path must keep its zero-knowledge property. The two entries coexist deliberately.

## Approach

Two JSON edits, one per repo, landing as **two PRs** with a strict order (see Cross-Project
Dependencies). The `inject_only: true` / no-`baseUrl` / no-`allowRules` shape is **forced, not chosen**:

- `CredentialBrokerService::request()` **denies** an inject-only provider outright
  (`CredentialBrokerService.php:190-192` — "an unbounded host is exactly what must never be proxied"), so
  the entry can never become an open proxy.
- `resolveInjectable()` returns `null` unless the provider `isInjectOnly()` (`:266-268`), so app-side
  resolution is available **only** to an inject-only entry.

A CLI needs the token in `ANTHROPIC_*` env. There is no host to lock and no request to constrain, so the
proxy shape is inexpressible and the inject-only shape is the only one the broker offers. The `authScheme`
on an inject-only entry is **descriptive only** (per the `$injectOnlyComment`) — the app decides how to
inject.

## New Dependencies

None. No new packages, libraries, or external services. `resolveInjectable()`, the catalogue loader, the
manifest `credentials[]` contract and the Credentials tab all exist and are unchanged.

## Impact

- **`openregister/lib/Settings/credential-providers.json`** — one new provider object + a `version` bump.
  The file is **runtime-immutable and read-only** by design (line 2): new providers ship in a reviewed
  release only, which is why this is a code-review-gated JSON edit rather than an admin setting.
- **`hermiq/src/manifest.json`** — one new `credentials[]` entry.
- **`hermiq/openspec/specs/agent-credentials/spec.md`** — extended via this change's delta.
- **Unchanged**: `CredentialBrokerService` (no code change — the guards and the inject-only branch already
  exist), the `anthropic` / `anthropic-oauth` proxy entries, every other provider, and every existing
  `http`-mode Hermiq path. Purely additive: no existing credential changes behaviour.
- **Nothing consumes the new provider until link 2 lands.** A user can create the credential; no code
  reads it yet. That is the expand-then-contract staging ADR-032 prescribes, and it is why this link is
  safe to merge alone.

## Cross-Project Dependencies

This change spans **two repositories**, and the dependency is strictly ordered:

| # | Repo | Edit | Must land |
|---|---|---|---|
| 1 | `openregister` | `lib/Settings/credential-providers.json` — the `anthropic-cli` entry | **FIRST** |
| 2 | `hermiq` | `src/manifest.json` — the `credentials[]` declaration | after #1 |

**Two PRs, in that order.** Hermiq's manifest declares a provider *identifier* that must already exist in
OpenRegister's catalogue: `CredentialController::create()` rejects an unknown provider, so a manifest
entry landing first would offer the user a Credentials-tab row that fails on save. The reverse order is
inert — an undeclared catalogue entry is simply unoffered.

**Where each spec lives.** Hermiq's `openspec/` **cannot** hold OpenRegister's canonical spec:
the credential-broker capability's canonical home is the `openregister` repo (its requirements are cited
throughout `CredentialBrokerService.php`, e.g. `@spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file`
at `:160`). This change's delta therefore covers **only** Hermiq's `agent-credentials` capability. The
OpenRegister-side catalogue requirement needs a mirrored delta in the `openregister` repo — flagged in
DEFERRED_QUESTIONS rather than silently decided here.

**AppAPI 34.0.0** — installed on the dev instance; relevant only from link 2 onward.

## Risks

### Risk 1: The Claude Max token leaves the vault into Hermiq's process and then into the ExApp environment

**Severity:** High — **Mitigation:** this is the whole point of the change and it is **not softened**: an
inject-only provider **consciously weakens the broker's central "the app never sees the secret" property**,
which the host-locked proxy providers (`anthropic`, `anthropic-oauth`) do preserve. It is unavoidable — a
CLI needs the token in its **environment**, and there is no proxy seam to interpose. It is accepted because
the alternative is worse (the app keeping custody of the secret outright), and it is **bounded**:
`resolveInjectable()` still enforces **Guard 1** (owner / organisation-membership — the IDOR guard,
`CredentialBrokerService.php:255-258`) and **Guard 2** (`allowedApps`, `:260-261`); the secret still lives
in **Doriath** and the app's config holds only a `credentialRef` — never store a secret in a schema, which
is the entire point of the broker; and the blast radius is **one user's personal subscription token, not an
organisation key**. This is the same trade-off the catalogue's `$injectOnlyComment` (line 4) already records
for the `generic-*` family, applied to a provider whose blast radius is strictly smaller.

### Risk 2: An inject-only entry could be mistaken for a proxy entry and given a baseUrl

**Severity:** Medium — **Mitigation:** adding a `baseUrl` or `allowRules` would silently re-route the
provider onto `request()`'s proxy path and give the CLI path a credential it cannot use
(`resolveInjectable()` would return `null` at `:266-268` and the turn would fail). The entry's `$comment`
states the constraint inline, the catalogue is code-review-gated by construction (line 2: not widenable at
runtime, no create/update/delete endpoint), and the spec delta makes the absence of both fields a normative
requirement with its own scenario rather than a convention.

### Risk 3: Claude Max/Pro OAuth is personal-scope only per the Anthropic Terms of Service

**Severity:** Low — **Mitigation:** reject at organisation scope; the token serves **only its owner**. This
is already the standing rule from `anthropic-agent-provider` and is carried forward unchanged. The
`$comment` on the existing `anthropic-oauth` entry (`credential-providers.json:183`) states it, and the new
entry states it too. Enforcement is the broker's existing scope-dispatched Guard 1; this change adds no new
enforcement seam, so the requirement is recorded declaratively and enforced by link 2's resolver.

### Risk 4: Landing the manifest declaration before the catalogue entry

**Severity:** Low — **Mitigation:** the Credentials tab would offer a provider whose save fails. Bounded by
the PR ordering above and by the failure being immediate, loud and reversible (revert one JSON entry).

## Rollback Strategy

Revert in reverse order: drop the `hermiq` manifest entry first (the Credentials tab stops offering
`anthropic-cli`), then the `openregister` catalogue entry and its `version` bump. Both are additive
single-object JSON edits with no consumers until link 2 lands, so a revert cannot break an existing path.
Any `anthropic-cli` credential a user already created would become unresolvable — it would fail closed at
`resolveProvider()`, never fail open — and can be deleted through the existing Credentials tab.

## Open Questions

1. **Does the OpenRegister-side catalogue requirement get its own `openspec` change in the `openregister`
   repo?** This change's delta can only cover Hermiq's `agent-credentials` capability; the credential-broker
   capability's canonical home is the `openregister` repo. See DEFERRED_QUESTIONS.
2. **Which env var name does the runner inject the secret under?** `ANTHROPIC_API_KEY` versus the
   subscription-OAuth variable the CLI expects. This is **link 2's** decision (the runner's
   `selectCredentialEnv()` allowlist), not this change's — the catalogue's `authScheme` is descriptive only
   for an inject-only provider, so nothing here depends on the answer.
