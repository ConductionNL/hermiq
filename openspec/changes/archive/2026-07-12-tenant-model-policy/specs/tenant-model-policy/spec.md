# tenant-model-policy (delta)

New capability: a per-organisation `ModelPolicy` object that allowlists which chat
provider(s)/model(s) an organisation's agents may use, an instance-admin fallback policy for
organisations without one, and run-time enforcement that refuses an out-of-policy run with a
clear audit entry and user-visible error instead of silently substituting a model.

## ADDED Requirements

### Requirement: Per-organisation model policy object
The system MUST let an organisation configure a `ModelPolicy`: an allowlist of
`{provider, models[]}` pairs drawn from the four supported chat drivers
(`openai`/`ollama`/`fireworks`/`nextcloud`) and an optional `defaultModel`. An empty
`models[]` for an allowed provider MUST mean "any model id for that provider is permitted."
At most one `ModelPolicy` MUST exist per organisation.

#### Scenario: An org-subadmin restricts their organisation to local inference only
- **GIVEN** an organisation with no existing `ModelPolicy`
- **WHEN** an org-subadmin creates a `ModelPolicy` allowing only `{provider: "ollama", models: []}`
- **THEN** the system MUST persist exactly one `ModelPolicy` for that organisation
- **AND** the policy MUST allow any Ollama model and no other provider

#### Scenario: An org-subadmin sets a default model
- **GIVEN** an organisation's `ModelPolicy` allowing `ollama`
- **WHEN** the org-subadmin sets `defaultModel` to `{provider: "ollama", model: "qwen2.5"}`
- **THEN** the system MUST persist the default alongside the allowlist
- **AND** the default MUST itself be one of the allowed provider/model combinations

### Requirement: Instance-admin fallback policy
The system MUST apply an instance-wide default `ModelPolicy` (organisation-less, managed by an
instance admin) to any organisation that has not configured its own. When neither an
organisation-specific nor an instance-wide `ModelPolicy` exists, the system MUST constrain
agents to the provider currently selected in the instance's `hermiq.llm` configuration rather
than leaving every driver unconstrained.

#### Scenario: An organisation with no policy uses the instance default
- **GIVEN** an organisation with no `ModelPolicy` of its own
- **AND** an instance-wide default `ModelPolicy` allowing `openai` and `ollama`
- **WHEN** the system resolves the effective policy for that organisation
- **THEN** the instance-wide default MUST be used
- **AND** an agent in that organisation selecting `fireworks` MUST be rejected

#### Scenario: No policy exists anywhere on a fresh install
- **GIVEN** an instance where no `ModelPolicy` object (organisation-specific or instance-wide)
  has ever been created
- **WHEN** the system resolves the effective policy for any organisation
- **THEN** the system MUST constrain agents to the single provider currently configured in
  `hermiq.llm.chatProvider`
- **AND** MUST NOT treat the absence of a policy as "every provider is allowed"

### Requirement: Run-time enforcement of the effective model policy
The system MUST check the resolved `(provider, model)` pair for every agent turn — regardless
of whether the run was triggered by a schedule tick, a manual "Run now," an interactive
conversation, or a flow listener — against the calling agent's effective `ModelPolicy` (its
organisation's own policy, else the instance-wide default, else the fail-closed instance
provider) before invoking the provider. An out-of-policy resolution MUST refuse the run: it
MUST NOT silently substitute an allowed provider/model, and MUST NOT partially execute the
turn.

#### Scenario: A scheduled run resolves to an out-of-policy provider
- **GIVEN** an organisation whose `ModelPolicy` allows only `ollama`
- **AND** an `Agent` in that organisation with `provider` set to `openai`
- **WHEN** the agent's schedule fires
- **THEN** the system MUST refuse to invoke the `openai` driver
- **AND** the schedule's run MUST be recorded with an error status and a message naming the
  rejected provider
- **AND** the refusal MUST appear in the run's audit trail entry

