# tenant-model-policy Specification

## Purpose
TBD - created by archiving change tenant-model-policy. Update Purpose after archive.
## Requirements
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

### Requirement: A model policy may name a fallback chain (REQ-MFAIL-001)

A `ModelPolicy` MAY carry `fallbackChain`, an ordered list of at most four provider and model pairs. Hermiq MUST refuse to save a chain entry that the same policy does not allow.

#### Scenario: An organisation admin adds a local model as fallback
- GIVEN an organisation admin on the Tenant operations page, in the Model policy section, with a policy allowing `anthropic` and `ollama`
- WHEN they add `ollama` with model `qwen2.5` under "Fallback when a provider fails" and save
- THEN the policy shows the fallback, and `GET /api/model-policy/effective` returns it in `fallbackChain`

#### Scenario: A fallback outside the policy is refused
- GIVEN a policy that allows only `ollama`
- WHEN the organisation admin adds `openai` with `gpt-4o-mini` as a fallback and saves
- THEN the save is refused with "This fallback is not allowed by the model policy." and the stored policy is unchanged

### Requirement: A temporary provider failure moves the turn to the next allowed hop (REQ-MFAIL-002)

When a provider call fails with a rate limit, a server error, a timeout, a connection error or an unavailable provider, and no answer text has been shown and no tool has run in the turn, hermiq MUST retry the turn on the next hop of the effective fallback chain. Before each hop hermiq MUST run the same model policy check and, when the run names an AI feature, the same residency and redaction checks as for the first provider. A hop that fails a check MUST be skipped and recorded, and MUST NOT be called.

#### Scenario: The hosted model hits its rate limit
- GIVEN an agent in organisation "Gemeente Voorbeeld" whose policy lists `anthropic` first and `ollama` `qwen2.5` as fallback
- WHEN a case handler sends a message in the chat and Anthropic answers HTTP 429
- THEN the reply comes from `ollama` `qwen2.5` in the same turn, and the run's steps show "Anthropic was rate limited. Answered by ollama qwen2.5."
- @e2e exclude needs a hosted provider that answers HTTP 429 on demand; covered by ResponseGenerationHandlerTest::testARateLimitedTurnIsAnsweredByTheNextHop and ProviderFallbackLoopTest

#### Scenario: A fallback that breaks the feature's residency is skipped
- GIVEN an agent whose AI feature requires residency `eu`, and a chain whose first hop `openai` is declared `outside-eu` and whose second hop `fireworks` is declared `eu`
- WHEN the first provider fails with HTTP 503
- THEN hermiq does not call `openai`, records it as skipped with step `residency`, and answers from `fireworks`
- @e2e exclude needs a provider that fails on demand; covered by PHPUnit on the fallback loop with stubbed drivers

#### Scenario: A failure after a tool call is not retried elsewhere
- GIVEN a turn in which the model already called `hermiq.createCalendarEvent`
- WHEN the provider then fails with HTTP 500
- THEN the turn fails as it does today, no other provider is called, and the run says "Not retried: a tool already ran in this turn"
- @e2e exclude needs a provider that fails on demand after a tool call; covered by PHPUnit on the fallback loop

### Requirement: A refusal or a configuration error never triggers a fallback (REQ-MFAIL-003)

A model policy refusal, a residency refusal, a redaction refusal, an authentication failure (HTTP 401 or 403) and a request the provider rejects as malformed (HTTP 400) MUST end the turn without trying another hop.

#### Scenario: A revoked key is shown, not hidden
- GIVEN a fallback chain and a first provider whose key was revoked in the credential broker
- WHEN a scheduled run starts and the provider answers HTTP 401
- THEN the run fails with the provider's authentication message, and no other provider is called
- @e2e exclude needs a revoked provider key; covered by PHPUnit with a stubbed broker response

### Requirement: A failing provider cools down per organisation (REQ-MFAIL-004)

After a hop fails with a rate limit or a server error, hermiq MUST skip that provider for that organisation for the provider's `retry-after`, or 60 seconds when none was sent. A cooldown MUST NOT last longer than 600 seconds and MUST NOT affect another organisation.

#### Scenario: The next turn starts at the healthy hop
- GIVEN Anthropic answered HTTP 429 with `retry-after: 30` for "Gemeente Voorbeeld" ten seconds ago
- WHEN another case handler in the same organisation sends a message
- THEN hermiq does not call Anthropic, records it as cooling, and answers from the next hop
- @e2e exclude timing across two turns against a failing provider; covered by PHPUnit with a fake cache and clock

### Requirement: Every attempt is on the run record (REQ-MFAIL-005)

Hermiq MUST record each attempt of a turn on the run: provider, model, outcome (`answered`, `failed`, `skipped` or `cooling`), reason and duration. The provider disclosure MUST name the provider and model that answered and MUST list in `receivedBy` every provider and model that was sent the prompt.

#### Scenario: A privacy officer reads where a prompt went
- GIVEN a scheduled run that fell back from `anthropic` to `ollama`
- WHEN a privacy officer opens that run on the Runs page
- THEN the run shows both attempts in order, the first marked "rate limited", and the disclosure lists both providers under "Received the prompt"

