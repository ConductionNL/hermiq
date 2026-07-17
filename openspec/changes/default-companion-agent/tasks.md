# Tasks: default-companion-agent

## Implementation Tasks

### Task 1: Persist the per-user default agent and expose set/clear endpoints
- **spec_ref**: `openspec/changes/default-companion-agent/specs/default-companion-agent/spec.md#requirement-a-user-can-set-and-clear-a-default-companion-agent`
- **files**: `lib/Controller/`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a user sets a default WHEN `PUT /api/user/default-agent` is called with an accessible `agentId` THEN the UUID is stored via NC user config (app `hermiq`) and echoed back
  - GIVEN `agentId` is a user-supplied object id WHEN the endpoint runs THEN it MUST `canUserAccessAgent()` BEFORE storing and return `403` otherwise (IDOR guard) — storing nothing
  - GIVEN a user clears their default WHEN `DELETE /api/user/default-agent` is called THEN the preference is removed and `204` returned
  - Both routes declare `#[NoAdminRequired]` and are registered in `appinfo/routes.php`; CSRF stays ON (no `#[NoCSRFRequired]` — both are state-changing)
  - No OpenRegister schema, object, or migration is introduced — the preference is a single scalar in NC user config
- [ ] Implement
- [ ] Test

### Task 2: Resolve the companion agent by three-tier precedence
- **spec_ref**: `openspec/changes/default-companion-agent/specs/default-companion-agent/spec.md#requirement-the-companion-agent-resolves-by-per-user-then-app-config-then-first-accessible-precedence`
- **files**: `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN line 224 calls `pickFallbackAgentForUser()` directly WHEN the change lands THEN it calls a new resolver: per-user → app-config `companion_agent_uuid` → first-accessible
  - GIVEN `pickFallbackAgentForUser()` (line 558) is the last tier WHEN the change lands THEN its signature, `Throwable` catch, warning log, `''` return and `findAll(config: ['limit' => 20])` cap are UNCHANGED
  - GIVEN a per-user default is set WHEN a chat starts THEN the instance-wide default is not consulted and the first-accessible scan does not run
  - GIVEN no default is set anywhere WHEN a chat starts THEN behaviour is identical to today (no regression)
  - Resolution adds at most one agent lookup to the chat path when an earlier tier answers
- [ ] Implement
- [ ] Test

### Task 3: Enforce the preference-is-not-authorization contract
- **spec_ref**: `openspec/changes/default-companion-agent/specs/default-companion-agent/spec.md#requirement-a-stored-agent-uuid-is-a-preference-never-an-authorization`
- **files**: `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN a stored UUID names an agent the user cannot access WHEN resolution runs THEN it falls through to the next tier and raises NO error — chat still works
  - GIVEN a user's access to their stored default is revoked after storing it WHEN resolution runs THEN that agent MUST NOT be returned (the read-time check is the load-bearing one)
  - GIVEN a stored default names a deleted agent WHEN resolution runs THEN it falls through; correctness does not depend on cleaning up the stale key
  - GIVEN the instance-wide default names an agent this user cannot access WHEN resolution runs THEN it falls through to first-accessible
  - `canUserAccessAgent()` runs on EVERY read at EVERY tier — presence of a stored UUID is never evidence of access
- [ ] Implement
- [ ] Test

### Task 4: Tolerate the absent app-config tier (hermiq#116 not merged)
- **spec_ref**: `openspec/changes/default-companion-agent/specs/default-companion-agent/spec.md#requirement-the-app-config-precedence-tier-is-optional`
- **files**: `lib/Controller/ChatStreamController.php`
- **acceptance_criteria**:
  - GIVEN `grep -rn "companion_agent_uuid" lib/ src/` returns NOTHING at HEAD (hermiq#116 is open, not merged) WHEN the resolver reads the key THEN an absent or empty value means "this tier has no answer" — fall through, no error, no warning log
  - GIVEN this change may merge before or after hermiq#116 WHEN either order occurs THEN both work and neither PR blocks the other
  - This task MUST NOT implement the `companion_agent_uuid` app-config key or its admin UI — that is hermiq#116's job
- [ ] Implement
- [ ] Test

### Task 5: Add the default-agent picker to personal settings
- **spec_ref**: `openspec/changes/default-companion-agent/specs/inapp-settings-section/spec.md#requirement-the-default-agent-picker-is-placed-above-the-talk-delivery-section`
- **files**: `src/App.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the `#user-settings` slot renders `#about` → `#talk-delivery` → `#setup` (admin-only) → `#credentials` WHEN the change lands THEN a new `NcAppSettingsSection` sits between `#about` and `#talk-delivery`
  - GIVEN `#setup` is admin-only WHEN a non-admin opens the dialog THEN the default-agent picker is visible and usable
  - GIVEN the picker lists agents WHEN it renders THEN it lists ONLY agents the calling user can access, and allows clearing
  - GIVEN `NcSelect` is used WHEN it renders THEN it carries an `inputLabel` — never a manual `<label>` (WCAG 2.1 AA, SC 1.3.1/4.1.2)
  - Strings added to `l10n/en.json` and `l10n/nl.json`, keyed by the ENGLISH source string
- [ ] Implement
- [ ] Test

### Task 6: Declare the "Make my default" headerAction on AgentDetail
- **spec_ref**: `openspec/changes/default-companion-agent/specs/agent-management-ui/spec.md#requirement-the-agent-detail-page-offers-a-make-my-default-action`
- **files**: `src/manifest.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN `AgentDetail` is `type: detail` with three `config.headerActions` (`edit-agent`, `version-history`, `view-factsheet`) WHEN the change lands THEN a fourth `type: "api-call"` entry PUTs `@objectId` to the default-agent endpoint
  - GIVEN it is a free-form record action WHEN it is declared THEN it goes in `config.headerActions`, NEVER `lifecycleActions`; no new Vue component is added
  - GIVEN a manifest key the renderer does not read fails SILENTLY WHEN writing the entry THEN the exact `api-call` field names are confirmed against the installed `CnDetailPage` first
  - GIVEN the renderer's body-widget branch silently drops `config.headerActions` WHEN verifying THEN the action is confirmed rendering AND functioning in a live browser — grepping the bundle is theatre
- [ ] Implement
- [ ] Test

### Task 7: Share the AI hexagon as the default agent avatar
- **spec_ref**: `openspec/changes/default-companion-agent/specs/agent-management-ui/spec.md#requirement-agents-without-an-icon-render-the-ai-hexagon-avatar`
- **files**: `src/`, `../nextcloud-vue/src/components/`
- **acceptance_criteria**:
  - GIVEN the hexagon lives in `CnAiFloatingButton.vue` as CSS local to a `position: fixed !important`, 52×60px button WHEN reused as an avatar THEN a shared, inline, size-configurable component is extracted in `nextcloud-vue` (branch from `beta`) and consumed here — its geometry is NOT duplicated into hermiq
  - GIVEN the brand rule (pointy-top, point-up, never rotated, never flat-top; six equal sides only at √3:2 — clip-path `polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%)`, Cobalt fill) WHEN rendered at any size THEN the ratio and orientation hold, and brand tokens/CSS variables are used rather than re-hardcoded hex values
  - GIVEN an agent with an empty `icon` WHEN rendered on any agent-avatar surface THEN the hexagon renders; GIVEN an agent with an `icon` THEN its MDI icon renders instead
  - GIVEN the avatar is decorative WHEN rendered THEN the agent's name remains available to assistive technology — the hexagon is never the sole carrier of meaning
  - No additional network request is introduced; nc-vue publishes before hermiq consumes it
- [ ] Implement
- [ ] Test

## Quality checklist

- PHPUnit unit tests for the resolver covering EVERY tier and EVERY fall-through: per-user hit, per-user inaccessible, per-user deleted, app-config hit, app-config inaccessible, app-config absent, first-accessible hit, nothing accessible
- PHPUnit test that `PUT /api/user/default-agent` returns `403` for an inaccessible `agentId` and stores nothing (IDOR guard)
- A regression test that passes against unfixed code proves nothing — assert the wrong-tier and revoked-access paths explicitly
- Newman/Postman tests for `PUT` and `DELETE /api/user/default-agent`, including the `403`
- Playwright browser tests: the settings picker renders above Talk delivery for a NON-admin; the "Make my default" headerAction renders and works on `/agents/:id`; an icon-less agent shows the hexagon
- The `api-call` headerAction is verified in a LIVE browser — schema-legal ≠ rendered
- All tests pass (`composer test`, `composer check:strict`, `newman run`)
- Feature documentation updated in `docs/` + screenshot in `docs/images/` (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added (ADR-007)
- Follow-up issue filed for model/provider compatibility validation — this change does NOT fix `claude --model qwen2.5` → exit 1 / empty stderr / infinite spin
- `openspec validate default-companion-agent --strict` passes
