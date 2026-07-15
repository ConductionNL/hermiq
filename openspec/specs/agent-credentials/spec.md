# agent-credentials Specification

## Purpose
TBD - created by archiving change agent-credentials. Update Purpose after archive.
## Requirements
### Requirement: Manifest-declared credential requirements
The system MUST declare hermiq's external-provider credential needs (at
minimum `openai`, `fireworks`, and `github`) in `src/manifest.json`'s
`credentials[]` array, each with a human-readable `reason`, so that a
credential-management surface can show the user what hermiq uses and why
without hermiq hand-rolling that explanation in its own UI.

#### Scenario: A user opens hermiq's credential settings and sees what hermiq uses
- GIVEN hermiq's manifest declares `credentials: [{provider: "openai", reason: "Chat/embedding generation for agents using the OpenAI driver."}, ...]`
- WHEN a user opens the "Agent credentials" section of hermiq's Settings page
- THEN the personal-scope credential surface MUST show "What Hermiq uses" listing OpenAI, Fireworks AI, and GitHub with their declared reasons
- AND no secret value is ever displayed anywhere on this surface

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

