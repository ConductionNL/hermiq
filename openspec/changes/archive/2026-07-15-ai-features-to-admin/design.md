# Design: ai-features-to-admin

## Architecture Overview
Hermiq already has two parallel Vue bootstraps:

1. **The manifest-driven SPA** (`src/main.js` → `src/App.vue` + `vue-router`, routes
   generated from `src/manifest.json`'s `menu`/`pages`, custom pages resolved through
   `src/registry.js`). `AiFeatureRegister` is registered here today as a `kind:"page"`
   entry, reachable at `/apps/hermiq/ai-features`.
2. **The NC admin-settings mount** (`lib/Settings/AdminSettings.php` → NC's settings
   framework renders `templates/settings/admin.php` → `Util::addScript` loads
   `hermiq-shared-vendor` / `hermiq-shared-nc-vue` / `hermiq-settings` → `src/settings.js`
   mounts `AdminRoot.vue` into `#hermiq-settings`). `AdminRoot.vue` already renders two
   `NcSettingsSection`s ("AI provider", "Web research") for pre-boot LLM/search-provider
   configuration.

This change moves `AiFeatureRegister` from bootstrap (1) to bootstrap (2): it becomes a
third `NcSettingsSection` inside `AdminRoot.vue`, imported directly (bootstrap (2) does
not use `registry.js` — that registry only serves the manifest-driven SPA's page/widget
resolution). No new bootstrap, no new webpack entry, no new PHP settings class — hermiq
already has exactly the "admin settings entry point" the brief anticipated needing to
build.

```
Before:                                   After:
main.js (SPA)                             main.js (SPA)
 └─ registry.js                            └─ registry.js
     └─ AiFeatureRegister  (kind:page) ✗       (AiFeatureRegister entry removed)
     manifest.json                           manifest.json
      ├─ menu: AiFeatureRegister ✗             (menu entry removed)
      └─ pages: /ai-features ✗                 (page entry removed)

settings.js (NC admin settings)           settings.js (NC admin settings)
 └─ AdminRoot.vue                          └─ AdminRoot.vue
     ├─ NcSettingsSection "AI provider"        ├─ NcSettingsSection "AI provider"
     └─ NcSettingsSection "Web research"        ├─ NcSettingsSection "Web research"
                                                 └─ NcSettingsSection "AI features"  ← NEW
                                                     └─ <AiFeatureRegister /> (imported directly)
```

`AiFeatureRegister.vue` itself is **not modified internally** — same template, same
`data()`/`computed`/`methods`, same API module (`src/api/aiFeatures.js`), same
`loadState('hermiq', 'is_admin', …)` / `loadState('hermiq', 'opencatalogi_available', …)`
reads. Only *who mounts it* and *where the initial state comes from* changes.

## API Design
No API endpoints are introduced, removed, or changed. `appinfo/routes.php` keeps its six
`aiFeature#*` routes exactly as they are today (see Impact in `proposal.md`).

## Database Changes
None. No OpenRegister schema change, no NC migration.

## Nextcloud Integration
- **Settings**: `lib/Settings/AdminSettings.php` (existing `IDelegatedSettings`) gains one
  responsibility in `getForm()`: provide the two `IInitialState` keys the relocated
  component needs.
  ```php
  public function __construct(
      private readonly IAppManager $appManager,
      private readonly IInitialState $initialState,   // NEW
      private readonly IGroupManager $groupManager,   // NEW
      private readonly IUserSession $userSession,      // NEW
  ) {
  }

  public function getForm(): TemplateResponse
  {
      $version = $this->appManager->getAppVersion(appId: Application::APP_ID);

      // AI-feature register (ai-features-to-admin): the register's UX gating for the
      // Algoritmeregister publish/withdraw controls (unchanged from the in-app nav
      // page). This page is only reachable by a full instance admin already
      // (getAuthorizedAppConfig() returns [] — no delegated-admin config keys), so
      // is_admin is always true here; resolved via IGroupManager for parity with
      // DashboardController::provideKillSwitchCapability() rather than hardcoded.
      $user = $this->userSession->getUser();
      $isAdminNow = ($user !== null && $this->groupManager->isAdmin($user->getUID()) === true);
      $this->initialState->provideInitialState('is_admin', $isAdminNow);
      $this->initialState->provideInitialState(
          'opencatalogi_available',
          $this->appManager->isInstalled('opencatalogi')
      );

      return new TemplateResponse(
          Application::APP_ID,
          'settings/admin',
          ['version' => $version]
      );
  }
  ```
  `getSection()` (`'hermiq'`), `getPriority()` (`10`), `getName()` (`null`), and
  `getAuthorizedAppConfig()` (`[]`) are unchanged — the panel stays a single,
  non-delegated, full-admin-only Hermiq section (see Risk 1 in `proposal.md`).
