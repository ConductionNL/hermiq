# Agent Tool Governance Specification

**Status**: active (backend shipped + unit-verified; frontend source-only, bundle deferred)
**Standards**: EU AI Act (Reg. 2024/1689) Art. 12 (record-keeping) & Art. 14 (human oversight)
**Feature tier**: V1

**OpenSpec changes:**
- `openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/` — the Hermiq consumer side of ADR-063: schema-scoped grants with default-deny, progressive tool disclosure via `hermiq.searchTools`, and the per-agent art.12/14 oversight surface (kind: code) — **DONE**

## Purpose

Hermiq is the sole agent consumer (ADR-034) of the MCP tool catalog OpenRegister derives under
ADR-063 — a coarse `{appId}.{schema}.{search|get|create|update|delete}` template per opted-in
schema, served through the blessed `ToolRegistryFacade`. A catalog that large breaks three things
on Hermiq's side of the facade, and this capability owns all three:

1. **Progressive disclosure** — a resolved catalog above a configurable threshold is not stuffed
   into the model context; a single `hermiq.searchTools` meta-tool is exposed instead, and full
   descriptors load only for the tools the model selects.
2. **Schema-scoped per-agent grants** — the flat `{appId}.{toolName}` whitelist is unusable for
   hand-curation over a coarse derived catalog, so `Agent.tools` gains a wildcard/verb-subset
   grammar with **default-deny** on write/destructive tools.
3. **The art.12/14 oversight surface** — a per-agent view of who invoked what, when, on which
   data, read from OpenRegister's MCP invocation AuditTrail.

Hermiq CONSUMES the derived catalog; it never derives it and ships no tool code of its own
(ADR-063, gate-27). The authoritative authorization boundary stays OpenRegister RBAC at invoke
time — everything here is a governance/UX layer that only ever NARROWS what an agent can reach.

## Requirements

### Requirement: Progressive tool disclosure for large catalogs
The system MUST NOT place every tool descriptor into the model context when an agent's resolved tool
catalog exceeds a configurable threshold (`IAppConfig('hermiq', 'tools.disclosureThreshold')`,
default **30**); it MUST instead expose a single `hermiq.searchTools` meta-tool and load full
descriptors only for the tools the model selects via that meta-tool (deferred loading). Below the
threshold, all resolved descriptors MAY be placed in context as today.

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
over the derived catalog — an exact tool id, a schema wildcard (`{app}.{schema}.*`), an explicit
verb subset (`{app}.{schema}.{verb}`), or a write modifier (`{app}.{schema}.*:write`) — and MUST
resolve those grants against the catalog the facade returns. A schema wildcard MUST grant read verbs
only; a write or destructive tool MUST be included only when named explicitly or via the write
modifier (default-deny). Per-tool annotations (`readOnlyHint`/`destructiveHint`/`scope`) MUST be
treated as untrusted UX signals used only to RESTRICT — never as the authoritative authorization,
which remains OpenRegister RBAC.

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
export (CSV + JSON). The system MUST NOT fabricate rows when no invocations have been recorded.

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

## User Stories

- As a municipal CISO, I want to see exactly which tools an agent invoked, when, and on which data,
  so that I can demonstrate EU AI Act Art. 14 human oversight rather than merely assert it.
- As an operator, I want to grant an agent a whole schema's read access in one entry, so that I do
  not hand-curate dozens of derived tool ids.
- As an operator, I want a wildcard to NEVER silently hand an agent `delete`, so that the safe
  choice is the zero-config one.
- As an agent builder, I want a large tool catalog not to blow my model's context, so that accuracy
  and cost stay sane as the fleet grows.
- As a security reviewer, I want tool annotations treated as untrusted, so that a spoofed
  `readOnlyHint` cannot escalate past OpenRegister RBAC.

## Acceptance Criteria

