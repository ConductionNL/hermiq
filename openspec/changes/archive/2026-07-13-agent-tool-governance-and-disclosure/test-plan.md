# Test Plan: agent-tool-governance-and-disclosure

## Test Cases

### TC-1: Schema wildcard grants read verbs only (default-deny on writes)
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools` (Scenario: A schema wildcard grants read verbs only)
- **type**: functional
- **preconditions**: An agent with `Agent.tools = ["{app}.{schema}.*"]`; the derived catalog for that schema exposes search/get/create/update/delete
- **steps**: Resolve the agent's catalog (open the grant editor / run a turn)
- **expected result**: `search` + `get` are resolved/available; `create`/`update`/`delete` are NOT, and render with the "requires explicit grant" affordance
- **test command**: `/test-functional`

### TC-2: A write tool becomes available only when named explicitly
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools` (Scenario: A write tool is granted only when named explicitly)
- **type**: functional
- **preconditions**: An agent with `Agent.tools = ["{app}.{schema}.*", "{app}.{schema}.delete"]`
- **steps**: Resolve the agent's catalog
- **expected result**: `delete` is now in the resolved set alongside the wildcard's read tools; other schemas' write tools remain denied
- **test command**: `/test-functional`

### TC-3: Progressive disclosure activates above the threshold
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-progressive-tool-disclosure-for-large-catalogs` (Scenario: A resolved catalog exceeds the disclosure threshold)
- **type**: functional
- **preconditions**: An agent whose resolved catalog exceeds `tools.disclosureThreshold`
- **steps**: Assemble a turn (unit-drive the engine) and inspect the tools placed in context
- **expected result**: Only `hermiq.searchTools` (plus always-on tools) is in context; the full descriptor set is NOT
- **test command**: `/test-functional`

### TC-4: searchTools returns only in-scope tools, and they become invocable
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-progressive-tool-disclosure-for-large-catalogs` (Scenario: The model searches for and then invokes a deferred tool)
- **type**: api
- **preconditions**: Progressive disclosure active for an agent with a known resolved set
- **steps**: Call `hermiq.searchTools` with a query matching some in-scope tools, then invoke one on the next turn
- **expected result**: Only matching descriptors from the agent's resolved set are returned; a tool outside the resolved set is never returned nor invocable; a matched tool invokes on the following turn
- **test command**: `/test-api`

### TC-5: A small catalog does not trigger disclosure
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-progressive-tool-disclosure-for-large-catalogs` (Scenario: A small catalog does not trigger disclosure)
- **type**: regression
- **preconditions**: An agent whose resolved catalog is under the threshold
- **steps**: Assemble a turn
- **expected result**: All resolved descriptors are placed in context (today's path); no `hermiq.searchTools` required
- **test command**: `/test-regression`

### TC-6: Un-granted destructive invocation routes through the approval gate
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#requirement-un-granted-destructive-tool-invocation-routes-through-the-approval-gate` (Scenario: An agent attempts an un-granted destructive tool call)
- **type**: functional
- **preconditions**: An agent whose grants do NOT explicitly include a destructive-hinted tool
- **steps**: Drive a run that attempts to invoke that tool; observe the approval inbox and the invocation
- **expected result**: A pending `Approval` is created; the tool is NOT invoked until approved; a denied approval blocks it permanently
- **test command**: `/test-functional`

### TC-7: An explicitly-granted destructive tool is not re-gated
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#requirement-un-granted-destructive-tool-invocation-routes-through-the-approval-gate` (Scenario: An explicitly-granted destructive tool call is not re-gated)
- **type**: regression
- **preconditions**: An agent whose grants explicitly include a destructive tool (exact id or `:write`)
- **steps**: Drive a run that invokes that tool
- **expected result**: No new `Approval` is created solely for destructiveness; OR RBAC still authorizes at invoke time
- **test command**: `/test-regression`

### TC-8: Untrusted read-only hint cannot bypass OR RBAC
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools` (Scenario: An untrusted read-only hint cannot bypass authorization)
- **type**: security
- **preconditions**: A tool annotated `readOnlyHint:true` whose invocation OR RBAC denies for the acting user
- **steps**: Invoke the tool as that user
- **expected result**: OR RBAC denies at invoke time; the annotation does not grant access RBAC would refuse
- **test command**: `/test-security`

### TC-9: Oversight view lists invocations, tenant-scoped, with export and empty state
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art1214` (Scenario: An operator reviews an agent's tool activity / An agent has no recorded invocations)
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — art.14 oversight
- **preconditions**: Agent X with recorded invocations; Agent Y with none; a second tenant's agent Z
- **steps**: Open the oversight view for X, then Y, as an operator in X/Y's tenant; attempt to read Z
- **expected result**: X shows rows (newest first) with tool id/identity/params/result/data-touched/timestamp + retention note + CSV/JSON export; Y shows an empty state (no fabricated row); Z's data never appears
- **test command**: `/test-persona-noor`

### TC-10: Oversight degrades gracefully when the richer OR audit shape is absent
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art1214` (Scenario: The richer invocation audit shape is not yet available)
- **type**: regression
- **preconditions**: OR has not yet written richer per-invocation MCP audit entries (only `action='run'`/tool-call entries exist)
- **steps**: Open the oversight view
- **expected result**: The view degrades to coarse entries, sets `available`/`source` and shows the reduced-detail indicator; it never errors or fabricates
- **test command**: `/test-regression`

### TC-11: Grant editor write endpoint is owner/admin-gated and single-write-path
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools`
- **type**: security
- **preconditions**: A non-owner authenticated user; an agent they do not own
- **steps**: Call `PUT /api/agents/{agentId}/tool-grants`
- **expected result**: Refused; `Agent.tools` unchanged; a legitimate owner edit persists via `ObjectService` only
- **test command**: `/test-security`

### TC-12: Grant editor accessibility (WCAG AA)
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools`
- **type**: accessibility
- **preconditions**: An agent with a mixed read/write derived catalog
- **steps**: Load the grant editor + oversight view; run an accessibility scan
- **expected result**: `NcSelect` `inputLabel` present, warn affordance meets contrast, keyboard/ARIA OK; no new WCAG AA violations
- **test command**: `/test-accessibility`

## Coverage Summary

- REQ (agent-tool-governance, ADDED) "Progressive tool disclosure for large catalogs": TC-3, TC-4, TC-5
- REQ (agent-tool-governance, ADDED) "Schema-scoped whitelist grants with default-deny for write/destructive tools": TC-1, TC-2, TC-8, TC-11, TC-12
- REQ (agent-tool-governance, ADDED) "Per-agent tool-invocation oversight surface (AI Act art.12/14)": TC-9, TC-10
- REQ (human-approval-gate, ADDED) "Un-granted destructive tool invocation routes through the approval gate": TC-6, TC-7

## Out of Scope

- Deriving the catalog / writing the invocation audit entries — OpenRegister's ADR-063 changes, not
  tested here (Hermiq consumes the facade + reads AuditTrail only).
- Embedding-based `searchTools` ranking, code-execution mode, and streamable-HTTP + OAuth transport —
  deferred; not built, not tested.
- OR RBAC correctness itself — exercised only insofar as TC-8 confirms Hermiq does not bypass it.
