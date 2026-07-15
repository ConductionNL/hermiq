# ai-feature-admin-surface Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- `ai-features-to-admin`

## Purpose
The AI-feature governance register (EU AI Act risk classification + DPO-acknowledgement
gate; see the `ai-feature-governance-register` capability) is design-time, instance-level
governance — enabling/disabling a feature, and acknowledging it as DPO, are admin actions,
not day-to-day operator tasks. This capability describes *where* that register is
reachable from: Nextcloud's Administration settings, not the app's main in-app nav. It
covers UI placement only; the governance data model, lifecycle guard, and API
authorization are owned by `ai-feature-governance-register` and unchanged here.

## ADDED Requirements

### Requirement: The AI-feature register renders inside Nextcloud admin settings, not the in-app nav

The system MUST render the AI-feature governance register only within Nextcloud's
Administration settings for hermiq (reachable at `/settings/admin/hermiq`), as a section
of the existing admin-settings Vue mount (`AdminRoot.vue`), and MUST NOT expose it as an
in-app `vue-router` page or main-nav menu item.

#### Scenario: Opening admin settings shows the AI-feature section

- **GIVEN** an instance admin opens Administration settings → Hermiq
- **WHEN** the panel loads
- **THEN** the system MUST show an "AI features" section listing the tenant's AiFeature
  register with the same governance actions (list, acknowledge, enable, disable) it had
  on the former in-app nav page

@e2e exclude UI relocation verified live via browser navigation to /settings/admin/hermiq during implementation; no committed Playwright suite exists for hermiq's admin-settings surfaces yet (mirrors the "AI provider"/"Web research" sections, which are also live-verified only).

#### Scenario: The former in-app nav route no longer exists

- **GIVEN** the manifest-driven SPA's `src/manifest.json`, `src/registry.js`
- **WHEN** they are inspected after this change
- **THEN** neither MUST contain an `AiFeatureRegister` menu entry, a `/ai-features` page
  entry, or a registry entry/import for it
- **AND** navigating to the app's former in-app route MUST NOT resolve to the AI-feature
  register (no matching SPA route remains)

@e2e exclude static manifest/registry absence is verified by `npm run check:specs` (manifest-v2 schema + registry cross-reference) and by grep during code review; no dynamic browser assertion needed for an absent route.

### Requirement: The admin-settings bootstrap supplies the same capability flags the component already reads

The system MUST provide the `is_admin` and `opencatalogi_available`
`IInitialState` keys from the admin-settings bootstrap (`AdminSettings::getForm()`) so
the relocated component's existing Algoritmeregister publish/withdraw visibility gating
(`loadState('hermiq', 'is_admin', …)` / `loadState('hermiq', 'opencatalogi_available',
…)`) continues to resolve correctly after the move — the component itself is not
modified to accommodate the relocation.

#### Scenario: Algoritmeregister action gating still resolves correctly after the move

- **GIVEN** OpenCatalogi is installed and an instance admin opens Administration
  settings → Hermiq
- **WHEN** the AI-feature section loads
- **THEN** `opencatalogi_available` MUST be `true` and `is_admin` MUST be `true`,
  identical to what the same caller would have seen loading the former `/ai-features`
  nav page

@e2e exclude initial-state provisioning is verified live (admin settings page loaded with OpenCatalogi installed/absent) during implementation; no committed Playwright suite for this surface yet.

### Requirement: The governance API and its authorization are unchanged by the UI relocation

The system MUST leave `AiFeatureController`'s routes, `@NoAdminRequired` /
`@NoCSRFRequired` attributes, and `ActionAuthService` action-auth gating unchanged by
this UI relocation — moving where the UI is mounted MUST NOT alter who can call the
underlying API or what response codes it returns.

#### Scenario: API callers are unaffected by the UI move

- **GIVEN** a caller whose authorization outcome for the acknowledge/enable/disable/list
  endpoints was fixed before this change (e.g. 401 unauthenticated, 403 non-admin/non-DPO,
  200 admin)
- **WHEN** this change is applied and the same caller invokes the same endpoint
- **THEN** the system MUST return the same authorization outcome as before the change

@e2e exclude covered by the existing AiFeatureController unit tests (unchanged by this PR); regression risk is a pure refactor with no controller diff, so no new test is added for this requirement.

## Non-Functional Requirements

- **Performance:** No measurable change — the component's own data fetch
  (`listAiFeatures()`) is unchanged; only its mount point moves. The admin-settings
  bundle grows by one component's worth of JS (already shipped in the shared SPA bundle
  today; net bundle-size delta is not expected to be user-visible).
- **Accessibility:** No regression — `AiFeatureRegister.vue`'s existing NC components
  (`NcButton`, `NcEmptyContent`, `NcNoteCard`, `CnDataTable`) and ARIA labels are
  unchanged; `NcSettingsSection` provides the same heading semantics `AdminRoot.vue`'s
  other two sections already use.
- **Internationalization:** Dutch and English MUST continue to be supported (ADR-005) —
  no new user-facing strings are introduced by the relocation itself (the "AI features"
  section name reuses the existing `t('hermiq', 'AI features')` string already shipped
  as the former menu label).

## Acceptance Criteria

- [ ] `/settings/admin/hermiq` shows an "AI features" section listing the tenant's
  AiFeature register with working acknowledge/enable/disable actions
- [ ] `src/manifest.json` and `src/registry.js` no longer reference `AiFeatureRegister`
  as a nav/page/registry entry
- [ ] `npm run check:specs` and `npm run lint` pass with no orphan-import or manifest
  errors
- [ ] `AiFeatureController`'s routes and authorization are unchanged (no diff to that
  file or `appinfo/routes.php`)

## Notes

- Depends on / coordinates with (prose only, no shared code path): the sibling
  `inapp-settings-section` change, which will later extract the Algoritmeregister
  publish/withdraw surface out of the now-admin-hosted `AiFeatureRegister.vue` into its
  own in-app "Algorithm register" nav item. That extraction is explicitly out of scope
  here (see `proposal.md` Out of Scope / Risk 2).
- Related ADRs: ADR-004 (`IInitialState` over DOM data-attributes), ADR-036 (universal
  widget/page manifest — `registry.js` kinds).
- See `proposal.md` Risk 1 for the accepted, documented trade-off around DPO-group
  UI reachability under NC's non-delegated `IDelegatedSettings` binding.
