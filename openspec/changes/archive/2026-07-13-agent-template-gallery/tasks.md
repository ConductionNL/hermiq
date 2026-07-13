# Tasks: agent-template-gallery

## Implementation Tasks

### Task 1: Add the `AgentTemplate` schema
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data`
- **files**: `lib/Settings/hermiq_register.json`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the register is re-imported WHEN OpenRegister validates it THEN an `AgentTemplate` schema (slug `agenttemplate`) exists with `name`, `description`, `category`, `systemPrompt`, `suggestedProvider`, `suggestedModel`, `tools[]`, `skillRefs[]` (`{skillId, name}`), `suggestedSchedule` (`{kind, cronExpr, intervalMinutes, deliver}`), `state` (enum `active|quarantined|archived`), `source` (enum `local|org|hub`), `quarantineReason`, `scanReport`, `version`, `createdBy`
  - GIVEN the schema declares no field named `secret`/`apiKey`/`invitedUsers`/`groups`/`requestQuota`/`tokenQuota`/`views`/`actingUser` THEN a structural review confirms no such field exists
  - GIVEN the schema change WHEN `info.xml`'s `<version>` is compared to the prior `0.1.52` THEN it MUST be bumped by one patch so OpenRegister re-imports on next boot
- [x] Implement
- [x] Test (`npm run check:register`/`check:json-strict` PASS; `info.xml` bumped 0.1.54→0.1.55, register `info.version` bumped 0.10.4→0.10.5 — HEAD was actually at 0.1.54/0.10.4, not the `0.1.52` in the spec text above, which had drifted)

### Task 2: `AgentTemplateSerializer` — JSON package (de)serialisation
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-exporting-an-agent-to-a-template-strips-tenant-specific-fields`
- **files**: `lib/Service/AgentTemplateSerializer.php`
- **acceptance_criteria**:
  - GIVEN a template payload WHEN `toPackage()` is called THEN a JSON string containing only the `AgentTemplate` schema's declared fields is returned
  - GIVEN a package string WHEN `fromPackage()` is called THEN the declared fields are parsed back with tolerant defaults for missing optional fields (mirrors `SkillSerializer::fromPackage()`)
- [x] Implement
- [x] Test (`tests/Unit/Service/AgentTemplateSerializerTest.php` — toPackage strips lifecycle fields, round-trip, tolerant defaults, malformed-JSON tolerance)

### Task 3: `AgentTemplateService` — export / import / quarantine / approve
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned`
- **files**: `lib/Service/AgentTemplateService.php`
- **acceptance_criteria**:
  - GIVEN an existing `Agent` UUID WHEN `exportFromAgent($agentId)` is called THEN a package containing only `AgentTemplate`-declared fields is returned (no `invitedUsers`/`groups`/quotas/`actingUser`)
  - GIVEN a package imported with `source='org'` or `source='hub'` WHEN `importPackage()` runs THEN OR's `ContentScanService` scans the `systemPrompt`, the template is saved `state='quarantined'` with a `quarantineReason`, and a `source='local'` import is saved `state='active'` with no scan
  - GIVEN a `quarantined` template WHEN `approveQuarantined($id, force)` is called with a `dangerous` scan verdict and `force=false` THEN the template stays `quarantined`; with `force=true` THEN it transitions to `active` and `quarantineReason` is cleared
- [x] Implement (also added `create()` for direct authoring and `exportTemplate()` — template's own portable fields as a package — the read-only counterpart to `importPackage()` that the gallery's "Export" button needed; not in the original task list but low-risk/reuses the serializer only)
- [x] Test (`tests/Unit/Service/AgentTemplateServiceTest.php` — export strips tenant fields, import quarantines for org/hub and skips for local, create() skips quarantine, approve activates/blocks-until-forced)

### Task 4: `AgentTemplateService::instantiate()` — model coercion, skill-ref resolution, schedule hint
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-silently-violates-the-callers-model-policy`
- **files**: `lib/Service/AgentTemplateService.php`
- **acceptance_criteria**:
  - GIVEN a template's `suggestedProvider`/`suggestedModel` is outside the caller's organisation's effective `ModelPolicy` (via `TenantModelPolicyService::effectivePolicyFor()`) WHEN `instantiate()` runs THEN the created `Agent` uses the policy's default/first-allowed provider instead, and the response reports `modelCoerced: true` with both the requested and resolved provider/model
  - GIVEN a template's `skillRefs` include a `skillId` not visible/active in the caller's organisation WHEN `instantiate()` runs THEN the `Agent` is still created, the resolvable refs are installed via `SkillService::installOnAgent()`, and unresolved refs appear in `unresolvedSkillRefs`
  - GIVEN a template has a `suggestedSchedule` WHEN `instantiate()` runs THEN no `Schedule` object is created and the hint is returned verbatim in the response
- [x] Implement (uses `TenantModelPolicyService::effectivePolicyFor()` + the PUBLIC `isAllowed()` — `matchesAllowed()` cited in design.md is `private`, so the equivalent public seam is used instead, same effect)
- [x] Test (`tests/Unit/Service/AgentTemplateServiceTest.php` — coerces out-of-policy model + reports it, honours in-policy model, resolves skill refs best-effort, never saves a `schedule` schema object)

