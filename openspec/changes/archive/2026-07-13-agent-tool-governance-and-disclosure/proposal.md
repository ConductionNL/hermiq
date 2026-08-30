# Proposal: agent-tool-governance-and-disclosure

## Summary
This is the **Hermiq consumer side of ADR-063** ("MCP as Platform Abstraction"). ADR-063 makes
OpenRegister derive a much larger MCP tool catalog than apps hand-wrote before — a coarse
`{appId}.{schema}.{search|get|create|update|delete}` template per opted-in schema, plus
annotation-declared `#[McpTool]` service tools — and serve it through the blessed
`ToolRegistryFacade`. Hermiq is the sole agent consumer of that catalog (ADR-034). A bigger
catalog breaks three things Hermiq must now own on its side of the facade:

1. **Progressive disclosure** — stuffing every derived descriptor into the LLM context is wasteful
   and degrades accuracy once a catalog grows past a few dozen tools. When an agent's effective
   catalog exceeds a threshold, Hermiq exposes a single `hermiq.searchTools` meta-tool and loads
   full descriptors only for the tools the model actually selects (Anthropic Tool Search /
   deferred-loading pattern — ~85% token reduction; Specter research 2026-07-12, brief §"design rules").
2. **Per-agent scoping UX** — the flat `{appId}.{toolName}` whitelist (`Agent.tools`, ADR-035 D4) is
   unusable for hand-curation over a coarse derived catalog. Hermiq adds **schema-level grants**
   (`{app}.{schema}.*` or read-only subsets) and **default-deny for write/destructive-hinted tools**
   unless explicitly granted.
3. **AI Act art.12/14 oversight surface** — the GOVERNED-agents wedge (deadline **2026-08-02**).
   Hermiq renders a per-agent oversight view (who invoked what, when, on which data) from OR's
   MCP invocation audit log, with a retention note and export.

Kind: **code**. Hermiq owns no database tables (thin-client, ADR-004); all new behaviour is
service/engine logic + Vue views + read endpoints over OpenRegister objects and AuditTrail.

## Motivation
The GOVERNED scheduled-agents wedge (EU AI Act art.12 record-keeping + art.14 human oversight,
sovereignty, flat self-hosted cost) is Hermiq's differentiator against every hosted rival
(Specter research 2026-07-12). ADR-063 hands Hermiq a far richer, uniformly-governed tool surface
"for free" from OpenRegister — but only if Hermiq closes the consumer-side gaps that a large,
auto-derived catalog opens: context blow-up, unmanageable whitelists, and the missing per-agent
oversight view that makes art.14 demonstrable rather than aspirational. Two of the three pieces
already have proven Hermiq seams to reuse: the `human-approval-gate` synchronous dispatch gate
(for destructive invocations) and the `run-audit-log` AuditTrail read (for oversight).

## Affected Projects
- [x] Project: `hermiq` — tool-grant resolution (`ToolLoop` + a new `ToolGrantResolver`),
  progressive disclosure (`hermiq.searchTools` meta-tool + deferred descriptor loading in the
  engine), a per-agent oversight read endpoint over OR's invocation audit log, an approval-gate
  hook for un-granted destructive invocations, and two frontend surfaces (grant editor + oversight
  view).

## Scope

### In Scope
- **Progressive disclosure**: a `hermiq.searchTools(query)` meta-tool registered through Hermiq's
  existing `IMcpToolProvider` (NC-native provider mechanism); when an agent's resolved catalog
  exceeds a configurable threshold, only the meta-tool (plus any always-on tools) is placed in
  context, and full descriptors for search hits are loaded on the following turn (deferred loading).
- **Schema-scoped whitelist grammar**: `Agent.tools` entries may be exact ids (`{app}.{tool}`),
  schema wildcards (`{app}.{schema}.*`), or explicit verb subsets (`{app}.{schema}.search`,
  `.get`). Resolution expands grants against the ADR-063 derived catalog exposed by the facade.
- **Default-deny for write/destructive tools**: a `*` schema wildcard grants **read verbs only**
  (`search`, `get`); create/update/delete (write-scoped or `destructiveHint`) tools are matched
  only when named explicitly or via an explicit write modifier. The tool-annotation hints are
  UNTRUSTED UX signals (brief §"design rules") — the authoritative gate stays OR RBAC + the
  approval gate below.
- **Approval-gate integration**: an un-granted destructive-hinted invocation attempted during a run
  routes through the existing `human-approval-gate` dispatch gate rather than executing silently.
- **Oversight surface (art.12/14)**: a per-agent read view + endpoint that lists tool invocations
  (agent identity, tool id, params summary, result summary, data touched, timestamp) sourced from
  OR's MCP invocation audit log, with a retention note and CSV/JSON export.

### Out of Scope
- **Deriving the catalog itself** — that is OpenRegister's job (ADR-063 changes
  `or-mcp-schema-dialect`, `or-mcp-derived-tool-provider`, `or-mcp-tool-attribute`). Hermiq only
  CONSUMES the derived tools through `ToolRegistryFacade` (gate-27; no cross-app RPC, no tool code).
- **Writing the MCP invocation audit entries** — the richer per-invocation audit entry is written by
  OR's `or-mcp-derived-tool-provider` at invoke time. Hermiq only READS it for the oversight view.
- **Code-execution mode** (98.7% token cut) and **streamable-HTTP + OAuth 2.1 transport** — noted
  as future direction in the brief; not built here.
- **Embedding-based tool ranking** — the first `searchTools` implementation is keyword/description
  matching; a semantic ranker is a deferred question.
- Any OR-side RBAC change — if the derived catalog's RBAC needs a new seam, it is filed as an OR
  issue, never hand-implemented cross-app (gate-27).

