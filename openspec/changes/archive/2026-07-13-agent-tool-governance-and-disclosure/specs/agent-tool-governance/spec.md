# agent-tool-governance (delta)

Introduces the Hermiq consumer-side governance layer over OpenRegister's ADR-063 derived MCP tool
catalog: progressive tool disclosure for large catalogs, schema-scoped per-agent whitelist grants
with default-deny for write/destructive tools, and a per-agent tool-invocation oversight surface for
EU AI Act art.12/14. Hermiq reads the derived catalog through the existing `ToolRegistryFacade`
(unchanged ABI) and ships no tool code of its own (ADR-063).

## ADDED Requirements

### Requirement: Progressive tool disclosure for large catalogs
When an agent's resolved tool catalog exceeds a configurable threshold, the system MUST NOT place
every tool descriptor into the model context; it MUST instead expose a single `hermiq.searchTools`
meta-tool and load full descriptors only for the tools the model selects via that meta-tool (deferred
loading). Below the threshold, all resolved descriptors MAY be placed in context as today.

#### Scenario: A resolved catalog exceeds the disclosure threshold
- **GIVEN** an agent whose resolved (grant-filtered) tool catalog contains more tools than the
  configured disclosure threshold
- **WHEN** the engine assembles the agent's turn
- **THEN** the system MUST place only the `hermiq.searchTools` meta-tool (plus any always-on tools)
  into the model context
- **AND** the system MUST NOT place the full set of tool descriptors into the context

#### Scenario: The model searches for and then invokes a deferred tool
- **GIVEN** progressive disclosure is active for an agent turn
- **WHEN** the model calls `hermiq.searchTools` with a query
- **THEN** the system MUST return only descriptors from that agent's already-resolved
  (grant-filtered, default-denied) set that match the query
- **AND** the system MUST make the matched tools invocable on a subsequent turn
- **AND** the system MUST NOT return, or make invocable, any tool outside the agent's resolved set

#### Scenario: A small catalog does not trigger disclosure
- **GIVEN** an agent whose resolved catalog does not exceed the threshold
- **WHEN** the engine assembles the turn
- **THEN** the system MAY place all resolved descriptors directly into context
- **AND** the `hermiq.searchTools` meta-tool need not be present

### Requirement: Schema-scoped whitelist grants with default-deny for write/destructive tools
The system MUST let a per-agent tool whitelist (`Agent.tools`) be expressed as schema-scoped grants
over the derived catalog — an exact tool id, a schema wildcard (`{app}.{schema}.*`), or an explicit
verb subset (`{app}.{schema}.{verb}`) — and MUST resolve those grants against the catalog the facade
returns. A schema wildcard MUST grant read verbs only; a write or destructive-hinted tool MUST be
included only when named explicitly or via an explicit write modifier (default-deny). Per-tool
annotations (`readOnlyHint`/`destructiveHint`/`scope`) MUST be treated as untrusted UX signals used
only to RESTRICT — never as the authoritative authorization, which remains OpenRegister RBAC.

#### Scenario: A schema wildcard grants read verbs only
- **GIVEN** an agent whose `Agent.tools` contains `{app}.{schema}.*`
- **WHEN** the resolver expands the grant against the derived catalog
- **THEN** the resolved set MUST include that schema's read tools (`search`, `get`)
- **AND** the resolved set MUST NOT include that schema's write/destructive tools
  (`create`/`update`/`delete` or any `destructiveHint:true` tool)

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

### Requirement: Per-agent tool-invocation oversight surface (AI Act art.12/14)
The system MUST provide, per agent and tenant-scoped, an oversight view of that agent's tool
invocations — tool id, acting identity, parameter summary, result summary, data touched, and
timestamp — sourced from OpenRegister's MCP invocation audit log, with a retention note and an
export. The system MUST NOT fabricate rows when no invocations have been recorded.

#### Scenario: An operator reviews an agent's tool activity
- **GIVEN** an agent that has invoked several tools across past runs
- **WHEN** an authorized operator opens the agent's oversight view
- **THEN** the system MUST list the recorded invocations (newest first) with tool id, acting
  identity, parameter summary, result summary, data touched, and timestamp, scoped to the operator's
  tenant
- **AND** the system MUST offer an export of those rows

#### Scenario: The richer invocation audit shape is not yet available
- **GIVEN** OpenRegister has not yet written the richer per-invocation MCP audit entries
- **WHEN** the oversight view loads
- **THEN** the system MUST degrade to the coarser `run`/tool-call audit entries already available
- **AND** the system MUST indicate the reduced detail rather than erroring or fabricating rows

#### Scenario: An agent has no recorded invocations
- **GIVEN** an agent that has never invoked a tool
- **WHEN** the oversight view loads
- **THEN** the system MUST render an empty state
- **AND** the system MUST NOT display any fabricated invocation row
