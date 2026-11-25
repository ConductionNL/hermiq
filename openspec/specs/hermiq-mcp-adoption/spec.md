# hermiq-mcp-adoption Specification

## Purpose
TBD - created by archiving change hermiq-mcp-adoption. Update Purpose after archive.
## Requirements
### Requirement: Declarative read-only tool surface on a curated schema subset
The system MUST derive Hermiq's register tools from `x-openregister-mcp` declarations on exactly three schemas — `Agent`, `Schedule` and `Session` — and MUST NOT hand-write any `IMcpToolProvider` CRUD tool over Hermiq's registers.

Each declaration MUST enable the `search` and `get` verbs only, MUST carry agent-facing `description`
prose per verb, and MUST declare `scope: 'read'` with `readOnlyHint: true`. Every `search.filters`
entry MUST name a real property of that schema.

#### Scenario: The derived catalog exposes reads for the three curated schemas
- GIVEN `hermiq_register.json` declares `x-openregister-mcp` on `Agent`, `Schedule` and `Session`
- WHEN `SchemaDerivedToolProvider` builds the catalog for app id `hermiq`
- THEN it MUST emit `hermiq.agent.search`, `hermiq.agent.get`, `hermiq.schedule.search`, `hermiq.schedule.get`, `hermiq.session.search` and `hermiq.session.get`
- AND every emitted descriptor MUST carry `readOnlyHint: true` and `scope: 'read'`

#### Scenario: Declared search filters are real schema properties
- GIVEN the `Schedule` declaration lists `filters: [enabled, agentId, lastStatus, kind]`
- WHEN OpenRegister's `McpAnnotationValidator` validates the schema at import
- THEN validation MUST pass because each filter names a declared property of `Schedule`
- AND a filter naming a property the schema does not declare MUST be rejected at import

### Requirement: No write verb on any Hermiq schema
The system MUST NOT declare the `create`, `update` or `delete` verb in the `x-openregister-mcp` block of any schema in `hermiq_register.json`.

An agent able to write Hermiq's own registers can escalate its own privileges in a single tool call:
`Agent.tools` and `Agent.prompt` grant capability and rewrite instructions; `Schedule.requiresApproval`
disables the agent's own approval gate and `Schedule.prompt` establishes unattended persistence;
`Memory.entries` is a persistent-injection sink. Refusing at declaration time means the tool is never
emitted, which is a stronger guarantee than one enforced at invoke time.

#### Scenario: No write tool is derivable for Hermiq
- GIVEN every `x-openregister-mcp` block in `hermiq_register.json`
- WHEN the derived catalog for app id `hermiq` is built
- THEN it MUST contain no tool id ending in `.create`, `.update` or `.delete`

#### Scenario: An agent cannot grant itself a tool
- GIVEN an agent whose grants list resolves to Hermiq's derived read tools
- WHEN the agent attempts to modify its own `Agent` object's `tools` array via an MCP tool
- THEN no such tool MUST exist in the catalog
- AND the attempt MUST fail as an unknown tool rather than being gated at invoke time

