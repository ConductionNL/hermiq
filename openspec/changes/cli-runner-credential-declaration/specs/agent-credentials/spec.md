# agent-credentials Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `cli-runner-credential-declaration` — adds the `anthropic-cli` inject-only provider declaration

## Purpose

Hermiq declares which external-provider credentials it needs and why, and resolves them at run time
through OpenRegister's credential broker, so no secret is ever stored in a Hermiq schema or held in
Hermiq's own custody (ADR-005; ADR-022 — apps consume OpenRegister abstractions). This change extends the
capability to cover a credential the broker's **constrained proxy cannot carry**: the token the official
`claude` CLI needs in its process **environment**. That is expressed with an `inject_only` provider, which
is a deliberate and bounded weakening of the broker's zero-knowledge property — recorded here as a
normative requirement rather than a code comment (ADR-032 link 1 of 3).

## ADDED Requirements

### Requirement: Anthropic CLI credential provider is registered as inject only
The OpenRegister credential-provider catalogue MUST register an `anthropic-cli` provider that carries
`inject_only: true`, **no** `baseUrl`, and **no** `allowRules`, so that a Claude subscription token
destined for the `claude` CLI's process environment can be stored in the vault and resolved app-side.

This shape is forced, not chosen. `CredentialBrokerService::resolveInjectable()` returns `null` unless
the resolved provider is inject-only, so app-side resolution is available to no other provider shape; and
a CLI needs its credential in an environment variable, so there is no host to lock and no request to
constrain. The `authScheme` on an inject-only entry is **descriptive only** — the consuming app decides
how to inject. The catalogue file is runtime-immutable: the entry MUST ship in a reviewed release and MUST
NOT be creatable or widenable through any API.

@e2e exclude Declarative provider-catalogue registration in a runtime-immutable backend JSON file with no UI surface — covered by OpenRegister's own PHPUnit coverage of the catalogue loader and `resolveInjectable()`

#### Scenario: The catalogue registers anthropic-cli without a baseUrl or allowRules
- GIVEN OpenRegister's `lib/Settings/credential-providers.json`
- WHEN the credential-provider catalogue is loaded
- THEN it MUST contain a provider whose `identifier` is `anthropic-cli`
- AND that provider MUST carry `inject_only: true`
- AND that provider MUST NOT carry a `baseUrl` key
- AND that provider MUST NOT carry an `allowRules` key
- AND its `$comment` MUST record that the secret leaves OpenRegister into the calling app

#### Scenario: A user can create an anthropic-cli credential
- GIVEN a signed-in user and a catalogue containing the `anthropic-cli` provider
- WHEN they create a personal-scope credential with provider `anthropic-cli` and `hermiq` in its `allowedApps`
- THEN the system MUST accept the credential and store the secret in the vault
- AND the secret MUST NOT be returned or displayed again on any surface
- AND the credential object MUST hold only a reference to the secret, never the secret itself

#### Scenario: An unknown provider is still rejected
- GIVEN a catalogue that registers `anthropic-cli`
- WHEN a caller attempts to create a credential for a provider identifier the catalogue does not register
- THEN the system MUST reject the write
- AND registering `anthropic-cli` MUST NOT make any other unregistered provider creatable

### Requirement: The inject only anthropic CLI credential is never proxied
The system MUST refuse to proxy an `anthropic-cli` credential through the credential broker's constrained
proxy, and MUST make its secret reachable only through the app-side injection path, which MUST still
enforce the owner and allowed-app guards.

An inject-only provider carries no host-lock and no allow-rules, so proxying it would make the broker an
unbounded open proxy — exactly what the constrained proxy exists to prevent. The broker therefore fails
closed on that path. The two guards that remain meaningful without a host — Guard 1 (owner /
organisation-membership, the IDOR guard) and Guard 2 (`allowedApps`) — MUST both still run before the raw
secret is returned. The trade-off is deliberate and bounded: the secret crosses the process boundary into
the trusted same-instance calling app, which is a weakening of the zero-knowledge property that the
host-locked `anthropic` and `anthropic-oauth` proxy providers preserve, and it is accepted only because a
CLI cannot be reached through a proxy seam at all.

