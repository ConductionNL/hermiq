# Tasks: agents-sharing-and-catalog-columns

> Archive pass 2026-10-07: code done except task 5, where the AgentDetail page still reads OpenRegister's object API (needs Ruben, design D4); open: task 5, task 6 two-user call, verification run (live check).

Kind: code. Size M. Rows `hermiq:ag-visibility`, `hermiq:ag-list`.

## Implementation tasks

### Task 1: Groups in the one predicate, and the four copies removed
- **spec_ref**: `openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-group-sharing-is-enforced-wherever-an-agent-is-read-or-run-req-agshare-002`
- **files**: `lib/Service/AgentAccessService.php`, `lib/Controller/AgentsController.php`, `lib/Controller/AgentVersionController.php`, `lib/Controller/ChatStreamController.php`, `lib/Controller/ToolOversightController.php`
- **acceptance_criteria**:
  - GIVEN a private agent shared with a group WHEN a member reads it THEN 200, and a non-member gets 404
  - GIVEN the existing tests of the four controllers WHEN they run after the move THEN they pass unchanged
- [x] Implement (at HEAD before this change; this PR makes the refusal a 404)
- [x] Test (`AgentsControllerTest::testIndexAndShowHonourTheAgentsGroups`, `AgentAccessServiceTest::testGroupMemberMayReadButNotModify`, `AgentReadRuleTest`; Playwright 200 for a member and 404 for an outsider)

### Task 2: A filtered list with owner, sharing and status
- **spec_ref**: `openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003`
- **files**: `lib/Controller/AgentsController.php` (`index()`, `serializeAgent()`)
- **acceptance_criteria**:
  - GIVEN 40 agents of which 12 are usable WHEN the list is read with limit 10 THEN pages hold 10, 2 and the total is 12
  - GIVEN an organisation admin WHEN the list is read THEN all 40 come back with `visibleBecause` on the ones they could not otherwise use
- [x] Implement
- [x] Test (`AgentCatalogTest::testAUserPageReadsThroughTheRuleWithItsOwnTotal`, `::testAnOrganisationAdminSeesEveryAgentWithTheReason`; Playwright for an admin)

### Task 3: The sharing field on the agent form
- **spec_ref**: `openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-agent-owner-decides-who-can-use-the-agent-req-agshare-001`
- **files**: `src/modals/AgentFormModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the owner WHEN they choose people and groups THEN both NcSelects have an inputLabel and the choice is saved
- [x] Implement
- [x] Test (`tests/agent-sharing.spec.js`; Playwright `agents-sharing-and-catalog-columns.spec.ts`, written, not run here)

### Task 4: The catalog columns
- **spec_ref**: `openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003`
- **files**: `src/manifest.json` (page `AgentCatalog`), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the catalog WHEN it renders THEN the columns are Name, Owner, Who can use it, Status, Model, from `/api/agents`
- [x] Implement (the columns, over OpenRegister's object API: see design, As built, D3)
- [x] Test (check:manifest 0; Playwright for the columns, written, not run here)

### Task 5: The admin view of a private agent
- **spec_ref**: `openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-sees-every-agent-of-the-organisation-req-agshare-004`
- **files**: `lib/Controller/AgentsController.php` (`show()`), the AgentDetail page
- **acceptance_criteria**:
  - GIVEN an admin on a private agent they cannot otherwise use WHEN the page loads THEN no prompt and no tools are returned by `show()`
- [ ] Implement (API done: `AgentCatalog::row()`, `AgentsControllerTest::testAnOrganisationAdminGetsTheReducedAgent`; the AgentDetail page still reads OpenRegister's object API, needs Ruben: design, As built, D4)
- [ ] Test (PHPUnit on the reduced shape done; Playwright as admin on the page waits on the decision)

### Task 6: Close or record the open object API read
- **spec_ref**: `openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-group-sharing-is-enforced-wherever-an-agent-is-read-or-run-req-agshare-002`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Controller/AgentsController.php`
- **acceptance_criteria**:
  - GIVEN a private agent WHEN a non-invited colleague reads `/apps/openregister/api/objects/hermiq/agent/{id}` THEN either 404, or the PR names the OpenRegister issue filed because object-level authorization cannot express it
- [x] Implement (the Agent read rule, hermiq#976, closes the object API; pinned by `AgentReadRuleTest`)
- [ ] Test (a live call with two users: not run here)

## Verification
- [x] `openspec validate agents-sharing-and-catalog-columns --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run, exit codes read
