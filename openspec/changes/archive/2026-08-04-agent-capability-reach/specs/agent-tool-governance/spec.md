# agent-tool-governance (delta)

The grant grammar gains a `#noapproval` fragment, and default-deny stops being a question about the
data verb alone: a tool is default-denied when it is write/destructive **or** when its `reach` is
`instance` or higher. The two rules compose as a union, so no tool becomes more permissive than it is
today.

## MODIFIED Requirements

### Requirement: Schema-scoped whitelist grants with default-deny for write/destructive tools

The system MUST let a per-agent tool whitelist (`Agent.tools`) be expressed as schema-scoped grants over the derived catalog — an exact tool id, a schema wildcard (`{app}.{schema}.*`), an explicit verb subset (`{app}.{schema}.{verb}`), or a write modifier (`{app}.{schema}.*:write`) — and MUST resolve those grants against the catalog the facade returns. A schema wildcard MUST grant read verbs only; a write or destructive tool MUST be included only when named explicitly or via the write modifier (default-deny).

A grant entry MAY additionally carry argument constraints (`{toolId}?arg=value&other=in:a,b,c`) and MAY end in a `#noapproval` fragment, giving the full grammar `{toolId}[?{constraints}][#noapproval]`. The system MUST split the `#noapproval` fragment off BEFORE splitting on the constraint opener `?`, so that a fragment can never be absorbed into a constraint value or into the base tool id. The fragment MUST NOT participate in grant expansion: a grant resolves to the same catalog id with or without it.

Classification of a tool id as requiring an explicit grant MUST be the UNION of two rules. The first is the existing write/destructive classification, whose precedence is unchanged: (1) the catalog descriptor's declared `scope`/`destructiveHint`/`readOnlyHint` hint, when the descriptor sets one, wins — even over a conflicting verb suffix; (2) otherwise, a 3-segment `{app}.{schema}.{verb}` id classifies from its verb suffix (`create`/`update`/`delete`); (3) otherwise (a hint-less id that is not a 3-segment derived id — a curated or hand-written id) the system MUST classify it write/destructive (fail CLOSED) rather than treat it as read. The second rule is `reach`: a tool whose resolved reach is `instance` or higher MUST also require an explicit grant, whatever its scope. A LOW reach MUST NOT relax the first rule — a tool that is write/destructive under it stays default-denied regardless of reach.

Per-tool annotations (`readOnlyHint`/`destructiveHint`/`scope`/`reach`) MUST be treated as untrusted UX signals used only to RESTRICT — never as the authoritative authorization, which remains OpenRegister RBAC.

`Agent.tools` remains a `string[]` (ADR-035 Decision 4 froze the shape); only the MEANING of each string is extended, so no OpenRegister schema migration is required.

<!-- Previous behavior: classification was the write/destructive rule alone; the grant grammar had no
     fragment, so `#` was unused and a grant ending in `#noapproval` would have been read as part of
     the base id or of the last constraint value. -->

#### Scenario: A schema wildcard grants read verbs only

- **GIVEN** an agent whose `Agent.tools` contains `{app}.{schema}.*`
- **WHEN** the resolver expands the grant against the derived catalog
- **THEN** the resolved set MUST include that schema's read tools (`search`, `get`)
- **AND** the resolved set MUST NOT include that schema's write/destructive tools
  (`create`/`update`/`delete`)
@e2e exclude Resolver-internal expansion with no UI surface; asserted by existing unit tests on the grant resolver.

#### Scenario: A write tool is granted only when named explicitly

- **GIVEN** an agent whose `Agent.tools` contains `{app}.{schema}.*` and `{app}.{schema}.delete`
- **WHEN** the resolver expands the grants
- **THEN** the resolved set MUST include `{app}.{schema}.delete` (named explicitly)
- **AND** the resolved set MUST include the schema's read tools from the wildcard
@e2e exclude Resolver-internal expansion; asserted by existing unit tests on the grant resolver.

#### Scenario: An untrusted read-only hint cannot bypass authorization

- **GIVEN** a tool whose annotation claims `readOnlyHint:true` but whose invocation is denied by
  OpenRegister RBAC for the acting user