#### Scenario: An interactive chat turn resolves to an out-of-policy model
- **GIVEN** an organisation whose `ModelPolicy` allows `openai` only for model `gpt-4o-mini`
- **AND** an `Agent` in that organisation with `model` set to `gpt-4o` (not `gpt-4o-mini`)
- **WHEN** a user sends a chat message to that agent
- **THEN** the system MUST refuse to generate a response via the disallowed model
- **AND** the user MUST see a clear, generic-safe error explaining the run was blocked by
  organisation policy, without leaking provider credentials or internal configuration

#### Scenario: A flow-triggered run respects the same policy as a schedule
- **GIVEN** an organisation whose `ModelPolicy` allows only `ollama`
- **AND** an `Agent` in that organisation configured with `provider: "fireworks"`
- **WHEN** an OpenRegister flow event triggers that agent to run
- **THEN** the system MUST refuse the run using the same policy check a scheduled run would
  apply
- **AND** the refusal MUST be recorded on the run's audit entry

#### Scenario: An in-policy run proceeds unaffected
- **GIVEN** an organisation whose `ModelPolicy` allows `ollama` with model `qwen2.5`
- **AND** an `Agent` in that organisation with `provider: "ollama"`, `model: "qwen2.5"`
- **WHEN** the agent runs, by any trigger
- **THEN** the system MUST proceed exactly as it does today, with no additional latency-
  visible behavior beyond the one allowlist check

### Requirement: Model policy authorization
The system MUST restrict who may write a `ModelPolicy`: an org-subadmin MAY write only their
own organisation's policy; only an instance admin MAY write the organisation-less
instance-wide default policy. Any authenticated user with access to the organisation MAY read
their organisation's effective policy (to populate the agent form).

#### Scenario: An org-subadmin cannot write another organisation's policy
- **GIVEN** an org-subadmin of organisation A
- **WHEN** they attempt to update organisation B's `ModelPolicy`
- **THEN** the system MUST reject the write
- **AND** organisation B's policy MUST remain unchanged

#### Scenario: A non-admin user can read their organisation's effective policy
- **GIVEN** a regular (non-subadmin) member of organisation A with an existing `ModelPolicy`
- **WHEN** they request their effective model policy (to build an agent)
- **THEN** the system MUST return organisation A's policy (or the instance default if A has
  none)
- **AND** MUST NOT require organisation-admin privileges for this read

## Non-Functional Requirements

- **Performance:** The policy check MUST add no more than one additional OpenRegister object
  read per agent turn (mirroring the existing kill-switch check's single query), not one read
  per candidate provider/model.
- **Accessibility:** The policy management UI (out of this spec's scope beyond the API; see
  `agent-management-ui` delta) MUST meet WCAG 2.1 AA when built.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for all
  policy-violation error messages surfaced to the user.

## Acceptance Criteria

- [ ] A `ModelPolicy` OpenRegister object exists, one per organisation plus one org-less
      instance default, editable via API.
- [ ] Every agent-turn trigger path (schedule, Run now, conversation, flow listener) is
      blocked from resolving an out-of-policy provider/model.
- [ ] A blocked run produces a clear, audited error rather than a silent fallback.
- [ ] An organisation with no policy inherits the instance default; an instance with no policy
      anywhere is constrained to its current `hermiq.llm` provider, not left fully open.
- [ ] Only an org-subadmin (own org) or instance admin (instance default) may write a policy.

## Notes

Depends on `TenantControlService`'s existing per-organisation-object pattern (same
`_rbac: false, _multitenancy: false`, `ObjectEntity.organisation`-matched read) and on
`ProviderFactory`/`LlmSettingsHandler` as the single seam every trigger path already resolves
a driver through. Related: `multi-tenant-ops` (sovereignty — local inference + AI Act audit
export), `agent-management-ui` (the filtered model picker), `agent-capability-profile`
(precedent for tightening a previously free-input agent field into a governed allowlist).
Data-classification (content-based) routing is an explicitly deferred future refinement of
this same object, not delivered here.