@e2e exclude Backend broker enforcement with no UI surface — covered by OpenRegister's PHPUnit coverage of `CredentialBrokerService::request()` and `resolveInjectable()`

#### Scenario: The broker refuses to proxy an anthropic-cli credential
- GIVEN a stored `anthropic-cli` credential owned by the acting user and allowing `hermiq`
- WHEN a caller asks the broker to perform a proxied request with that credential
- THEN the broker MUST deny the request
- AND the denial MUST NOT depend on the requested method or path — no request shape is ever proxied
- AND no secret MUST appear in the denial response or in any log line

#### Scenario: App-side resolution returns the secret only after both guards pass
- GIVEN a stored `anthropic-cli` credential owned by the acting user and allowing `hermiq`
- WHEN `hermiq` resolves the credential for app-side injection
- THEN the system MUST enforce the owner guard and the allowed-app guard before returning anything
- AND MUST return the raw secret only when both guards pass

#### Scenario: A credential belonging to another user is never resolvable
- GIVEN an `anthropic-cli` credential owned by a different user
- WHEN the acting user resolves that credential by its identifier for app-side injection
- THEN the system MUST deny the resolution on the owner guard
- AND MUST NOT return the secret

#### Scenario: A credential that does not allow hermiq is never resolvable by hermiq
- GIVEN an `anthropic-cli` credential owned by the acting user whose `allowedApps` does not include `hermiq`
- WHEN `hermiq` resolves that credential for app-side injection
- THEN the system MUST deny the resolution on the allowed-app guard
- AND MUST NOT return the secret

#### Scenario: A host-locked proxy provider stays zero-knowledge
- GIVEN a stored `anthropic-oauth` credential owned by the acting user and allowing `hermiq`
- WHEN `hermiq` resolves that credential for app-side injection
- THEN the system MUST NOT return a secret, signalling that the credential is a proxy credential
- AND the `anthropic` and `anthropic-oauth` providers MUST remain host-locked proxy providers, unchanged by this capability

### Requirement: Claude subscription credentials are personal scope only
The system MUST treat a Claude Max or Pro subscription credential as **personal-scope only**, per the
Anthropic Terms of Service: the token serves only its owner and MUST be rejected at organisation scope.

This applies to both `anthropic-oauth` and the new `anthropic-cli` provider. It is a declarative
constraint recorded at the point the provider is declared; enforcement at resolution time is the
responsibility of the resolver introduced by the `cli-runner-text-turn-dispatch` change.

@e2e exclude Terms-of-service scope constraint declared in backend JSON, enforced by a resolver that does not exist until the successor change — no UI surface in this change

#### Scenario: The provider declaration records the personal-scope constraint
- GIVEN the `anthropic-cli` provider entry in the credential-provider catalogue
- WHEN the entry is reviewed
- THEN its `$comment` MUST state that a Claude Max or Pro subscription token is personal-scope only per the Anthropic Terms of Service
- AND MUST state that it is rejected at organisation scope

#### Scenario: An organisation-scope subscription credential is not offered as a shared organisation resource
- GIVEN a user viewing the organisation credentials surface for hermiq
- WHEN they inspect what hermiq declares
- THEN the `anthropic-cli` credential MUST be presented as a personal credential
- AND MUST NOT be presented as an organisation-wide credential to be shared across members

## MODIFIED Requirements

### Requirement: Manifest-declared credential requirements
The system MUST declare hermiq's external-provider credential needs (at minimum `openai`, `fireworks`,
`github`, `anthropic`, `anthropic-oauth`, and `anthropic-cli`) in `src/manifest.json`'s `credentials[]`
array, each with a human-readable `reason`, so that a credential-management surface can show the user what
hermiq uses and why without hermiq hand-rolling that explanation in its own UI.

Every provider hermiq declares MUST be registered in OpenRegister's credential-provider catalogue.
Because the credential surface filters to app-declared providers, a provider hermiq does not declare is
not offered to the user at all — so the declaration, not the catalogue entry, is what makes a credential
reachable.

