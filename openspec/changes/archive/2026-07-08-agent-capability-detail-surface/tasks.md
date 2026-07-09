# Tasks: agent-capability-detail-surface

## Implementation Tasks

### Task 1: Backend — SkillService::uninstallFromAgent
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent`
- **files**: `lib/Service/SkillService.php`
- **acceptance_criteria**:
  - GIVEN a skill installed on agent X WHEN `uninstallFromAgent(skillId, agentId=X)` is called THEN agent X's uuid is removed from the skill's `installedOn` via `objectService->saveObject`
  - GIVEN the same call THEN the skill's uuid is removed from agent X's `skillInstalls` via a new private `desyncAgentSkillInstalls` (mirrors `syncAgentSkillInstalls`)
  - GIVEN a skill/agent pair that is not associated WHEN `uninstallFromAgent` is called THEN it returns successfully with no data change (idempotent)
- [x] Implement
- [x] Test

### Task 2: Backend — SkillController::uninstall + route
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent`
- **files**: `lib/Controller/SkillController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated user WHEN `DELETE /api/skills/{id}/install/{agentId}` is called THEN the response mirrors `install`'s shape/status codes (200 success, 400 missing agentId is not applicable here since agentId is a route param, 404 skill not found, 401 unauthenticated, 500 on unexpected failure)
  - GIVEN the route entry in `appinfo/routes.php` THEN it uses `requirements: {id: '[^/]+', agentId: '[^/]+'}` and verb `DELETE`
  - GIVEN the controller method THEN its auth attributes (`@NoAdminRequired`, `@NoCSRFRequired`) match `install` exactly
- [x] Implement
- [x] Test

### Task 3: Frontend — src/api/skills.js uninstallSkill helper
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent`
- **files**: `src/api/skills.js`
- **acceptance_criteria**:
  - GIVEN `uninstallSkill(skillId, agentUuid)` is called THEN it issues `DELETE /apps/hermiq/api/skills/{skillId}/install/{agentUuid}` and returns the updated skill payload
- [x] Implement
- [x] Test

### Task 4: Frontend — AgentDetail config block becomes CnObjectDataWidget
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-create-and-configure-an-agent-mvp`
- **files**: `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN `AgentDetail.vue` mounts WHEN `load()` runs THEN `agentStore.fetchSchema('agent')` is called and the resolved schema is passed to `<CnObjectDataWidget :schema :object-data="agent" object-type="agent" :store="agentStore">`
  - GIVEN the widget's `exclude`/`overrides` config THEN tenancy/noise fields (invitedUsers, groups, views, isPrivate, requestQuota, tokenQuota, actingUser, configuration, contextRefs, skillInstalls) are hidden
  - GIVEN the user edits and saves a config field THEN the widget's `@saved` handler reloads the agent
  - GIVEN the header "Edit agent" button THEN it still opens the existing config-only `AgentFormModal` unchanged
- [x] Implement
- [x] Test

### Task 5: Frontend — dynamic tools field slot
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-create-and-configure-an-agent-mvp`
- **files**: `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN the widget renders the `tools` field WHEN using the `#field-tools` slot THEN an `NcSelect` (multiple, `close-on-select=false`, `inputLabel` set) is shown with options from `listTools()` mapped to `{label, value}`
  - GIVEN the user changes the selection and confirms THEN the slot's `update(arrayOfIds)` is called with the new tool id array and `cancel` is available to discard
- [x] Implement
- [x] Test

### Task 6: Frontend — Skills section (attach/detach) on AgentDetail
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-skills-in-place-mvp`
- **files**: `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN the Skills section THEN it lists installed skills resolved from `agent.skillInstalls` against `listSkills()` labels
  - GIVEN an attach picker (`NcSelect`, catalog skills not yet installed) WHEN the user selects one THEN `installSkill(skillId, agentUuid)` is called and the agent is reloaded
  - GIVEN an installed skill's detach action WHEN triggered THEN `uninstallSkill(skillId, agentUuid)` is called and the agent is reloaded
- [x] Implement
- [x] Test

### Task 7: Extract AgentMemoryPanel.vue and consume from both routes
- **spec_ref**: `openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-memory-in-place-mvp`
- **files**: `src/components/AgentMemoryPanel.vue`, `src/views/AgentMemory.vue`, `src/views/AgentDetail.vue`
- **acceptance_criteria**:
  - GIVEN `AgentMemoryPanel.vue` (props: `agentId`) THEN it contains the char-budget bar, entry list, add-fact input, and Consolidate action, using `getMemory`/`addMemory`/`consolidateMemory` from `src/api/memory.js`
  - GIVEN `AgentMemory.vue` THEN it renders `<AgentMemoryPanel :agent-id="..."/>` instead of duplicating the markup
  - GIVEN the new Memory section on `AgentDetail.vue` THEN it renders the same `<AgentMemoryPanel>` with the current agent's id
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/Service/SkillServiceTest.php`, controller test if one exists), mirroring the existing `installOnAgent` coverage (both-sides removal + idempotency)
- New/changed API endpoint (`DELETE /api/skills/{id}/install/{agentId}`) covered by Newman/Postman tests
- UI changes (config widget, tools slot, Skills section, Memory section) covered by Playwright browser tests, driven through real clicks not the API
- All tests pass (`composer test`, `newman run`)
- No feature-documentation update required beyond the existing agent-detail docs page — screenshot refresh only if the page layout materially changed (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (attach/detach labels, Memory section labels) (ADR-007)
- `openspec validate` passes
