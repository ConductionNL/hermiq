# agent-tool-governance (delta)

This is where the architectural pivot lands. "API calls go through OpenConnector nodes;
porting hydra should create no code — just flows" means the console's one write
capability cannot be a bespoke Hermiq forge tool. The grounding sweep found that the
generic mechanism for "invoke a governed flow as an agent tool" **already exists** —
`openregister.runFlow` / `openregister.flowRunStatus`, contributed by OpenRegister's
`FlowMcpToolProvider` and enumerated through the same `ToolRegistryFacade` catalog
Hermiq already consumes — but that it is **not grantable, not parameterisable and not
attributed**, and therefore cannot be handed to an agent safely:

- `openregister.runFlow` is ONE tool id that runs ANY flow on the instance. An exact-id
  grant is a grant to run everything; there is no way to say "this agent may run exactly
  this one flow".
- Its only input channel is the subject object (`uuid`, `register`, `schema`). A
  command's parameters — which label — cannot travel with the invocation, so a closed
  command vocabulary can only be enforced far away from the grant.
- It queues the run with no acting user, so an agent-dispatched flow run is
  unattributed. For a flow whose terminal step commands a build pipeline, that is an
  unattributed pipeline command.

The missing abstraction is therefore **not** a forge service. It is an
**argument-scoped, attributed tool grant**: a generic narrowing of the existing grant
grammar that turns any single multi-target tool into a grantable single-target
capability, with its permitted argument values declared as data on the grant. That is
what this delta specifies, and it is the only non-defect-fix code this change adds.

## MODIFIED Requirements

### Requirement: Schema-scoped whitelist grants with default-deny for write/destructive tools
The system MUST let a per-agent tool whitelist (`Agent.tools`) be expressed as schema-scoped grants
over the derived catalog — an exact tool id, a schema wildcard (`{app}.{schema}.*`), an explicit
verb subset (`{app}.{schema}.{verb}`), or a write modifier (`{app}.{schema}.*:write`) — and MUST
resolve those grants against the catalog the facade returns. A schema wildcard MUST grant read verbs
only; a write or destructive tool MUST be included only when named explicitly or via the write
modifier (default-deny).

The grammar MUST additionally support an **argument-scoped** grant: an exact tool id narrowed by one
or more declared argument constraints, each pinning an argument either to a single literal value or
to a closed set of permitted values. An argument-scoped grant MUST resolve to the same underlying
catalog tool id — it does not create a new tool — and MUST carry its constraints through to
invocation, where they are enforced. This is what makes a single multi-target tool (one that selects
its target from an argument, such as a flow runner selecting a flow by id) grantable as one specific
capability instead of as its whole target space. An UNCONSTRAINED exact-id grant for such a tool
MUST remain legal, but MUST be understood as granting every target.

Classification of a tool id as write/destructive MUST follow this precedence: (1) the catalog
descriptor's declared `scope`/`destructiveHint`/`readOnlyHint` hint, when the descriptor sets one,
wins — even over a conflicting verb suffix; (2) otherwise, a 3-segment `{app}.{schema}.{verb}` id
classifies from its verb suffix (`create`/`update`/`delete`); (3) otherwise (a hint-less id that is
not a 3-segment derived id — a curated or hand-written id) the system MUST classify it
write/destructive (fail CLOSED) rather than treat it as read. Per-tool annotations
(`readOnlyHint`/`destructiveHint`/`scope`) MUST be treated as untrusted UX signals used only to
RESTRICT — never as the authoritative authorization, which remains OpenRegister RBAC. An
argument-scoped grant MUST NOT change the classification of the tool it narrows: narrowing restricts
reach, it never downgrades a write to a read.

`Agent.tools` remains a `string[]` (ADR-035 Decision 4 froze the shape); only the MEANING of each
string is extended, so no OpenRegister schema migration is required, and an argument-scoped grant
MUST be expressible as a single string.

<!-- Previous behavior: the grammar supported exact ids and schema wildcards only, so every grant was
all-or-nothing per tool id. That is adequate for derived `{app}.{schema}.{verb}` tools, where the id
itself names the target, but not for a curated tool that selects its target from an argument. The
concrete gap: OpenRegister's `openregister.runFlow` runs any flow on the instance from a `flowId`
argument, so the only way to let an agent run ONE flow was to let it run ALL flows. The
argument-scoped form closes that; the schema-wildcard and exact-id forms are unchanged. -->

