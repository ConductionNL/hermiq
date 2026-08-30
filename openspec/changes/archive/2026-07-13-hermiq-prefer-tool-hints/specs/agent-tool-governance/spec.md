# agent-tool-governance (delta)

Refines the write/destructive classification `ToolGrantResolver` uses for default-deny (empty
`Agent.tools`) and the un-granted-invocation approval-gate check: OpenRegister now forwards the
ADR-063 `scope`/`destructiveHint`/`readOnlyHint` annotation hints onto every catalog descriptor
(previously dropped before reaching Hermiq), so the classifier prefers them over the verb-suffix
heuristic — and a hint-less id that the verb-suffix rule also cannot classify now fails CLOSED
(treated as write/destructive) instead of silently passing as read.

## MODIFIED Requirements

### Requirement: Schema-scoped whitelist grants with default-deny for write/destructive tools
The system MUST let a per-agent tool whitelist (`Agent.tools`) be expressed as schema-scoped grants
over the derived catalog — an exact tool id, a schema wildcard (`{app}.{schema}.*`), an explicit verb
subset (`{app}.{schema}.{verb}`), or a write modifier (`{app}.{schema}.*:write`) — and MUST resolve
those grants against the catalog the facade returns. A schema wildcard MUST grant read verbs only; a
write or destructive tool MUST be included only when named explicitly or via the write modifier
(default-deny).

Classification of a tool id as write/destructive MUST follow this precedence: (1) the catalog
descriptor's declared `scope`/`destructiveHint`/`readOnlyHint` hint, when the descriptor sets one,
wins; (2) otherwise, a 3-segment `{app}.{schema}.{verb}` id classifies from its verb suffix
(`create`/`update`/`delete`); (3) otherwise (a hint-less id that is not a 3-segment derived id) the
system MUST classify it write/destructive (fail closed) rather than treat it as read.

Per-tool annotations (`readOnlyHint`/`destructiveHint`/`scope`) MUST be treated as untrusted UX
signals used only to RESTRICT — never as the authoritative authorization, which remains OpenRegister
RBAC.

`Agent.tools` remains a `string[]` (ADR-035 Decision 4 froze the shape); only the MEANING of each
string is extended, so no OpenRegister schema migration is required.

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

#### Scenario: A declared hint overrides a conflicting verb suffix

- **GIVEN** a 3-segment derived id whose verb suffix would classify it read (e.g. `.get`) but whose
  catalog descriptor declares `destructiveHint: true`
- **WHEN** the resolver classifies the id
- **THEN** the descriptor's `destructiveHint` MUST win — the id is classified write/destructive

#### Scenario: An untrusted read-only hint cannot bypass authorization

- **GIVEN** a tool whose annotation claims `readOnlyHint:true` but whose invocation is denied by
  OpenRegister RBAC for the acting user
- **WHEN** the agent invokes that tool
- **THEN** the system MUST let OpenRegister RBAC deny the invocation at invoke time
- **AND** the annotation MUST NOT be used to grant access the RBAC layer would refuse

## ADDED Requirements

### Requirement: Descriptor hints take precedence over verb-suffix classification
When a catalog descriptor declares `scope`, `destructiveHint`, or `readOnlyHint` for a tool id, the
system MUST classify that id's write/destructive status from the declared hint (in that priority
order: `scope`, then `destructiveHint`, then `readOnlyHint` — the first one the descriptor actually
sets) rather than from the id's own verb suffix, including when the two would disagree. The
verb-suffix heuristic MUST remain available, UNCHANGED, as the fallback for a 3-segment derived id
whose descriptor sets none of these keys.

#### Scenario: A curated tool is classified from its declared hint

- **GIVEN** a 2-segment curated tool id (not a 3-segment ADR-063 derived shape) whose catalog
  descriptor declares `destructiveHint: true`
- **WHEN** the resolver classifies the id (e.g. for an empty-`Agent.tools` default-deny resolution)
- **THEN** the id MUST be classified write/destructive and excluded from the resolved set
- **AND** a sibling curated id whose descriptor declares `readOnlyHint: true` MUST be classified read
  and included

#### Scenario: A hint-less derived id keeps the pre-existing verb-suffix result

- **GIVEN** a 3-segment `{app}.{schema}.{verb}` id whose descriptor sets none of `scope`/
  `destructiveHint`/`readOnlyHint` (or has no descriptor at all)
- **WHEN** the resolver classifies the id
- **THEN** the result MUST be identical to the classification before this change (regression parity)

### Requirement: An unclassifiable, hint-less id fails closed
The system MUST classify a tool id write/destructive when it carries no usable descriptor hint AND is
not a 3-segment `{app}.{schema}.{verb}` derived id — it MUST NOT be treated as read/safe by default.

#### Scenario: A hint-less curated tool is excluded from an empty-grants resolution

- **GIVEN** an agent with `Agent.tools = []` ("all tools allowed") and a catalog containing a
  hint-less 2-segment curated tool id
- **WHEN** the resolver applies default-deny
- **THEN** that id MUST be excluded from the resolved set (it now requires an explicit grant, or a
  declared read hint, to be included)

#### Scenario: A hint-less curated tool trips the approval gate when invoked un-granted

- **GIVEN** a hint-less 2-segment curated tool id that is NOT part of an agent's resolved set
- **WHEN** the agent's run attempts to invoke that tool
- **THEN** the invocation MUST route through the `human-approval-gate` approval gate exactly as any
  other un-granted write/destructive tool — the facade MUST NOT be invoked directly