### Task 5: `AgentTemplateController` + routes + ADR-023 action seed
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-approving-a-quarantined-template-requires-action-authorization`
- **files**: `lib/Controller/AgentTemplateController.php`, `appinfo/routes.php`, `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN `@NoAdminRequired` routes for index/show/create/update/delete/export/import/instantiate WHEN any authenticated user calls them THEN OR's native RBAC scopes reads/writes to the caller's organisation
  - GIVEN `POST /api/agent-templates/{id}/approve` WHEN the caller lacks `agenttemplate.approve-quarantined` THEN `403`; WHEN `force=true` is passed and the caller lacks `agenttemplate.override-scan-verdict` (in addition to `approve-quarantined`) THEN `403` even with a valid approve grant
  - GIVEN `lib/actions.seed.json` WHEN inspected THEN `agenttemplate.approve-quarantined` and `agenttemplate.override-scan-verdict` both default to `["admin"]`
- [x] Implement (also added `exportPackage()` — GET `/api/agent-templates/{id}/export` — for the template-itself export the gallery's "Export" button needs)
- [x] Test (`tests/Unit/Controller/AgentTemplateControllerTest.php` — 401s, approve() two-tier action-auth gate incl. force path, instantiate() organisation resolution, import validation)

### Task 6: `SeedAgentTemplates` repair step
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-a-fresh-install-ships-seeded-starter-templates`
- **files**: `lib/Repair/SeedAgentTemplates.php`, `lib/AppInfo/Application.php` (repair-step registration, same point as `SeedModelPolicies`)
- **acceptance_criteria**:
  - GIVEN no `AgentTemplate` objects exist WHEN the repair step runs THEN it creates 5 starter templates (Morning briefing, Inbox triage, Website/monitor watcher, Weekly report, Meeting-notes summariser), each `state='active'`, `source='local'`
  - GIVEN a seeded template already exists (matched by its seeded name) and an admin has edited it WHEN the repair step runs again THEN the edit is preserved and no duplicate is created
- [x] Implement (registered in `appinfo/info.xml`'s `<install>` and `<post-migration>` repair-step lists, same point as `SeedModelPolicies`)
- [x] Test (`tests/Unit/Repair/SeedAgentTemplatesTest.php` — fresh install seeds all 5, re-run skips the existing name, graceful no-op when OpenRegister unavailable)

### Task 7: `src/api/agentTemplates.js` + `AgentTemplateGallery.vue`
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-silently-violates-the-callers-model-policy`
- **files**: `src/api/agentTemplates.js`, `src/views/AgentTemplateGallery.vue`, `src/registry.js`, `src/customComponents.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the gallery page opens WHEN it loads THEN templates render through `CnDataTable` with name/category/description/state, and a `quarantined` badge mirrors `SkillsCatalog.vue`'s state styling
  - GIVEN a user clicks "Use this template" on an `active` template WHEN `instantiate()` succeeds THEN a note-card surfaces any `modelCoerced`/`unresolvedSkillRefs` from the response before navigating to the new agent
  - GIVEN every `NcSelect` on the page THEN it carries an `inputLabel` (WCAG 2.1 AA, ADR-004)
- [x] Implement (page uses no `NcSelect` at all — nothing to label; the sibling `TemplateImportModal.vue` likewise has none)
- [x] Test (compile-verified: `npm run lint` 0 errors, `npm run check:specs` PASS incl. `check:registry`; live coverage deferred to the playwright-regression-coverage change)

### Task 8: `TemplateImportModal.vue` + `AgentCatalog.vue` entry point
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned`
- **files**: `src/modals/TemplateImportModal.vue`, `src/views/AgentCatalog.vue`
- **acceptance_criteria**:
  - GIVEN a user pastes a package and picks `source='org'` WHEN they submit THEN the imported template appears in the gallery `quarantined`, matching `SkillImportModal.vue`'s pattern
  - GIVEN `AgentCatalog.vue` WHEN it renders THEN a "Browse templates" button sits next to "Create agent", navigating to the gallery without removing the existing blank-form path
- [x] Implement
- [x] Test (compile-verified: `npm run lint` 0 errors, `npm run check:specs` PASS; live coverage deferred to the playwright-regression-coverage change)

### Task 9: i18n strings
- **spec_ref**: `openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-a-fresh-install-ships-seeded-starter-templates`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN every new user-facing string introduced in Tasks 7-8 WHEN `l10n/en.json` and `l10n/nl.json` are inspected THEN both contain matching English-keyed entries
- [x] Implement (also backfilled 3 pre-existing gaps encountered while adding these — generic "Export"/"Import"/"State"/"Exported package" keys used by `SkillsCatalog.vue` were never added to l10n; fixed per the project's "always fix pre-existing issues" rule)
- [x] Test (both files remain valid JSON per `npm run check:json-strict`; keys verified present in both `en.json`/`nl.json`)

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