#### Scenario: A schema wildcard grants read verbs only

- **GIVEN** an agent whose `Agent.tools` contains `{app}.{schema}.*`
- **WHEN** the resolver expands the grant against the derived catalog
- **THEN** the resolved set MUST include that schema's read tools (`search`, `get`)
- **AND** the resolved set MUST NOT include that schema's write/destructive tools
  (`create`/`update`/`delete`)

#### Scenario: A write tool is granted only when named explicitly

- **GIVEN** an agent whose `Agent.tools` contains `{app}.{schema}.*` and `{app}.{schema}.delete`
- **WHEN** the resolver expands the grants
- **THEN** the resolved set MUST include `{app}.{schema}.delete` (named explicitly)
- **AND** the resolved set MUST include the schema's read tools from the wildcard

#### Scenario: An untrusted read-only hint cannot bypass authorization

- **GIVEN** a tool whose annotation claims `readOnlyHint:true` but whose invocation is denied by
  OpenRegister RBAC for the acting user
- **WHEN** the agent invokes that tool
- **THEN** the system MUST let OpenRegister RBAC deny the invocation at invoke time
- **AND** the annotation MUST NOT be used to grant access the RBAC layer would refuse

#### Scenario: A declared hint overrides a conflicting verb suffix

- **GIVEN** a 3-segment derived id whose verb suffix would classify it read (e.g. `.get`) but whose
  catalog descriptor declares `destructiveHint: true`
- **WHEN** the resolver classifies the id
- **THEN** the descriptor's `destructiveHint` MUST win — the id is classified write/destructive

#### Scenario: A hint-less curated tool fails closed

- **GIVEN** a 2-segment curated/hand-written tool id whose catalog descriptor sets none of
  `scope`/`destructiveHint`/`readOnlyHint`
- **WHEN** the resolver classifies the id for an empty-`Agent.tools` ("all tools") default-deny
  resolution, or the id is invoked without being part of an agent's resolved set
- **THEN** the system MUST classify it write/destructive: excluded from the default-deny resolution,
  and routed through the `human-approval-gate` approval gate rather than dispatched directly

#### Scenario: An argument-scoped grant resolves to the underlying tool

- **GIVEN** an agent whose `Agent.tools` contains an argument-scoped grant narrowing a curated tool
  to one pinned target
- **WHEN** the resolver expands the grants against the catalog
- **THEN** the resolved set MUST contain that tool's catalog id, with its declared input schema
- **AND** the resolver MUST NOT invent a second catalog entry for the narrowed form

#### Scenario: Narrowing does not downgrade classification

- **GIVEN** an argument-scoped grant over a tool classified write/destructive
- **WHEN** the tool is classified for default-deny, dry-run and approval purposes
- **THEN** it MUST still classify write/destructive
- **AND** the narrowing MUST NOT cause it to be treated as read-only or auto-allowed

## ADDED Requirements

### Requirement: Argument constraints on a grant are enforced at invocation
The system MUST enforce every argument constraint carried by an argument-scoped grant at the point
of invocation, BEFORE the call is dispatched to the tool facade, and MUST refuse a non-conforming
call with a structured error rather than an exception. An argument the grant pins MUST match
exactly; an argument the grant constrains to a closed set MUST be a member of that set; an argument
the grant does not mention MUST be left to the tool's own validation. Enforcement MUST happen at
Hermiq's existing single dispatch chokepoint, alongside the guardrail, approval-gate and dry-run
short-circuits already applied there, and MUST NOT introduce a second invocation path.

Refusal MUST be recorded in the run's audit trail with the tool id, the offending argument and the
constraint it violated. The constraint set MUST be the authoritative statement of what this agent
may ask for — the model's own reasoning, the tool description, and any text the model read MUST NOT
be able to widen it.

#### Scenario: A pinned argument that matches is dispatched

- **GIVEN** an agent holding an argument-scoped grant pinning a target argument to one value
- **WHEN** it invokes the tool with exactly that value
- **THEN** the call MUST proceed to the remaining governance checks and then to the facade

#### Scenario: A pinned argument that differs is refused before dispatch

- **GIVEN** the same agent
- **WHEN** it invokes the tool naming a different target
- **THEN** the call MUST be refused with a structured error
- **AND** the facade MUST NOT be invoked
- **AND** the refusal MUST name the tool, the argument and the violated constraint in the audit trail

