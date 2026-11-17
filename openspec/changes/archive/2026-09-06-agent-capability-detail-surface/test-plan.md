# Test Plan: agent-capability-detail-surface

## Test Cases

### TC-1: Edit a config field inline from the detail view
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-create-and-configure-an-agent-mvp`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator) — builds and tweaks agent config directly
- **preconditions**: an existing agent with a known name/prompt/temperature
- **steps**: open the agent detail page, click the `name` field in the config widget, change the value, save
- **expected result**: the field persists via OpenRegister and the detail view shows the new value without opening the Edit modal
- **test command**: `/test-functional`

### TC-2: Edit the tool allowlist inline from the detail view
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-create-and-configure-an-agent-mvp`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator)
- **preconditions**: an agent with an empty or partial `tools` list; the tool registry returns at least two tools
- **steps**: open the `tools` field in the config widget, select/deselect tools from the `NcSelect`, save
- **expected result**: the updated tool id array is persisted via OpenRegister; reopening the field shows the new selection
- **test command**: `/test-functional`

### TC-3: Attach a skill from the detail page
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-skills-in-place-mvp`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator)
- **preconditions**: an active catalog skill not yet installed on the agent
- **steps**: open the Skills section, pick the skill in the attach picker
- **expected result**: `installSkill` is called, the agent reloads, and the skill appears in the installed list
- **test command**: `/test-functional`

### TC-4: Detach a skill from the detail page
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator)
- **preconditions**: an agent with at least one installed skill
- **steps**: open the Skills section, trigger the detach action on an installed skill
- **expected result**: `uninstallSkill` is called, the agent reloads, and the skill no longer appears in the installed list
- **test command**: `/test-functional`

### TC-5: Uninstall endpoint removes both sides and is idempotent
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent`
- **type**: api
- **preconditions**: a skill installed on agent X (both `installedOn` and `skillInstalls` populated)
- **steps**: `DELETE /apps/hermiq/api/skills/{id}/install/{agentX}` once, then repeat the same call
- **expected result**: first call removes agent X from `installedOn` and the skill from agent X's `skillInstalls`, returns 200; second call also returns 200 with no further change (no error)
- **test command**: `/test-api`

### TC-6: Uninstall endpoint auth posture matches install
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent`
- **type**: security
- **preconditions**: an unauthenticated request and a request for a non-existent skill id
- **steps**: call `DELETE /apps/hermiq/api/skills/{id}/install/{agentId}` unauthenticated, then authenticated with a bogus skill id
- **expected result**: unauthenticated returns 401; unknown skill id returns 404; no internal error detail is leaked (mirrors `install`)
- **test command**: `/test-security`

### TC-7: Add a memory fact from the detail page
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-memory-in-place-mvp`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator)
- **preconditions**: an agent with an existing `Memory` object under budget
- **steps**: open the Memory section on the detail page, submit a new fact via the add-fact input
- **expected result**: the fact persists via `addMemory` and appears in the entry list without a full page navigation
- **test command**: `/test-functional`

### TC-8: Memory panel behaves identically from both routes
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-memory-in-place-mvp`
- **type**: regression
- **preconditions**: an agent with memory entries
- **steps**: view the standalone `AgentMemory.vue` route and the detail-page Memory section for the same agent
- **expected result**: both surfaces show the same char-budget bar, entry list, add-fact input, and Consolidate action, backed by the same `AgentMemoryPanel` component
- **test command**: `/test-regression`

### TC-9: Config widget hides tenancy/noise fields
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-create-and-configure-an-agent-mvp`
- **type**: functional
- **preconditions**: an agent with populated `invitedUsers`, `groups`, `quotas`, `actingUser`, `configuration`, `contextRefs`, `skillInstalls`
- **steps**: open the agent detail page and inspect the rendered config widget fields
- **expected result**: none of the excluded fields render in the widget; the Skills section (not the widget) is the only place `skillInstalls` is reflected
- **test command**: `/test-functional`

### TC-10: Config widget and new sections meet WCAG 2.1 AA
- **spec_ref**: `openspec/specs/agent-management-ui/spec.md` (Standards: WCAG 2.1 AA, unchanged by this delta)
- **type**: accessibility
- **preconditions**: agent detail page rendered with the new widget, Skills section, and Memory section
- **steps**: run an accessibility audit against the agent detail page, focusing on the new `NcSelect` instances (tools field, attach picker) for `inputLabel`/keyboard operability
- **expected result**: no new WCAG 2.1 AA violations; all `NcSelect` instances carry `inputLabel`
- **test command**: `/test-accessibility`

## Coverage Summary

- **agent-management-ui — Create and configure an agent [MVP] (MODIFIED)**: covered by TC-1, TC-2, TC-9, TC-10
- **agent-management-ui — Agent detail manages skills in place [MVP] (ADDED)**: covered by TC-3, TC-4
- **agent-management-ui — Agent detail manages memory in place [MVP] (ADDED)**: covered by TC-7, TC-8
- **skills-catalog — Detach an installed skill from an agent (ADDED)**: covered by TC-4, TC-5, TC-6

## Out of Scope

- Run-loop consumption of `skillInstalls`/`tools` during an actual agent turn — unchanged by
  this change (existing `ToolLoop`/future skill-injection behaviour), so no new test case is
  added for turn-time behaviour.
- Cross-tenant RBAC on agent/skill/memory reads — unchanged, already covered by the
  originating `agent-memory`/`skills-catalog`/`multi-tenant-ops` test coverage.
