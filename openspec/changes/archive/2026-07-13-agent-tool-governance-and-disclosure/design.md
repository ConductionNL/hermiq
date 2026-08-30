# Design: agent-tool-governance-and-disclosure

## Architecture Overview
Three consumer-side capabilities bolt onto Hermiq's existing tool path
(`ToolLoop` → `ToolRegistryFacade` → `FacadeToolInvoker`) without touching the facade ABI or adding
tool code (ADR-063: Hermiq consumes, never ships, MCP tools).

```
Agent.tools (grants)                     ADR-063 derived catalog (via ToolRegistryFacade)
   │  {app}.{schema}.*                        {app}.{schema}.{search|get|create|update|delete}
   │  {app}.{schema}.get                      + #[McpTool] service tools (readOnlyHint/destructiveHint/scope)
   ▼                                                    │
┌─────────────────────┐   expand + default-deny   ┌────┴──────────────────┐
│ ToolGrantResolver   │◀──────────────────────────│ facade->listTools([]) │  (full catalog, for matching)
│  read verbs from *  │                           └───────────────────────┘
│  write/destructive  │
│  only if named      │──▶ resolved id set ──▶ ToolLoop::listAgentFunctions()
└─────────────────────┘                                 │
                                    count > threshold?   │  no → descriptors into context (today's path)
                                                yes ┌────┴────┐
                                                    ▼         ▼
                                    hermiq.searchTools    ToolSearchService.register(deferredSet)
                                    (ONLY meta-tool in     (holds resolved descriptors off-context)
                                     context this turn)
                                          │ LLM calls searchTools(query)
                                          ▼
                                    ToolSearchService.rank(query) → matching descriptors
                                          │  engine injects them on the NEXT turn (deferred loading)
                                          ▼
                                    FacadeToolInvoker → (destructive + un-granted?) ──▶ human-approval-gate
                                          │ otherwise
                                          ▼
                                    ToolRegistryFacade::invokeTool()  ──▶  OR writes MCP invocation audit entry
                                                                                    │
                                                              ToolOversightController reads it (per-agent view)
```

## Component Design

### 1. `ToolGrantResolver` (new, `lib/Service/Engine/ToolGrantResolver.php`)
Pure resolution of `Agent.tools` grant entries against the derived catalog. Given the grant list and
the full catalog descriptor list (from `ToolRegistryFacade::listTools([])`), it returns the concrete
set of allowed tool ids.

