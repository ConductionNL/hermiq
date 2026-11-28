# ai-feature-governance

## ADDED Requirements

### Requirement: An AI feature MAY bind its own provider and model

The system MUST let an `AiFeature` carry an optional `provider` and `model`. When
both are set, runs of that feature MUST use them. When they are unset, the feature
MUST fall back to the organisation's effective `ModelPolicy` default, which is the
behaviour today.

A binding MUST NOT be writable when the organisation's effective `ModelPolicy` does
not permit the pair, and the refusal MUST name the policy that forbids it.

Candidate C-integrations-38 (`integrations.tsv:34`), relevance **`must`**, driven
passer zammad: AI provider and features at `config/routes/ai_provider_connection.rb`,
`ai_agent.rb`, `ai_text_tool.rb` and `ai_vector_index.rb`, admin areas `AI::Provider`
and `AI::Assistance`, permissions `admin.ai_provider` and
`admin.ai_assistance_ticket_summary`.

#### Scenario: Two features, two providers

- **GIVEN** an organisation whose `ModelPolicy` permits both `ollama` and `openai`
- **WHEN** one feature is bound to `ollama` and another to `openai`
- **THEN** each feature's runs MUST use its own provider

#### Scenario: A binding outside the policy is refused at write time

- **GIVEN** an organisation whose `ModelPolicy` permits only `ollama`
- **WHEN** a feature is bound to `openai`
- **THEN** the write MUST be refused, naming the policy

#### Scenario: An unbound feature keeps today's behaviour

- **GIVEN** a feature with no provider or model set
- **WHEN** it runs
- **THEN** the effective `ModelPolicy` default MUST be used

### Requirement: The feature binding narrows the policy and is re-checked on every turn

The system MUST resolve a run's `(provider, model)` pair as: the feature's binding
when set, otherwise the effective `ModelPolicy` default, and MUST then check the
resolved pair against the effective `ModelPolicy` on **every** turn, whatever
triggered the run.

A policy narrowed after a binding was written MUST take effect without the feature
being revisited. A feature binding MUST NOT be able to widen what a policy permits.

#### Scenario: Narrowing the policy disables a stale binding

- **GIVEN** a feature bound to `openai` under a policy that permitted it
- **WHEN** the organisation's policy is narrowed to `ollama` only
- **THEN** the next run of that feature MUST be refused, naming the policy, with no
  edit to the feature

#### Scenario: One enforcement point, whatever the trigger

- **GIVEN** a feature whose binding is outside the current policy
- **WHEN** it is run from a schedule tick, a manual run and an interactive turn
- **THEN** all three MUST be refused identically

### Requirement: A configured provider MUST declare where it runs

The system MUST let each configured provider carry a `residency` of `on-premise`,
`eu` or `outside-eu`, plus a free-text `location`. The residency MUST be administered
by whoever configures the provider and MUST NOT be inferred from an endpoint hostname
or address.

A `.eu` hostname resolving outside the EU, and a local reverse proxy in front of a
hosted model, are both ordinary. A guessed residency is one no data protection officer
can rely on, and reliance is the whole purpose of the field.

#### Scenario: Residency is a statement, not a guess

- **WHEN** a provider is configured with an endpoint whose hostname suggests one
  region
- **THEN** the system MUST NOT set a residency from that hostname, and MUST require
  the administrator to state it

#### Scenario: The detail an enum cannot carry is kept

- **GIVEN** a provider declared `eu`
- **WHEN** its location is read
- **THEN** the free-text location the administrator typed MUST be returned with it

### Requirement: A feature MAY require a residency, and a run outside it is refused before the call

The system MUST let an `AiFeature` declare a `requiredResidency`. When it is set, a
run whose resolved provider carries a different residency MUST be refused **before**
any request reaches the provider. The refusal MUST name the feature, the required
residency and the provider's actual residency.

The check MUST run in the same place as the model-policy check, in the order: resolve
the feature binding, narrow by policy, check residency, then call. Each step MUST name
itself when it refuses.

A run refused after the text has been sent records a breach rather than preventing
one, which is why the ordering is specified rather than left to the implementation.

#### Scenario: Case text never leaves for a forbidden region

- **GIVEN** a feature requiring `on-premise` and a provider carrying `outside-eu`
- **WHEN** the feature runs
- **THEN** no request MUST reach the provider, and the refusal MUST name both
  residencies

#### Scenario: The refusing step names itself

- **GIVEN** a run refused on residency and another refused on model policy
- **WHEN** each refusal is read
- **THEN** each MUST say which check refused it

#### Scenario: No required residency refuses nothing

- **GIVEN** a feature with no `requiredResidency`
- **WHEN** it runs against any permitted provider
- **THEN** no residency refusal MUST occur

### Requirement: Every run MUST record the feature, the provider and the residency in force

The system MUST record, on each run entry written to the audit trail, the AI feature
the run belongs to, the provider and model actually used, and the provider's residency
and location **as they stood at the time of the run**.

The residency MUST be copied onto the run rather than referenced, so relabelling a
provider later MUST NOT change what earlier runs say.

#### Scenario: Which model saw this case, and where, is a read

- **GIVEN** a completed run
- **WHEN** its audit entry is read
- **THEN** it MUST carry the feature, the provider, the model, the residency and the
  location

#### Scenario: Relabelling a provider does not rewrite history

- **GIVEN** runs recorded while a provider was labelled `eu`
- **WHEN** the provider is relabelled `outside-eu`
- **THEN** those earlier runs MUST still read `eu`

### Requirement: A split deployment MUST be expressible as configuration

An instance running on premise MUST be able to bind a feature to a provider whose
residency is `eu` or `outside-eu`, with no change to where its objects are stored. The
residency label MUST make visible exactly which features send data off the premises.

Candidate C-configuration-75 (`configuration.tsv:111`), relevance `could`. **No
driven passer.** The evidence is decos-join's documented `/zaaksysteem/joni-plus`
page, admitted under decision D21 and labelled documented here.

#### Scenario: The case system stays where it is

- **GIVEN** an on-premise instance with one feature bound to a hosted provider
- **WHEN** that feature runs
- **THEN** only that feature's text MUST reach the hosted provider, and no object
  storage MUST move

#### Scenario: What leaves the building is readable in one place

- **WHEN** an administrator reads the AI feature register
- **THEN** each feature MUST show the residency of the provider it will use