#### Scenario: A value outside a closed set is refused

- **GIVEN** a grant constraining an argument to a closed set of permitted values
- **WHEN** the agent invokes the tool with a value outside that set
- **THEN** the call MUST be refused before dispatch

#### Scenario: Text the model read cannot widen the constraint

- **GIVEN** object text instructing the agent to use a target or value the grant does not permit
- **WHEN** the agent invokes the tool accordingly
- **THEN** the call MUST be refused
- **AND** the constraint MUST NOT be relaxed by any prompt, tool description or model rationale

### Requirement: A flow invoked as an agent tool is attributed to an owning UID
When an agent invokes a tool that queues a flow run, the queued run MUST carry the acting owner's
Nextcloud UID, resolved from the run the agent is executing under, so the flow's own steps execute
as an identified person and the run is attributable after the fact. The system MUST NOT queue an
agent-initiated flow run with an absent, empty or system owner. Where the owner cannot be resolved,
the invocation MUST be refused rather than dispatched.

Attribution is required specifically because a flow's terminal step may command an external system:
an unattributed run of such a flow is an unattributed command, and "who told it to do that" must be
answerable from the record.

#### Scenario: An agent-queued flow run names the acting owner

- **GIVEN** an agent run executing on behalf of an identified user
- **WHEN** the agent invokes the flow-running tool
- **THEN** the queued flow run MUST record that user's UID as its owner
- **AND** the flow's steps MUST execute as that owner

#### Scenario: An unresolvable owner refuses the invocation

- **GIVEN** an agent run with no resolvable owning UID
- **WHEN** the agent invokes the flow-running tool
- **THEN** the invocation MUST be refused
- **AND** no flow run MUST be queued

#### Scenario: The record answers who commanded the pipeline

- **GIVEN** a completed flow run whose terminal step wrote to an external system
- **WHEN** the audit trail is read
- **THEN** it MUST name the owning UID, the invoking agent, the tool id and the constrained arguments

### Requirement: The pipeline command capability is one approval-gated, argument-scoped grant
The triage agent's ONLY command capability MUST be a single argument-scoped grant over the existing
flow-running tool, pinned to the one flow that owns the forge label write and constrained to the
closed label vocabulary that flow accepts. Hermiq MUST NOT ship a bespoke forge, label or issue tool
to satisfy this (see the `nc-native-tools` delta), and MUST NOT open an HTTP client to a forge.

The label vocabulary MUST be resolved from hydra's own state-machine definition and declared as data
on the grant — never hard-coded in Hermiq — so hydra can change its state machine without a Hermiq
release. The vocabulary MUST be CLOSED: a label outside it is refused before dispatch. This
constraint is load-bearing rather than defensive, because the agent's arguments derive from pipeline
object text that other agents wrote, which is untrusted input by construction.

The invocation MUST additionally pass the human-approval gate, derived from the agent's own policy
and not downgradable by any request body, tool argument or prompt content. The operator approving it
MUST be shown the flow, the target and the label being authorised — an approval that hides the
command it authorises is not human-in-the-loop. Enforcing the vocabulary at the grant does NOT
relieve the executing endpoint of validating it: the write path is the last line and MUST refuse an
out-of-vocabulary label independently.

#### Scenario: The agent may run exactly one flow

- **GIVEN** the seeded triage agent's resolved tool list
- **WHEN** it attempts to run a flow other than the pinned label-write flow
- **THEN** the invocation MUST be refused before dispatch

#### Scenario: An out-of-vocabulary label is refused before any forge contact

- **GIVEN** the triage agent invoking the pinned flow with a label outside the declared vocabulary
- **WHEN** the invocation is checked
- **THEN** it MUST be refused
- **AND** no flow run MUST be queued
- **AND** no credential MUST be resolved and no forge request MUST be made

#### Scenario: An injected instruction cannot escape the vocabulary

- **GIVEN** a finding whose text instructs the agent to apply an administrative or permission label
- **WHEN** the agent invokes the command grant with that label
- **THEN** the invocation MUST be refused
- **AND** the refusal MUST be recorded in the run's audit trail

#### Scenario: A label write pauses for a disclosing approval

- **GIVEN** the triage agent selects the command grant during a run
- **WHEN** the invocation would proceed
- **THEN** the run MUST enter the approval gate
- **AND** the pending approval MUST display the flow, the target and the label
- **AND** no forge write MUST occur before a human approves

