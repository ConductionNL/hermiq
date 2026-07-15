# Tasks: ai-features-to-admin

## Implementation Tasks

### Task 1: Remove the AI-feature register from the in-app nav (manifest + registry)
- **spec_ref**: `openspec/changes/ai-features-to-admin/specs/ai-feature-admin-surface/spec.md#requirement-the-ai-feature-register-renders-inside-nextcloud-admin-settings-not-the-in-app-nav`
- **files**: `src/manifest.json`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` WHEN inspected THEN it MUST NOT contain the
    `AiFeatureRegister` menu entry (order 21) or the `/ai-features` page entry
  - GIVEN `src/registry.js` WHEN inspected THEN it MUST NOT import
    `./views/AiFeatureRegister.vue` or register an `AiFeatureRegister` entry
  - GIVEN `npm run check:specs` WHEN run THEN it MUST pass (no orphan-import, no
    manifest-v2 schema error)
- [x] Implement
- [x] Test

### Task 2: Wire AiFeatureRegister into AdminRoot.vue as a new admin-settings section
- **spec_ref**: `openspec/changes/ai-features-to-admin/specs/ai-feature-admin-surface/spec.md#requirement-the-ai-feature-register-renders-inside-nextcloud-admin-settings-not-the-in-app-nav`
- **files**: `src/views/AdminRoot.vue`
- **acceptance_criteria**:
  - GIVEN `AdminRoot.vue` WHEN it imports `AiFeatureRegister` from
    `../views/AiFeatureRegister.vue` (a sibling import, not through `registry.js`)
    THEN it MUST render it inside a third `NcSettingsSection` (`name: t('hermiq', 'AI
    features')`) after "AI provider" and "Web research"
  - GIVEN an instance admin opens `/settings/admin/hermiq` WHEN the page loads THEN the
    "AI features" section MUST show the register table with working
    acknowledge/enable/disable buttons (verify live via browser, mirroring the
    existing "AI provider"/"Web research" live-verification pattern — no committed
    Playwright suite for this surface)
- [x] Implement
- [x] Test

### Task 3: Provide is_admin / opencatalogi_available from the admin-settings bootstrap
- **spec_ref**: `openspec/changes/ai-features-to-admin/specs/ai-feature-admin-surface/spec.md#requirement-the-admin-settings-bootstrap-supplies-the-same-capability-flags-the-component-already-reads`
- **files**: `lib/Settings/AdminSettings.php`
- **acceptance_criteria**:
  - GIVEN `AdminSettings::getForm()` WHEN it runs THEN it MUST call
    `IInitialState::provideInitialState('is_admin', …)` (resolved via `IGroupManager` +
    `IUserSession`, mirroring `DashboardController::provideKillSwitchCapability()`) and
    `provideInitialState('opencatalogi_available', $this->appManager->isInstalled('opencatalogi'))`
    before returning the `TemplateResponse`
  - GIVEN OpenCatalogi is installed WHEN the admin-settings page loads THEN
    `loadState('hermiq', 'opencatalogi_available')` in the mounted component MUST
    resolve `true` (verify live, both with OpenCatalogi installed and absent)
  - GIVEN `AdminSettings::getSection()`, `getPriority()`, `getName()`,
    `getAuthorizedAppConfig()` WHEN inspected after this change THEN they MUST be
    unchanged (`'hermiq'`, `10`, `null`, `[]`)
- [x] Implement
- [x] Test

### Task 4: Version bump and end-to-end live verification
- **spec_ref**: `openspec/changes/ai-features-to-admin/specs/ai-feature-admin-surface/spec.md#requirement-the-governance-api-and-its-authorization-are-unchanged-by-the-ui-relocation`
- **files**: `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN `appinfo/info.xml` WHEN this change ships served-asset changes THEN
    `<version>` MUST be bumped (served-asset hygiene; no schema/route change so no
    `lib/Settings/hermiq_register.json` bump is required)
  - GIVEN the full change WHEN verified live THEN: `/settings/admin/hermiq` shows the
    AI-feature section with a working acknowledge → enable → disable round-trip, AND
    the in-app nav no longer shows an "AI features" item, AND
    `AiFeatureController`'s routes/authorization are unchanged (no diff to that file
    or `appinfo/routes.php`)
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed PHP logic (`AdminSettings::getForm()`) covered by a PHPUnit test
  asserting the two new `provideInitialState()` calls (extend the existing
  `AdminSettingsTest` if present, else add one)
- No new Newman/Postman coverage needed — no API endpoint changes
- UI change (admin-settings section) live-verified via browser per Task 2/4 acceptance
  criteria; no committed Playwright suite exists for hermiq's admin-settings surfaces
  (consistent with the existing "AI provider"/"Web research" sections)
- `npm run lint` and `npm run check:specs` both pass
- No new user-facing strings are introduced (the "AI features" section name reuses the
  existing `t('hermiq', 'AI features')` string), so no `l10n/en.json` / `l10n/nl.json`
  additions are required
- `openspec validate ai-features-to-admin --type change --strict` passes