Grant grammar (entries in `Agent.tools`, an array of strings — no schema shape change):
| Grant form | Expands to |
|---|---|
| `{app}.{tool}` (exact, no wildcard) | that one tool, verbatim (today's behaviour; write tools allowed by exact naming) |
| `{app}.{schema}.*` | **read verbs only** — `search`, `get` — of that schema's derived tools (default-deny) |
| `{app}.{schema}.*:write` | read verbs **and** write verbs (`create`, `update`, `delete`) of that schema |
| `{app}.{schema}.{verb}` | exactly that verb (e.g. `.search`), including a write verb when named explicitly |
| `[]` (empty `Agent.tools`) | unchanged: "all discovered tools allowed" — but still filtered by default-deny (a bare `*`-equivalent grants read verbs; write/destructive tools require an explicit agent-level grant or fall to the approval gate at invoke time) |

**Default-deny rule**: a tool is write/destructive when its ADR-063 annotation carries
`scope ∈ {create, update, delete}` OR `destructiveHint:true`. Such a tool is included in the resolved
set ONLY if a grant names it exactly or via a `:write` modifier. Annotations are UNTRUSTED UX signals;
they only ever *restrict* here — the authoritative allow/deny is OR RBAC at invoke time.

### 2. `ToolSearchService` + `hermiq.searchTools` meta-tool (new, `lib/Service/ToolSearchService.php`)
Registered as one additional tool on Hermiq's existing NC-native `IMcpToolProvider` (id
`hermiq.searchTools`, so it flows through the same registration mechanism as the six existing
`hermiq.*` tools — see `nc-native-tools`). Input: `{ "query": "string" }`. It is **Hermiq-internal**:
the invocation never leaves Hermiq (no facade round-trip), it ranks the run's deferred descriptor set
and returns matches. v1 ranking = case-insensitive substring/token match over tool `name` +
`description`; embedding similarity is deferred (Open Questions). Deferred set is held per-run (in the
engine's turn state), never persisted.

**Disclosure decision** (in `ToolLoop::listAgentFunctions()`): after grant resolution, if the resolved
descriptor count `> IAppConfig('hermiq','tools.disclosureThreshold', <THRESHOLD_DEFAULT>)`, return only
the `hermiq.searchTools` descriptor (plus any tool tagged always-on) and hand the full resolved set to
`ToolSearchService::registerDeferred()`. Otherwise, unchanged: return all descriptors.

### 3. Approval-gate hook for un-granted destructive invocations
`FacadeToolInvoker::__call()` (or a thin guard the engine calls just before it) checks: is the tool
write/destructive AND not covered by an exact/`:write` grant for this agent? If so it does NOT call
`invokeTool()` — it routes through the existing `human-approval-gate` dispatch gate (creates a pending
`Approval` object; the run blocks exactly as a schedule-level gated action does). This reuses the
`human-approval-gate` state machine rather than inventing a second block path.

### 4. Oversight surface (`ToolOversightController` + Vue view)
`GET /api/agents/{agentId}/tool-invocations` reads OR's AuditTrail for that agent's MCP invocation
entries (written by OR's `or-mcp-derived-tool-provider`), tenant-scoped exactly like
`run-audit-log`'s history read. Each row: tool id, agent identity, param summary, result summary,
data touched (object/schema refs), timestamp. `available:false` degraded fallback to coarse
`action='run'` + tool-call entries when the richer OR entry shape is not yet present (Risk 4). Export
= CSV + JSON of the same rows.

## API Design

### `GET /api/agents/{agentId}/tool-catalog`
Returns the resolved, grant-filtered catalog for the grant editor: every derived tool the agent
COULD grant, annotated with `scope`/`destructiveHint` and whether it is currently granted and by which
grant entry. `@NoAdminRequired`, tenant-scoped.
```json
{ "agentId": "agent-uuid", "disclosureThreshold": 30, "resolvedCount": 41, "disclosureActive": true,
  "tools": [ { "id": "pipelinq.lead.search", "scope": "read", "destructiveHint": false,
               "granted": true, "grantedBy": "pipelinq.lead.*" },
             { "id": "pipelinq.lead.delete", "scope": "delete", "destructiveHint": true,
               "granted": false, "requiresExplicitGrant": true } ] }
```

### `PUT /api/agents/{agentId}/tool-grants`
Persists the `Agent.tools` grant array (the ONLY write; it edits the existing `Agent` object via
`ObjectService`, single write-path). Admin/owner-gated like other Agent edits.
```json
{ "grants": [ "pipelinq.lead.*", "pipelinq.lead.delete", "openregister.schemas.get" ] }
```

### `GET /api/agents/{agentId}/tool-invocations?from=&to=&format=json|csv`
Per-agent oversight rows (see §4). `@NoAdminRequired`, tenant-scoped.
```json
{ "agentId": "agent-uuid", "available": true, "source": "or-mcp-invocation-audit",
  "retention": "inherited from OpenRegister AuditTrail policy",
  "rows": [ { "at": "2026-07-12T09:14:03Z", "toolId": "pipelinq.lead.create",
              "actingUser": "alice", "paramsSummary": "name=…, org=…",
              "resultSummary": "created lead 7f3…", "dataTouched": ["pipelinq.lead:7f3…"] } ] }
```

## Database Changes
**None.** No Nextcloud DB migration, no new OpenRegister schema. `Agent.tools` already exists as a
`string[]` (see `lib/Settings/hermiq_register.json`); this change only extends the **meaning** of the
strings (adds wildcard/verb-subset grammar) — the JSON Schema is unchanged. The one edit to
`hermiq_register.json` is a **description update** on the `tools` property documenting the grant
grammar. All invocation-audit data lives in OR's AuditTrail (read-only from Hermiq).

## Declarative vs Imperative
ADR-031/ADR-063 push tool exposure to be **declarative** (schemas declare `x-openregister-mcp`; OR
derives the catalog). Hermiq's consumer side is unavoidably **imperative** in two spots, and this
section fixes the boundary so no declarative payload silently drifts into Hermiq code:

- **Declarative (owned upstream, Hermiq only reads):** the catalog itself, per-tool
  `scope`/`readOnlyHint`/`destructiveHint` annotations, and the MCP invocation audit entry shape — all
  declared by schemas + `#[McpTool]` and materialised by OpenRegister. Hermiq NEVER hard-codes a tool
  id, a verb list, or a destructive classification; it reads them from the descriptor the facade
  returns. If Hermiq needs a new annotation (e.g. a machine-readable "always-on" flag), that is an
  ADR-063 dialect addition filed against OpenRegister, not a Hermiq lookup table.
- **Declarative (owned by Hermiq, per-agent):** the grant list `Agent.tools` is data on the Agent
  object — edited through the grant editor, resolved at run start, never compiled into code.
- **Imperative (Hermiq engine logic):** (a) grant EXPANSION + default-deny (`ToolGrantResolver`) —
  the *rules* are code, the *inputs* (grants + annotations) are declarative; (b) the disclosure
  DECISION + `searchTools` ranking (`ToolSearchService`) — a runtime, per-turn engine behaviour that
  cannot be declarative because it depends on the live resolved count and the model's query.

The test of the boundary: adding a new app/schema to the fleet must expose new tools to a Hermiq agent
with **zero Hermiq code change** — only a schema opt-in upstream and a grant edit on the agent.

## Security Considerations
- **Read** endpoints (`tool-catalog`, `tool-invocations`) are `@NoAdminRequired` + tenant-scoped via
  `ObjectService`/AuditTrail read, exactly like `run-audit-log`/`AnalyticsController` — a caller never
  sees another tenant's agents or invocations.
- **Write** (`tool-grants`) edits the `Agent` object and reuses the Agent object's existing
  owner/admin authorization; no new write surface beyond the grant array.
- **Default-deny is a UX guardrail, not the security boundary.** The security boundary is OR RBAC in
  the provider (owns IDOR, brief §current-state) + the `human-approval-gate` block for un-granted
  destructive calls. A spoofed `readOnlyHint` cannot escalate: OR RBAC still authorises at invoke time.
- The `searchTools` meta-tool never returns a tool outside the agent's resolved (already
  grant-filtered, default-denied) set — disclosure narrows what the model sees, it never widens it.
- No secrets, no direct third-party HTTP (remote calls stay in OpenConnector per `nc-native-tools`),
  no cross-app RPC (gate-27 — the facade is the only inbound surface).

## NL Design System
The grant editor and oversight view reuse existing `AgentDetail.vue` card/table patterns (CSS
variables, no hardcoded colors), `NcSelect` with `inputLabel` (ADR-004) for verb-subset pickers, and
the existing warn styling for the "requires explicit grant" affordance on write/destructive tools.
Export uses a standard `NcButton`. WCAG AA; Dutch + English strings (ADR-007).

## File Structure
```
lib/
  Service/
    Engine/
      ToolLoop.php               (+ grant-resolution step, + disclosure-decision step)
      ToolGrantResolver.php       (new — grant expansion + default-deny)
      FacadeToolInvoker.php       (+ un-granted-destructive → approval-gate short-circuit)
    ToolSearchService.php         (new — searchTools ranking + per-run deferred set)
    <existing Nc-native provider>  (+ register hermiq.searchTools meta-tool)
  Controller/
    ToolOversightController.php    (new — tool-catalog, tool-grants, tool-invocations)
  Settings/
    hermiq_register.json           (Agent.tools DESCRIPTION update only — grant grammar docs)
appinfo/
  routes.php                       (+ tool-catalog/grants/invocations routes)
src/
  api/
    toolOversight.js               (new)
  views/
    AgentDetail.vue                (+ tool grant editor card, + oversight view/link)
  components/
    ToolGrantEditor.vue            (new)
    ToolInvocationTable.vue        (new)
```

## Seed Data
No new OR object type is introduced, so there is nothing new to seed as objects. Seeding here means
giving the **seeded example agents** realistic grant lists + one that trips progressive disclosure, so
both surfaces render on a fresh install without hand-setup.

### Agent grant seeds (edits to existing seeded `Agent` objects' `tools` array)
| Seeded agent | `Agent.tools` grants | Illustrates |
|---|---|---|
| `agent-daily-briefing` | `["openregister.schemas.get", "pipelinq.lead.search"]` | tight read-only scope, disclosure OFF |
| `agent-crm-assistant` | `["pipelinq.lead.*", "pipelinq.contactmoment.*", "pipelinq.lead.create"]` | schema wildcards (read) + one explicit write grant; default-deny visible on `.delete` |
| `agent-fleet-power` | `["*.*.*:write"]` (broad) | resolved count `> <THRESHOLD_DEFAULT>` → progressive disclosure ACTIVE, `hermiq.searchTools` in context |

**Related items per agent:** none new (grants are a property on the existing Agent object; no
file/note/task relations).

### Oversight rows
Not seeded directly — invocation rows appear naturally once the seeded agents run (they read from OR's
live AuditTrail). On a brand-new install with no runs yet, the oversight view renders its empty state
("no invocations recorded yet"), never a fabricated row.

## Trade-offs
- **Extend the string grammar vs. a structured grant object**: keeping `Agent.tools` a `string[]` and
  overloading it with `*`/`:write` grammar avoids an OR schema migration and keeps the existing
  whitelist/legacy-id path working, at the cost of a small parser. A structured `{schema, verbs}[]`
  would be tidier but is a breaking schema change for a field ADR-035 D4 already froze — rejected.
- **Meta-tool as a Hermiq NC-native tool vs. an engine-only construct**: registering `searchTools`
  through the existing `IMcpToolProvider` means the model calls it with the exact same mechanism as any
  other tool (no special LLM prompt path), at the cost of one provider entry. Rejected the alternative
  (a bespoke non-tool "search" prompt affordance) because it would fork the tool-call path.
- **Default-deny on `*` vs. grant-everything-then-warn**: default-deny makes the safe choice the
  zero-config one (a wildcard can't silently hand an agent `delete`), matching the AI Act art.14
  posture, at the cost of operators needing an explicit grant for writes — surfaced explicitly in the
  editor so the deny is never a mystery.

## Open Questions
(carried from proposal.md; the load-bearing ones are lifted into DEFERRED_QUESTIONS in the final
report)
- `<THRESHOLD_DEFAULT>` disclosure threshold value.
- `searchTools` ranking: keyword (v1) vs embedding (v2).
- Default-deny granularity: all non-read verbs, or only `destructiveHint:true`.
- Oversight retention/export specifics (currently inherited from OR AuditTrail policy + CSV/JSON).