@e2e exclude Manifest-declared credential metadata rendered by the shared credential surface — the surface itself is covered by this capability's existing personal and organisation credential-management requirements

#### Scenario: A user opens hermiq's credential settings and sees what hermiq uses
- GIVEN hermiq's manifest declares `credentials: [{provider: "openai", reason: "Chat/embedding generation for agents using the OpenAI driver."}, ...]`
- WHEN a user opens the "Agent credentials" section of hermiq's Settings page
- THEN the personal-scope credential surface MUST show "What Hermiq uses" listing OpenAI, Fireworks AI, and GitHub with their declared reasons
- AND no secret value is ever displayed anywhere on this surface

#### Scenario: The manifest declares the anthropic-cli credential with a reason
- GIVEN hermiq's `src/manifest.json`
- WHEN its `credentials[]` array is read
- THEN it MUST contain an entry whose `provider` is `anthropic-cli`
- AND that entry MUST carry a human-readable `reason` naming the CLI execution mode it serves
- AND that entry MUST follow the same `{provider, reason, scopes}` shape as the existing entries

#### Scenario: The Credentials tab offers anthropic-cli because hermiq declares it
- GIVEN hermiq declares `anthropic-cli` in its manifest and OpenRegister's catalogue registers it
- WHEN a user opens hermiq's Settings and the "Agent credentials" section
- THEN the surface MUST offer `anthropic-cli` as a credential the user can add
- AND MUST show its declared reason
- AND MUST NOT offer any provider hermiq does not declare

## Non-Functional Requirements

- **Performance:** No runtime cost. Both edits are declarative JSON read on an existing path — the catalogue is loaded server-side as it already is, and the manifest is read as it already is. No new request, query, or allocation is introduced.
- **Accessibility:** The `anthropic-cli` row is rendered by the existing shared credential component, inheriting its WCAG 2.1 AA behaviour. No new markup is introduced by this change.
- **Internationalization:** The manifest `reason` MUST be authored in English as the source language (ADR-007/ADR-025), and is surfaced through the existing credential surface's translation path — no new translation seam.
- **Security:** No secret MUST appear in either JSON file. The `authScheme.template` placeholder is substituted at call time and the token itself never appears in the catalogue.

## Acceptance Criteria

- [ ] `credential-providers.json` registers `anthropic-cli` with `inject_only: true`, no `baseUrl`, no `allowRules`
- [ ] The catalogue `version` is bumped and both JSON files remain valid JSON
- [ ] The entry's `$comment` records the inject-only trade-off and the personal-scope constraint
- [ ] `src/manifest.json` declares `anthropic-cli` in `credentials[]` with a `reason`
- [ ] The broker denies a proxied request for an `anthropic-cli` credential
- [ ] App-side resolution of an `anthropic-cli` credential enforces the owner and allowed-app guards
- [ ] `anthropic` and `anthropic-oauth` remain host-locked proxy providers, unchanged
- [ ] No secret value appears in either JSON file

## Notes

- **Two repositories, one logical change.** The catalogue entry lives in `openregister`; the manifest
  declaration lives in `hermiq`. The catalogue entry MUST land first — a manifest entry for an
  unregistered provider would offer the user a row whose save fails. The credential-broker capability's
  canonical spec home is the `openregister` repo, so this delta covers only hermiq's side; the
  OpenRegister-side requirement is flagged for a mirrored delta in that repo.
- **ADR-032** — link 1 of 3. This change is `kind: config`: declarative JSON only, no code. The `cli`
  dispatch (link 2) and the governed MCP/egress transport (link 3) are `kind: code` and depend on this.
- **ADR-005** — the inject-only path is a recorded, bounded deviation from the broker's zero-knowledge
  posture, not an oversight. See `design.md`, "Risks".
- **No OpenRegister schema is introduced or modified.** A credential-provider registration is a
  server-side catalogue row, not a schema — so ADR-001's seed-data requirement does not apply and no
  seed-data task exists.