- **WHEN** the agent invokes that tool
- **THEN** the system MUST let OpenRegister RBAC deny the invocation at invoke time
- **AND** the annotation MUST NOT be used to grant access the RBAC layer would refuse
@e2e exclude Requires an RBAC-denied invocation driven from a model turn; asserted by unit test.

#### Scenario: A declared hint overrides a conflicting verb suffix

- **GIVEN** a 3-segment derived id whose verb suffix would classify it read (e.g. `.get`) but whose
  catalog descriptor declares `destructiveHint: true`
- **WHEN** the resolver classifies the id
- **THEN** the descriptor's `destructiveHint` MUST win — the id is classified write/destructive
@e2e exclude Classification-precedence assertion; asserted by existing unit tests on the grant resolver.

#### Scenario: A hint-less curated tool fails closed

- **GIVEN** a 2-segment curated/hand-written tool id whose catalog descriptor sets none of
  `scope`/`destructiveHint`/`readOnlyHint`
- **WHEN** the resolver classifies the id for an empty-`Agent.tools` ("all tools") default-deny
  resolution, or the id is invoked without being part of an agent's resolved set
- **THEN** the system MUST classify it write/destructive: excluded from the default-deny resolution,
  and routed through the `human-approval-gate` approval gate rather than dispatched directly
@e2e exclude Fail-closed classification of an un-annotated id; asserted by existing unit tests.

#### Scenario: An external-reach read tool is default-denied

- **GIVEN** an agent with an empty `Agent.tools` ("all discovered tools allowed")
- **AND** a catalog tool whose `scope` is `read` and whose resolved `reach` is `external`
- **WHEN** the resolver applies default-deny
- **THEN** the resolved set MUST NOT include that tool
- **AND** the resolved set MUST still include `read`-scoped tools whose reach is `self` or `user`
@e2e exclude Default-deny resolution over a synthesised catalog; asserted by unit test on the grant resolver.

#### Scenario: A low reach does not relax the write/destructive rule

- **GIVEN** a catalog tool whose `scope` is `delete` and whose resolved `reach` is `self`
- **WHEN** the resolver applies default-deny for an empty `Agent.tools`
- **THEN** the resolved set MUST NOT include that tool
- **AND** the verdict MUST be identical to the verdict this resolver produced before reach existed
@e2e exclude Non-regression assertion comparing pre- and post-change classification verdicts; asserted by unit test.

#### Scenario: A waived grant resolves to the same catalog id as an unwaived one

- **GIVEN** two agents, one granted `{toolId}` and the other granted `{toolId}#noapproval`
- **WHEN** the resolver expands each agent's grants against the same catalog
- **THEN** both resolved sets MUST contain exactly `{toolId}`
- **AND** neither resolved set MUST contain an id containing the text `noapproval`
@e2e exclude Resolver-internal expansion assertion on the fragment split order; asserted by unit test.

## ADDED Requirements

### Requirement: The tool-catalogue surface exposes reach alongside scope

The system MUST include each tool's resolved `reach` in the grant-annotated tool catalogue it returns for an agent, alongside the existing `scope` and grant annotation, so that an operator configuring grants can see how far each tool reaches without reading source. The system MUST NOT return a catalogue entry with no `reach`; where a descriptor declares none, the fail-closed `external` value MUST be returned rather than an absent or null field.

#### Scenario: Every catalogue entry carries a reach

- **GIVEN** an agent with a resolved tool catalogue containing both native and derived tools
- **WHEN** an authorized operator reads that agent's tool catalogue
- **THEN** every entry MUST carry a `reach` drawn from the closed vocabulary
- **AND** no entry MUST carry an absent or null `reach`
@e2e Playwright: seed an agent, GET its tool catalogue through the API and assert every entry carries a reach from the closed vocabulary.

#### Scenario: A tool whose descriptor declares no reach is returned as external

- **GIVEN** a catalogue entry whose descriptor declares no `reach`
- **WHEN** the catalogue is returned
- **THEN** that entry's `reach` MUST be `external`
@e2e exclude Requires a descriptor with no reach in the live catalogue, which the shipped provider does not produce; asserted by unit test on the catalogue assembler.
