# Design: cli-runner-credential-declaration

## Architecture Overview

OpenRegister's credential broker offers exactly two shapes, and the catalogue keeps them apart:

```
   ┌──────────────────────── OpenRegister ─────────────────────────┐
   │  credential-providers.json  (runtime-immutable, reviewed)     │
   │                                                               │
   │  A. HOST-LOCKED PROXY          B. INJECT-ONLY                 │
   │     baseUrl + allowRules          inject_only: true           │
   │     e.g. anthropic,               NO baseUrl, NO allowRules   │
   │          anthropic-oauth          e.g. generic-*, anthropic-cli│
   │          │                                  │                 │
   │          ▼                                  ▼                 │
   │  request()                          resolveInjectable()       │
   │   Guard 1 owner/IDOR                 Guard 1 owner/IDOR       │
   │   Guard 2 allowedApps                Guard 2 allowedApps      │
   │   Guard 3 allow-rules                (3+4 N/A — no host)      │
   │   Guard 4 host-lock                         │                 │
   │          │                                  ▼                 │
   │   injectAuth() ─► calls out          returns RAW SECRET       │
   │   secret NEVER leaves OR             to the in-process app    │
   │                                             │                 │
   │  request() DENIES inject_only ──────────────┘  (:190-192)     │
   │  resolveInjectable() returns null unless inject_only (:266)   │
   └───────────────────────────────────────────────────────────────┘
                                                  │
                                                  ▼
                          Hermiq (PHP) ──► ExApp env ──► claude -p
                                     (link 2 — NOT this change)
```