- **Controllers**: `AiFeatureController` is unchanged — still `@NoAdminRequired` +
  `ActionAuthService::requireAction()` per method, still resolves the caller via
  `IUserSession`. Reachability of the *UI* narrows to full admins; reachability of the
  *API* does not change.
- **Services**: none touched.
- **Mappers/Entities**: none touched.
- **Events/Hooks**: none.

## Security Considerations
- No new attack surface: same controller, same routes, same `ActionAuthService` gate,
  same 401/403 responses.
- The admin-settings page itself is guarded by NC's settings framework the same way
  `AdminSettings`'s existing "AI provider"/"Web research" sections already are — no new
  gate to design, just one more section behind the existing one.
- `hydra-gate-admin-router` is satisfied by construction: `AiFeatureRegister` is removed
  from `src/manifest.json`/`registry.js` (the in-app `vue-router`-backed SPA), so it can
  no longer be reached as a public frontend route — it is only ever mounted by the
  NC-settings-gated `AdminRoot.vue` tree. This is the intended fix direction for that
  gate, not a new violation.
- `hydra-gate-initial-state` is respected: the new initial-state keys use
  `IInitialState::provideInitialState()` / `loadState()`, matching the existing pattern
  (no DOM data-attribute reads introduced).
- See Risk 1 in `proposal.md` for the DPO-group-broadening reachability trade-off.

## NL Design System
`AiFeatureRegister.vue` already uses `NcNoteCard`, `NcEmptyContent`, `NcButton`,
`CnDataTable`, and CSS custom properties (`--color-*`) exclusively — no hardcoded
colors, no non-NC components. Wrapping it in an `NcSettingsSection` (the same pattern
`AdminRoot.vue` already uses for its other two sections) is the only NL-Design-System
touch point; no new component patterns are introduced. The component's own
`max-width: 960px` is left as-is (see Open Questions in `proposal.md`) — it will render
slightly wider than the `720px` "AI provider"/"Web research" sections, which is a minor
visual inconsistency, not a functional or accessibility issue (the data table itself
scrolls horizontally as needed).

## File Structure
```
lib/
  Settings/
    AdminSettings.php          (MODIFIED — inject IInitialState/IGroupManager/IUserSession,
                                 provide is_admin + opencatalogi_available)
src/
  manifest.json                (MODIFIED — remove AiFeatureRegister menu + page entries)
  registry.js                  (MODIFIED — remove AiFeatureRegister import + registry entry)
  views/
    AdminRoot.vue               (MODIFIED — import AiFeatureRegister, add NcSettingsSection)
    AiFeatureRegister.vue       (UNCHANGED — moves who imports it, not its contents)
appinfo/
  info.xml                     (MODIFIED — <version> bump only)
```

## Seed Data
Not applicable — no new schema/entity is introduced; `AiFeature` seed data (if any)
belongs to the `ai-feature-governance-register` change, not this one.

## Trade-offs
- **Reuse `AdminRoot.vue` vs. a second admin-settings entry point**: chosen to reuse.
  Hermiq's `IDelegatedSettings` binding is deliberately single-section
  (`getName(): ?string` returns `null` — "the single Hermiq panel", per the class's own
  docblock). Adding a second `ISettings`/webpack entry pair purely to host one more
  `NcSettingsSection` would duplicate the version-injection, shared-chunk-loading, and
  section-registration boilerplate for no behavioural gain; the brief's own guidance
  ("if the admin settings currently render a different Vue entry … wire the AI-feature
  register into it") points the same way.
- **Leave `AiFeatureRegister.vue` internals untouched vs. splitting out the
  Algoritmeregister buttons now**: chosen to leave untouched. Splitting that surface out
  is the explicit responsibility of the sibling `inapp-settings-section` change (see
  Out of Scope); doing it here would mean guessing at that change's target shape instead
  of letting its own author design it, and would risk a merge conflict between the two
  changes touching the same component simultaneously.
- **Hardcode `is_admin = true` vs. resolve it via `IGroupManager`**: chosen to resolve
  it properly (mirroring `DashboardController::provideKillSwitchCapability()`) rather
  than hardcode `true`. Today they're equivalent (only full admins reach this page), but
  hardcoding would silently go stale if `AdminSettings` ever gains real
  `IDelegatedSettings` delegation (see Risk 1 / Open Questions) — a delegated
  non-full-admin could then reach the page with a wrong `is_admin=true` initial state.