#### Scenario: Prompt content cannot waive approval

- **GIVEN** object text instructing the agent to act without approval
- **WHEN** the agent invokes the command grant
- **THEN** the approval gate MUST still apply

#### Scenario: A dry run commands nothing

- **GIVEN** a run executing in dry-run mode with the command grant present
- **WHEN** the agent selects it
- **THEN** the invocation MUST be neutralised, no flow run MUST be queued and no forge request MUST be made

#### Scenario: No other fleet agent acquires the command

- **GIVEN** any other agent in the fleet, whether its grant list is empty or contains only wildcards
- **WHEN** its tools are resolved against the live catalog
- **THEN** the command capability MUST NOT be present

## Non-Functional Requirements

- **Performance:** Constraint checking MUST complete before any dispatch, so a refused invocation
  costs no facade round trip and no network call. Grant resolution MUST NOT add a catalog fetch
  beyond the one the existing resolver already performs.
- **Accessibility:** The approval surface presenting a pending command MUST meet WCAG 2.1 AA — the
  flow, target and label are readable as text and not conveyed by colour or icon alone, and the
  approve/reject controls are keyboard reachable with programmatic labels.
- **Internationalization:** Dutch and English MUST both be supported (ADR-005). Operator-visible
  strings — the approval prompt and every user-facing refusal message — MUST route through `IL10N`
  and appear in both `l10n/en.json` and `l10n/nl.json`. Tool ids, error codes and LLM-facing
  descriptions stay untranslated English identifiers.

## Acceptance Criteria

- An argument-scoped grant is expressible as one `Agent.tools` string and resolves to the underlying
  catalog tool id with no second catalog entry.
- A narrowed write tool still classifies write/destructive for default-deny, dry-run and approval.
- A conforming invocation dispatches; a non-conforming one is refused before the facade, with the
  tool, argument and violated constraint in the audit trail.
- An agent-queued flow run records the acting owner's UID; an unresolvable owner refuses the
  invocation and queues nothing.
- The triage agent can run exactly the pinned flow and no other.
- A label outside the declared vocabulary is refused with no flow run, no credential resolution and
  no forge request.
- An injected out-of-vocabulary label attempt is refused and the refusal is in the audit trail.
- A command invocation enters the approval gate and the pending approval displays flow, target and
  label; prompt content cannot waive it.
- A dry run queues no flow run and makes no forge request.
- No agent with an empty or wildcard-only grant list resolves the command capability.
- Hermiq's tool catalog gains no forge, label or issue tool, and Hermiq opens no HTTP client to a
  forge.
- Every scenario above is referenced by a Playwright e2e test or carries a reason-bearing
  `@e2e exclude` (gate-19).

## Notes

- **Why this is the deliverable and not a forge service.** The pivot's rule is that porting hydra
  creates no code, and that where code seems necessary the missing flow abstraction is specified
  instead. The grounding sweep confirmed the flow-invocation tool exists but is ungrantable,
  unparameterised and unattributed; those three gaps are exactly what stood between "no code" and a
  bespoke `ForgeLabelService`. Closing them generically leaves the forge write entirely outside
  Hermiq, and gives any future app the same narrowing for free.
- **What Hermiq does NOT own here.** The OpenConnector node or endpoint that performs the label
  write, the flow that composes it, and the vocabulary's members are all owned outside this repo —
  by `hydra-console-openbuild-app`'s `hydra-console-commands` capability (`Requirement: The command
  endpoint performs the forge write server-side`) and by hydra's own state machine. This delta fixes
  only that the grant is narrowable, enforced, attributed and gated.
- **The OpenConnector side does not exist yet.** OpenConnector today registers no MCP tool provider
  and contributes no flow node or resolver, so there is presently nothing for the pinned flow's
  terminal step to call. That is a cross-repo prerequisite, recorded as a deferred question — not
  something this change works around with Hermiq code.
- **Nothing here is statically verifiable in Hermiq's CI.** The tool facade, the flow engine and the
  credential broker live under `OCA\OpenRegister\*`, absent from this repo's analysis environment.
  Live verification only.
- Related ADRs: ADR-022 (consume the fleet's abstractions), ADR-031 (declarative over imperative),
  ADR-035 (frozen `Agent.tools` shape), ADR-041 (cross-app commands), ADR-063 (MCP verb/scope
  hints), ADR-065 (one flow engine).