The two branches are **mutually exclusive by construction**. `request()` fails closed on an inject-only
provider (`lib/Service/Credential/CredentialBrokerService.php:190-192`: "an unbounded host is exactly what
the constrained proxy exists to prevent"), and `resolveInjectable()` returns `null` for anything that is
not inject-only (`:266-268`), which is how a caller routes between them.

**This change adds one row to branch B and one line to Hermiq's manifest. It writes no code.** The broker
already does everything required; the only reason a `cli` turn cannot get a token today is that no
catalogue row expresses its shape.

### Why the shape is forced, not chosen

| Requirement of a CLI | Consequence |
|---|---|
| The token must be in the **process environment** | There is no HTTP request for the broker to interpose on — nothing to proxy |
| There is no fixed host the CLI's own traffic is bound to from OR's point of view | `baseUrl` is inexpressible — there is nothing to host-lock |
| There is no request method/path the broker can constrain | `allowRules` is inexpressible |
| An entry with no `baseUrl`/`allowRules` that were proxyable would be an **open proxy** | The broker MUST refuse it — and does |

Any two of these force `inject_only: true`. The remaining design decision is not *whether* to weaken the
zero-knowledge property, but *whether to ship a `cli` mode at all* — and that was decided upstream by the
Anthropic refusal documented in the proposal's Motivation.

## Declarative-vs-imperative decision (ADR-031)

**Not applicable, and genuinely so — this change contains no business logic to place.**

ADR-031 governs a specific choice: when behaviour attaches to an OpenRegister object write, declare it on
the schema (`x-openregister-*` in `lib/Settings/{app}_register.json`) rather than implementing it in a
service class. The ADR's subject matter is object behaviour — lifecycle/state machines, aggregations,
calculations, derived fields, notifications, declarative relations.

This change contains **no behaviour at all**. It is two JSON edits:

1. A row in a **server-side provider catalogue** — not an OpenRegister schema, not register-seeded objects,
   and explicitly outside the declarative-config surface: `credential-providers.json:2` records that the
   file "is NOT an OpenRegister schema, NOT register-seeded objects, and has no create/update/delete
   endpoint", and names itself an "ADR-031 exception" precisely because its immutability is a security
   control that ADR-031's runtime-configurable model would undermine.
2. A row in `src/manifest.json` — already the declarative surface ADR-024/ADR-036 prescribe, consumed by an
   existing renderer. Nothing to decide.

There is no service class this change could have written and did not. There is no rule, no computation, no
trigger, and nothing fires on an object write. The question ADR-031 asks — "could a schema declare this
instead of a service?" — has no subject here.

Worth stating explicitly, because the inverse mistake is the tempting one: the enforcement that *does*
exist (deny-proxy, owner guard, allowed-app guard) is **code that already ships in OpenRegister** and is
deliberately **not** made declarative. A runtime-editable allow-rule is a widenable security control, which
is the exact failure mode `credential-providers.json:2` was written to prevent. This change adds a row to a
reviewed, immutable file; it does not move a guard into config.

## API Design

**No API changes.** No new or modified endpoints, routes, or wire shapes.

Both consumed interfaces exist and are unchanged:

- `CredentialBrokerService::resolveInjectable(credentialId, appId, actingUserId): ?string`
  (`CredentialBrokerService.php:250-276`) — the app-side path this provider will be resolved through, by
  link 2. This change does not call it.
- The manifest `credentials[]` contract — `{provider, reason, scopes}`, already established at
  `src/manifest.json:7-35` and specced by the `agent-credentials` capability.
- OpenRegister's existing `CredentialController` create/list/delete surface — unchanged; it gains one more
  acceptable `provider` value because the catalogue it validates against gained a row.

→ `contract.md` is written despite the change adding no endpoint, because the **provider identifier itself
is the cross-repo interface**: Hermiq's manifest names a string that OpenRegister's catalogue must define,
and the two land in different repos in a required order. The contract records that coupling.

## Database Changes

**None.** Hermiq owns no tables (ADR-001 — thin client over OpenRegister). OpenRegister gains no table, no
column, and no schema: the provider catalogue is a read-only file loaded server-side, not storage.

The credential *objects* users create against this provider use the **existing** `credential` schema,
unchanged — this change adds a permitted value for an existing field, not a field.

→ `migration.md` is **skipped**: no tables, no columns, no OpenRegister schema definitions, no data
transformations.

## Nextcloud Integration

- **Controllers**: none — new or modified.
- **Services**: none. `CredentialBrokerService` is **unchanged**; its inject-only branch already exists and
  is already covered.
- **OCP interfaces**: none newly used.
- **Mappers/Entities**: none — Hermiq owns no tables.
- **Events/Hooks**: none.
- **Frontend**: none. The Credentials tab renders the new row through the existing shared credential
  component with no Vue change — it filters to app-declared providers, and the manifest declaration is what
  makes the row appear.

This section is short because the change is genuinely inert until link 2. That is the point of the ADR-032
split, not an omission.

## Security Considerations

### The credential trade-off — a conscious weakening, recorded plainly

This is the substance of the change and it is not softened.

**What is given up.** With `anthropic-cli`, the Claude Max token **leaves the vault into Hermiq's PHP
process on every call, and from there into the ExApp's environment**. This **consciously weakens the
broker's central property — "the app never sees the secret"** — which the host-locked proxy providers
(`anthropic`, `anthropic-oauth`) **do** preserve. An app compromise that could previously only *use* a
proxy credential (bounded by the allow-rules and the host-lock) can, with this provider, *read the token
itself*.

**Why it is unavoidable.** A CLI needs the token in its **environment**. There is no proxy seam to
interpose: the broker's proxy works by making the outbound HTTP call itself and substituting `{secret}`
into a header template, and a subprocess reading `ANTHROPIC_*` from its environment offers no such call to
intercept. The choice is app-side injection, or a `cli` mode that cannot authenticate — there is no third
option in which the CLI runs and the secret stays inside OpenRegister.

**What bounds it:**

| Bound | Mechanism |
|---|---|
| **Owner / IDOR guard still runs** | `resolveInjectable()` calls `loadAdmittedCredential()` — the scope-dispatched owner / organisation-membership check (`CredentialBrokerService.php:255-258`). A user cannot resolve another user's token. |
| **`allowedApps` guard still runs** | `assertAppAllowed()` (`:260-261`). Only apps the credential's owner has authorised can resolve it. |
| **It can never be proxied** | `request()` denies it outright (`:190-192`). The entry cannot degrade into an open proxy, which is the failure mode an unbounded-host entry would otherwise create. |
| **The secret is never in a schema** | It lives in **Doriath**; the app's config holds only a `credentialRef`. **Never store a secret in a schema — store a reference.** That is the point of the broker, and it is preserved here in full. |
| **Blast radius is one user** | A personal Claude Max subscription token, not an organisation key. Compare `generic-oauth2`, whose blast radius is an org's client secret. |
| **The proxy providers are untouched** | `anthropic` and `anthropic-oauth` remain host-locked and zero-knowledge. The `http` path loses nothing. |

**Precedent, not novelty.** The catalogue's `$injectOnlyComment` (line 4) already made this exact trade for
the five `generic-*` providers, in the same words: "the secret leaves OR into the trusted in-process app
(NOT zero-knowledge, unlike the SaaS proxy above), but it lives in Doriath and the app's schema holds only
a credentialRef — which is the whole point". This change adds a sixth entry of the same shape with a
**smaller** blast radius than the five that exist. It does not open a new category.

**What this change does NOT do.** It does not weaken any existing credential, does not touch the guards,
and does not make the catalogue widenable. The file remains runtime-immutable with no create/update/delete
endpoint (`credential-providers.json:2`), so this entry — and its consequences — ship only through a
reviewed release, which is the review you are reading.

### Personal scope only — an Anthropic Terms of Service constraint

**Claude Max/Pro OAuth is PERSONAL-SCOPE ONLY per the Anthropic Terms of Service.** The token serves only
its owner and MUST be rejected at organisation scope. Carried forward unchanged from
`anthropic-agent-provider`; the existing `anthropic-oauth` entry states it at
`credential-providers.json:183` and the new entry states it too.

**Recorded here, enforced in link 2.** This change is declarative JSON — it has no resolution path in which
to reject an organisation-scope credential, because nothing resolves the provider yet. The constraint is
declared at the point the provider is declared (its `$comment`, and a normative spec requirement with its
own scenario) so that link 2's resolver has an unambiguous rule to implement and a reviewer has something
to check it against. Stating this openly is deliberate: a constraint recorded in a comment with no
enforcement anywhere would be the orphaned-capability pattern, and naming the enforcing link is what keeps
it from becoming one.

### No secret material in either file

Neither JSON file contains a secret. `{secret}` in an `authScheme.template` is a **placeholder** the broker
substitutes at call time from the vault (`credential-providers.json:2`); the token never appears in the
catalogue. On an inject-only entry the `authScheme` is **descriptive only** — the consuming app decides how
to inject per its own needs — so the template is documentation of intent, not a live substitution site.

### The `authScheme` on an inject-only entry is not enforcement

Worth stating so a later reader does not over-read it: the `header`/`template` on `anthropic-cli` describes
the conventional shape, but nothing enforces it, because the broker never makes the call. Link 2 decides the
actual env var. A reviewer should not read `authScheme` on an inject-only provider as a control.

## File Structure

```
openregister/                                      (PR 1 — MUST land first)
  lib/Settings/
    credential-providers.json    MOD — + anthropic-cli inject-only entry; version 1.4.0 → 1.5.0

hermiq/                                            (PR 2 — after PR 1)
  src/
    manifest.json                MOD — + {provider: "anthropic-cli", reason, scopes} in credentials[]
  openspec/changes/cli-runner-credential-declaration/
    specs/agent-credentials/spec.md                the delta (hermiq's side only)
```

Two files. No PHP, no Vue, no tests to author — the behaviour this change relies on is already covered by
OpenRegister's existing `CredentialBrokerService` tests, and this change adds no behaviour of its own.

## Seed Data

**Not applicable.** This change introduces and modifies **no OpenRegister schemas**, so ADR-001's seed-data
requirement does not apply and `tasks.md` carries **no seed-data task**.

Stated precisely, because the entry is easy to mistake for one: **a credential-provider registration is not
a schema.** `credential-providers.json:2` says so in its own words — the file "is NOT an OpenRegister
schema, NOT register-seeded objects, and has no create/update/delete endpoint". It is a read-only catalogue
the broker reads server-side. There is no register to import, no schema to define, no object to seed, and
no repair step to write.

The `credential` objects users create against this provider use the **existing** `credential` schema,
unchanged — and those are user data, never seeded.

## Trade-offs

### Inject-only vs. extending the broker to support the CLI

**Chosen: inject-only.** The alternative — teaching the broker to launch a subprocess with an injected
environment — would put process execution inside OpenRegister's credential service. That is a far larger
security surface than the one this change accepts (OR would gain the ability to spawn processes with
secrets in their environment, on behalf of any app), it inverts ADR-001 (Hermiq owns the agent runtime, not
OR), and it would still hand the secret to the CLI's environment in the end — arriving at the same exposure
by a longer and more dangerous route. The trade-off is not avoidable by moving it.

### Reusing `anthropic-oauth` vs. a separate `anthropic-cli` entry

**Chosen: a separate entry.** Reuse is impossible, not merely undesirable: `anthropic-oauth` is a proxy
provider, so `resolveInjectable()` returns `null` for it (`:266-268`) and the CLI path would get nothing.
Making `anthropic-oauth` inject-only instead would **silently downgrade the existing `http` path** — every
current Claude Max user's credential would stop being zero-knowledge and start being readable by any app in
its `allowedApps`, with no user-visible signal. Two entries keep the two postures honest and let a user
decide, per credential, which exposure they accept. This mirrors the catalogue's own precedent for
`adyen`/`adyen-test` (`:52-67`): "A separate entry, not a flag" — a difference the broker enforces
structurally is a different provider.

### One entry vs. also adding `openai`/`grok` CLI providers

**Chosen: Anthropic only.** No verified official CLI exists for the others; the runner's `providers.js`
ships a `codex` adapter and a `grok` placeholder. Adding speculative catalogue rows to a
**runtime-immutable security file** would be exactly the wrong place to speculate — every row is a standing
permission. Add them when a CLI is verified.

### Chain vs. one envelope (ADR-032)

**Chosen: link 1 of a three-link chain.** One envelope covering the whole `cli` feature would be
`kind: mixed` — this declarative JSON plus substantial PHP and Node — which ADR-032 rejects outright: the
two Stage-A specs that failed burned a full 200-turn budget without producing a PR because a mixed envelope
exercises both reviewer surfaces against one budget. The thin-glue exception does **not** apply in reverse
either: the code in links 2 and 3 is far past ≤20 LOC across ≤2 files.

Splitting also buys the property ADR-032 calls expand-then-contract: **this link is safe to merge alone**
because nothing consumes the provider until link 2. A user can create the credential; no code reads it.
If links 2 and 3 never land, the cost is one unused catalogue row and one unused manifest entry.

**Mixed-spec rationale: not invoked.** This change is `kind: config` and touches no code — the inverse of
link 3, which is `kind: code` and touches no declarative JSON. The split is clean in both directions, which
is the sign it was cut in the right place.