## Approach
Tool resolution today is one call: `ToolLoop::listAgentFunctions()` reads `Agent.tools`, expands
legacy ids, narrows by the per-request selection, and asks `ToolRegistryFacade::listTools($whitelist)`
for flattened descriptors. This change inserts a **grant-resolution step** before the facade call (a
new `ToolGrantResolver` that expands schema wildcards against the catalog and applies default-deny),
and a **disclosure-decision step** after it (if the resolved descriptor count exceeds the threshold,
substitute the `hermiq.searchTools` meta-tool for the bulk list and register the resolved set for
deferred loading). The `searchTools` meta-tool call is handled in `FacadeToolInvoker`/the engine as a
Hermiq-internal call (it never leaves Hermiq): it ranks the deferred set against the query and returns
the matching descriptors, which the engine injects on the next turn. The oversight surface is a thin
read over OR's AuditTrail (exactly as `run-audit-log` already reads `action='run'` entries), filtered
to the agent's MCP-invocation entries; no new store, single write-path preserved.

## New Dependencies
None (no new PHP/npm packages).

## Cross-Project Dependencies
**Depends conceptually on OpenRegister's ADR-063 chain — but `depends_on` stays EMPTY** (opsx does
not encode cross-repo ordering as a machine dependency; this is a prose note). Specifically:
- The **derived catalog** (coarse CRUD templates + `#[McpTool]` service tools, with per-tool
  `readOnlyHint`/`destructiveHint`/`scope` annotations) is produced by
  `openregister/or-mcp-schema-dialect` + `or-mcp-derived-tool-provider` + `or-mcp-tool-attribute`
  and read by Hermiq through the existing `ToolRegistryFacade` (unchanged ABI).
- The **MCP invocation audit log** the oversight surface renders is specified and written by
  `openregister/or-mcp-derived-tool-provider`. Until that lands, Hermiq's oversight view falls back
  to the coarser `action='run'` + tool-call entries `run-audit-log` already produces (degraded, not
  broken). This dependency is called out prominently here so the sequencing is unambiguous.

No other apps-extra project reads or writes Hermiq's grants or oversight data.

## Impact
- **Backend**: new `lib/Service/Engine/ToolGrantResolver.php` (grant expansion + default-deny), new
  `lib/Service/ToolSearchService.php` (meta-tool ranking + deferred set), changes to
  `lib/Service/Engine/ToolLoop.php` (grant + disclosure steps) and `FacadeToolInvoker.php` (meta-tool
  short-circuit), an extension to Hermiq's NC-native `IMcpToolProvider` registering `hermiq.searchTools`,
  a new `lib/Controller/ToolOversightController.php` + routes, and an approval-gate hook in the
  dispatch/engine path for un-granted destructive invocations.
- **Frontend**: a per-agent **tool grant editor** (schema-scoped grants over the derived catalog) on
  `AgentDetail.vue`, and a per-agent **oversight view** (invocation activity table + retention note +
  export) — both new Vue surfaces + an `src/api/toolOversight.js` module.
- **Specs**: new capability `agent-tool-governance`; delta on `human-approval-gate` (destructive
  invocation routing). References `nc-native-tools` (meta-tool provider) and `run-audit-log`
  (oversight source).

## Risks

### Risk 1: A large derived catalog blows the LLM context before disclosure kicks in
**Severity:** Medium — **Mitigation:** the disclosure threshold is evaluated on the RESOLVED
descriptor set (after grants + default-deny), so a tightly-scoped agent never triggers it; when it
does trigger, only the meta-tool is placed in context. The threshold is a configurable
`IAppConfig` value (default is a deferred question) so it can be tuned without a code change.

### Risk 2: `destructiveHint`/`scope` annotations are attacker/author-controllable and could be spoofed
**Severity:** Medium — **Mitigation:** the hints are treated as UNTRUSTED UX only (brief). They drive
default-deny and the approval-gate routing (fail-safe: an UNMARKED tool that is actually destructive
is caught by OR RBAC at invoke time; a tool falsely marked read-only still cannot bypass OR RBAC).
The authoritative authorization stays OR's RBAC in the provider (owns IDOR, brief §current-state).

### Risk 3: Default-deny silently hides write tools an operator expected to be available
**Severity:** Low — **Mitigation:** the grant editor renders write/destructive tools explicitly with
a distinct "requires explicit grant" affordance, so the deny is visible, never silent; and an
explicit exact-id or write-modifier grant restores them.

### Risk 4: Oversight view depends on an OR audit-log shape not yet shipped
**Severity:** Medium — **Mitigation:** documented graceful fallback to `run-audit-log`'s existing
`action='run'` + tool-call entries; the view degrades to coarser detail rather than erroring.

## Rollback Strategy
Additive. The grant-resolution step falls back to the current exact-id behaviour when no wildcard
grants are present; removing the disclosure step restores the current "list all descriptors" path;
the oversight controller and the two Vue surfaces are new and independently removable. No existing
schema, endpoint, or the `ToolRegistryFacade` ABI is modified.

## Open Questions
- What is the disclosure threshold default (descriptor count)? Provisional: `<THRESHOLD_DEFAULT>` ≈ 30
  tools, an `IAppConfig` key `tools.disclosureThreshold`; see design.md.
- Does `searchTools` rank by keyword/description match (v1) or an embedding similarity (v2)?
  Provisional: keyword/description v1; embedding deferred.
- Does default-deny apply to `update` (idempotent write) the same as `delete` (destructive), or only
  to `destructiveHint:true`? Provisional: default-deny covers all non-read verbs; the write modifier
  re-grants them together. See DEFERRED_QUESTIONS.
- Oversight retention window + export format? Provisional: retention follows OR AuditTrail's own
  policy (Hermiq stores nothing new); export = CSV + JSON.
