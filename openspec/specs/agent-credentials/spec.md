# agent-credentials Specification

## Purpose
TBD - created by archiving change agent-credentials. Update Purpose after archive.
## Requirements
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

### Requirement: Personal and organisation credential management surfaces
The system MUST surface both a personal-scope and an organisation-scope
credential-management surface for hermiq, reachable from hermiq's in-app
Settings page, built from the shared `CnCredentials` component (never a
hand-rolled credential form).

#### Scenario: A user manages their own personal credential for hermiq
- GIVEN a signed-in user with no personal OpenAI broker credential yet
- WHEN they open hermiq's Settings → Agent credentials → "Your credentials" and add a new OpenAI credential
- THEN the system MUST create a personal-scope broker credential owned by that user with `hermiq` in its `allowedApps`
- AND the secret MUST be sent once to OpenRegister and never returned or displayed again

#### Scenario: An organisation admin manages an organisation-wide credential for hermiq
- GIVEN a user who is an administrator of their active organisation
- WHEN they open hermiq's Settings → Agent credentials → "Organisation credentials" and add a new Fireworks AI credential
- THEN the system MUST create an organisation-scope broker credential owned by that organisation with `hermiq` in its `allowedApps`
- AND any member of that organisation MUST be able to see the credential's metadata (name, provider) on this surface, never its secret

#### Scenario: A non-admin organisation member cannot administer organisation credentials
- GIVEN a user who is a member but not an administrator of their active organisation
- WHEN they attempt to create or delete an organisation-scope credential from hermiq's Settings page
- THEN the system MUST reject the write (the existing OpenRegister `CredentialController` admin guard, unchanged by this capability)
- AND the organisation's existing credentials MUST remain unchanged

### Requirement: Run-time credential resolution precedence
The system MUST resolve, at agent-run time, which broker credential the
`openai` and `fireworks` chat drivers use by trying, in order: (1) the acting
user's own personal broker credential for that provider allowing `hermiq`;
(2) else the agent's organisation's organisation-scope broker credential for
that provider allowing `hermiq`; (3) else the instance-wide credential
configured in `hermiq.llm.<provider>Config.credentialId`. Resolution MUST be
skipped entirely (falling straight to today's instance-only behaviour) when no
organisation is supplied to the resolving call, matching the existing
`tenant-model-policy` enforcement opt-in shape.

#### Scenario: A user's personal credential overrides the instance default
- GIVEN a user with a personal OpenAI broker credential allowing `hermiq`
- AND an instance-wide OpenAI credential configured in `hermiq.llm`
- WHEN that user runs an agent configured with `provider: "openai"`
- THEN the system MUST use the user's personal credential for the call
- AND the instance-wide credential MUST NOT be used for this run

#### Scenario: An organisation credential is used when no personal credential exists
- GIVEN a user with no personal OpenAI broker credential
- AND their organisation has an organisation-scope OpenAI broker credential allowing `hermiq`
- WHEN that user runs an agent configured with `provider: "openai"` belonging to that organisation
- THEN the system MUST use the organisation's credential for the call

#### Scenario: The instance default is used when neither personal nor organisation credential exists
- GIVEN a user with no personal OpenAI broker credential
- AND an organisation with no organisation-scope OpenAI broker credential
- WHEN that user runs an agent configured with `provider: "openai"`
- THEN the system MUST fall back to the instance-wide credential configured in `hermiq.llm.openaiConfig.credentialId`
- AND this MUST behave identically to the pre-existing behaviour before this capability existed

#### Scenario: A credential for a different provider or not allowed for hermiq is never selected
- GIVEN a user with a personal GitHub broker credential and a personal Fireworks broker credential not allowing `hermiq`
- WHEN that user runs an agent configured with `provider: "openai"`
- THEN neither the GitHub credential (wrong provider) nor the disallowed Fireworks credential MUST be selected
- AND resolution MUST fall through to the organisation, then instance, default exactly as if neither existed

### Requirement: Resolver selections never bypass the broker's own guards
The system MUST treat every credential id the resolver selects as a mere
candidate: `CredentialBrokerService`'s owner, `allowedApps`, provider
allow-rules, and host-lock guards MUST still run, unchanged, on every call the
selected credential is used for.

#### Scenario: A resolved personal credential that is later revoked still fails closed at the broker
- GIVEN a user's personal OpenAI credential was selected earlier in a long-running schedule context
- AND the user deletes that credential before the run actually executes
- WHEN the run attempts to call the broker with the now-deleted credential id
- THEN the broker MUST refuse the call (the credential no longer resolves to a valid, owned object)
- AND the run MUST fail closed with a clear error, never silently substitute a different credential

### Requirement: An agent can carry its own credential per provider (REQ-AGCRED-001)

The Agent MUST accept an optional `credentialIds` map from provider to a broker credential uuid. The agent form MUST let the owner pick, per provider, one of the credentials they may use for hermiq. Only the owner and administrators MUST be able to change it.

#### Scenario: An owner gives an agent its own key
- GIVEN the owner of "Supplier digest" and "Permit helper", both in the same organisation
- WHEN they open "Supplier digest" in the agent form, pick the credential "Digest OpenAI key" for openai and save
- THEN the next turn of "Supplier digest" uses that key and "Permit helper" keeps using the organisation's key

### Requirement: A pinned credential goes first and is never bypassed (REQ-AGCRED-002)

When an agent has a credential pinned for the provider of a turn, the system MUST use it before the personal and organisation credentials. When the broker refuses the pinned credential, the turn MUST stop with "The credential pinned to this agent cannot be used for this run." and MUST NOT use another credential.

#### Scenario: The pinned key is no longer allowed
- GIVEN an agent pinned to a credential whose allowed apps no longer include hermiq
- WHEN a user sends it a message
- THEN the chat shows "The credential pinned to this agent cannot be used for this run." and no model is called
- @e2e exclude broker refusal needs a second credential store state, covered by PHPUnit on the resolver and ProviderFactory

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

