# Tasks: agent-tool-governance-and-disclosure

## Implementation Tasks

### Task 1: `ToolGrantResolver` — schema-scoped grant expansion + default-deny
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools`
- **files**: `lib/Service/Engine/ToolGrantResolver.php`, `tests/Unit/Service/Engine/ToolGrantResolverTest.php`
- **acceptance_criteria**:
  - GIVEN `Agent.tools` contains `{app}.{schema}.*` WHEN the resolver expands it against the derived catalog THEN the resolved set includes that schema's `search`/`get` tools and EXCLUDES its `create`/`update`/`delete` or `destructiveHint:true` tools
  - GIVEN `Agent.tools` also contains `{app}.{schema}.delete` (or a `*:write` modifier) WHEN resolved THEN the named/write tools ARE included
  - GIVEN an exact-id grant or an empty `Agent.tools` WHEN resolved THEN existing behaviour (incl. legacy-id expansion) is preserved, with default-deny still applied to wildcard-derived write tools
- [ ] Implement
- [ ] Test

### Task 2: Wire grant resolution into `ToolLoop::listAgentFunctions()`
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools`
- **files**: `lib/Service/Engine/ToolLoop.php`, `tests/Unit/Service/Engine/ToolLoopTest.php`
- **acceptance_criteria**:
  - GIVEN an agent with wildcard grants WHEN `listAgentFunctions()` runs THEN it resolves grants via `ToolGrantResolver` BEFORE calling `ToolRegistryFacade::listTools()`, and the facade is queried with the concrete resolved id set
  - GIVEN the per-request `$selectedTools` narrowing WHEN both are present THEN intersection semantics (empty intersection = no tools) are preserved unchanged
- [ ] Implement
- [ ] Test

### Task 3: `ToolSearchService` + `hermiq.searchTools` meta-tool + disclosure decision
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-progressive-tool-disclosure-for-large-catalogs`
- **files**: `lib/Service/ToolSearchService.php`, `lib/Service/Engine/ToolLoop.php`, Hermiq's NC-native `IMcpToolProvider`, `tests/Unit/Service/ToolSearchServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a resolved catalog whose size exceeds `IAppConfig('hermiq','tools.disclosureThreshold', <THRESHOLD_DEFAULT>)` WHEN the turn is assembled THEN only `hermiq.searchTools` (plus always-on tools) is placed in context and the full resolved set is handed to `ToolSearchService::registerDeferred()`
  - GIVEN progressive disclosure is active WHEN `hermiq.searchTools(query)` is called THEN it returns only matching descriptors from the agent's resolved set and NEVER a tool outside it
  - GIVEN a resolved catalog under the threshold WHEN the turn is assembled THEN all descriptors are placed in context (today's path) and the meta-tool need not be present
- [ ] Implement
- [ ] Test

### Task 4: Approval-gate hook for un-granted destructive invocations
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/human-approval-gate/spec.md#requirement-un-granted-destructive-tool-invocation-routes-through-the-approval-gate`
- **files**: `lib/Service/Engine/FacadeToolInvoker.php`, `tests/Unit/Service/Engine/FacadeToolInvokerTest.php`
- **acceptance_criteria**:
  - GIVEN a destructive-hinted tool NOT covered by an explicit grant WHEN the run attempts to invoke it THEN a pending `Approval` is created and `ToolRegistryFacade::invokeTool()` is NOT called until it reaches `approved`; a denied `Approval` blocks it permanently
  - GIVEN an explicitly-granted destructive tool WHEN invoked THEN no new `Approval` is required and OR RBAC still authorizes at invoke time
- [ ] Implement
- [ ] Test

### Task 5: `ToolOversightController` + routes — catalog, grants, invocations
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art1214`
- **files**: `lib/Controller/ToolOversightController.php`, `appinfo/routes.php`, `lib/Settings/hermiq_register.json`, `tests/Unit/Controller/ToolOversightControllerTest.php`
- **acceptance_criteria**:
  - GIVEN an agent with recorded invocations WHEN `GET /api/agents/{agentId}/tool-invocations` is called THEN it returns tenant-scoped rows (tool id, acting identity, param/result summary, data touched, timestamp) sourced from OR's MCP invocation audit log, newest first, with a retention note; `format=csv` exports the same rows
  - GIVEN OR's richer invocation-audit shape is absent WHEN the endpoint runs THEN it degrades to coarse `action='run'`/tool-call entries and sets `available`/`source` accordingly (never fabricates)
  - GIVEN `GET /api/agents/{agentId}/tool-catalog` WHEN called THEN it returns the grant-annotated derived catalog (granted/grantedBy/requiresExplicitGrant, scope/destructiveHint); `PUT .../tool-grants` persists the `Agent.tools` array via `ObjectService` (single write-path), owner/admin-gated
  - GIVEN `hermiq_register.json` WHEN the `Agent.tools` description is read THEN it documents the grant grammar (exact id / `{app}.{schema}.*` / verb subset / `:write` modifier) — description-only change, JSON Schema shape unchanged
- [ ] Implement
- [ ] Test

### Task 6: Frontend — per-agent tool grant editor
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools`
- **files**: `src/api/toolOversight.js`, `src/components/ToolGrantEditor.vue`, `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN an operator opens an agent's grant editor WHEN the derived catalog loads THEN read tools show as grantable via schema wildcard and write/destructive tools render with a distinct "requires explicit grant" affordance (warn styling, ADR-004 `NcSelect` `inputLabel`, no hardcoded colors)
  - GIVEN the operator saves grants WHEN submitted THEN `toolOversight.js` PUTs to `/api/agents/{agentId}/tool-grants` and the agent's `Agent.tools` updates
  - GIVEN a non-owner WHEN the editor renders THEN it is read-only
- [ ] Implement
- [ ] Test

### Task 7: Frontend — per-agent oversight view (activity table + export)
- **spec_ref**: `openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art1214`
- **files**: `src/components/ToolInvocationTable.vue`, `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN an agent with invocations WHEN the oversight view loads THEN a tenant-scoped table renders (newest first) with a retention note and a CSV/JSON export button (`NcButton`)
  - GIVEN an agent with no invocations WHEN the view loads THEN an empty state renders — never a fabricated row
  - GIVEN degraded (coarse) audit data WHEN the view loads THEN the reduced-detail indicator is shown
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
- Cross-repo note honoured: no Hermiq-side tool code or catalog derivation (ADR-063; gate-27) — Hermiq consumes the facade + reads OR AuditTrail only