- [x] `Agent.tools` accepts exact ids, `{app}.{schema}.*`, `{app}.{schema}.{verb}`, and
      `{app}.{schema}.*:write`, resolved against the facade's catalog (`ToolGrantResolver`)
- [x] A schema wildcard resolves to read verbs only; write/destructive derived tools require an
      explicit grant (default-deny)
- [x] An empty `Agent.tools` preserves "all discovered tools allowed" for non-derived ids while
      still stripping classifiable derived write ids
- [x] Above `tools.disclosureThreshold` (default 30) only `hermiq.searchTools` enters the context;
      the full resolved set is held off-context for deferred loading (`ToolSearchService`)
- [x] `hermiq.searchTools` never returns a tool outside the agent's resolved set, and is handled
      Hermiq-internally (no facade round-trip)
- [x] An un-granted write/destructive invocation routes through the `human-approval-gate` state
      machine instead of executing (see that spec's delta)
- [x] `GET /api/agents/{agentId}/tool-catalog` returns the grant-annotated catalog; `PUT
      .../tool-grants` persists `Agent.tools` via `ObjectService` (single write-path), owner-only
- [x] `GET /api/agents/{agentId}/tool-invocations` returns tenant-scoped rows (newest first) with a
      retention note and CSV/JSON export, degrading gracefully and never fabricating
- [ ] Frontend grant editor + oversight view live-verified in a browser (source shipped; bundle
      deferred — see Notes)

## Notes

- **ADR-063 consumer.** The derived catalog, its per-tool annotations, and the MCP invocation audit
  entry shape are all produced by OpenRegister (`or-mcp-schema-dialect`,
  `or-mcp-derived-tool-provider`, `or-mcp-tool-attribute`). Hermiq reads them through the unchanged
  `ToolRegistryFacade` ABI and OR's `AuditTrailMapper`. Adding a new app/schema to the fleet must
  expose new tools to a Hermiq agent with **zero Hermiq code change** — only a schema opt-in
  upstream plus a grant edit on the agent.
- **Known upstream gap (write/destructive classification).** OpenRegister's
  `McpProviderBridge::getFunctions()` — the adapter every provider's tools flow through before
  `ToolRegistryFacade::listTools()` returns them — does **not** forward the
  `destructiveHint`/`scope`/`readOnlyHint` annotation keys into the descriptor (verified against
  HEAD 2026-07-13); only `name`/`mcpId`/`description`/`parameters` survive. Until that is closed
  upstream, `ToolGrantResolver` classifies write/destructive from the VERB SUFFIX of a 3-segment
  derived id (`{app}.{schema}.{create|update|delete}`) — the only signal available. A 2-segment or
  bare (hand-written / legacy) id is never classified this way, so pre-existing whitelist behaviour
  is preserved exactly. **File as an OpenRegister issue; never hand-patch cross-app (gate-27).**
- **Known upstream limitation (agent principal).** OR's `createToolInvocationEntry()` records the
  ambient Nextcloud **session user**, not an agent principal — the `IMcpToolProvider` ABI does not
  thread an acting-agent identity into `invokeTool()`. The oversight surface therefore CORRELATES
  invocations to an agent via that agent's owner plus its schedules' owners. A first-class
  agent-identity column upstream would make this exact rather than correlated.
- **Frontend bundle deferred.** `src/api/toolOversight.js`, `src/components/ToolGrantEditor.vue`
  and `src/components/ToolInvocationTable.vue` ship as source and are wired into `AgentDetail.vue`;
  they are syntax-checked but not webpack-built or browser-verified in this change.
- Related: **ADR-063** (MCP as platform abstraction), **ADR-034** (Hermiq is the agent consumer),
  **ADR-035 D4** (`Agent.tools` whitelist), **ADR-004** (governance via OR AuditTrail),
  `human-approval-gate` (the destructive-invocation gate), `nc-native-tools` (the provider the
  meta-tool registers through), `run-audit-log` (the degraded oversight fallback).
