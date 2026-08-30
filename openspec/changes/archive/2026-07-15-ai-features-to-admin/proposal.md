# Proposal: ai-features-to-admin

## Summary
Relocate the AI-feature governance register — today an in-app nav page at
`/ai-features` (`AiFeatureRegister.vue`, menu item "AI features") — into Nextcloud's
own **Administration settings** at `/settings/admin/hermiq`, alongside hermiq's existing
"AI provider" and "Web research" admin panels. The register is design-time governance
(EU AI Act risk classification + DPO acknowledgement gate before a feature may be
enabled) — an instance-configuration concern, not a day-to-day operator page — so it
belongs with the app's other pre-boot configuration, not the main nav.

## Motivation
A UI/navigation audit of hermiq against the manifest-v2 conventions found that
governance/configuration surfaces were scattered across the main nav instead of living
where NC users expect instance-level configuration: the admin settings panel. The
AI-feature register is the clearest case — it is inherently an admin/DPO governance
action (enable/disable a feature instance-wide, acknowledge on behalf of the
organisation), not a per-user or per-tenant operational task, and hermiq already has a
working NC admin-settings entry point (`AdminSettings.php` + `AdminRoot.vue`) that this
page can join rather than duplicate.

## Affected Projects
- [ ] Project: `hermiq` — move the AI-feature register UI from the in-app nav to NC
  admin settings; remove the `/ai-features` nav/page/registry entries; no backend
  endpoint changes.

## Scope

### In Scope
- Render `AiFeatureRegister.vue` inside `AdminRoot.vue` (the existing admin-settings
  Vue mount) as a new `NcSettingsSection`, reachable only via
  `/settings/admin/hermiq`.
- Remove the `AiFeatureRegister` menu entry and `/ai-features` page entry from
  `src/manifest.json`, and the corresponding entry + import from `src/registry.js`.
  `AiFeatureRegister.vue` moves from "registry-resolved SPA page" to "directly imported
  by `AdminRoot.vue`" — the file itself is not deleted.
- Extend the admin-settings PHP bootstrap (`AdminSettings::getForm()`) to provide the
  two `IInitialState` keys (`is_admin`, `opencatalogi_available`) the component reads,
  which today are only provided by `DashboardController` for the main SPA bootstrap.
- Keep `AiFeatureController`'s six endpoints (`index`, `acknowledge`, `enable`,
  `disable`, `publishToAlgoritmeregister`, `withdrawFromAlgoritmeregister`) unchanged —
  same routes, same `@NoAdminRequired` + `ActionAuthService` gating.

### Out of Scope
- The Algoritmeregister publish/withdraw surface (the `algoritmeregister` column +
  "Publish"/"Withdraw" buttons already embedded in `AiFeatureRegister.vue`) is **not**
  extracted or re-homed by this change. The sibling `inapp-settings-section` change owns
  turning that surface into its own "Algorithm register" in-app-settings nav item; until
  that change lands, those buttons simply move along with the rest of the component into
  admin settings (see Risks / Open Questions).
- No change to `AiFeatureController`, `AiFeatureService`, the OpenRegister `AiFeature`
  schema, or the DPO-acknowledgement lifecycle guard.
- No change to `ActionAuthService` action-auth semantics (`aifeature.*` actions stay
  broadenable to a non-admin DPO group at the API layer — see Risk 1).
- Broader nav/settings restructuring (Guardrail policy, in-app Settings section
  build-out, Tenant ops split, Dashboard org widgets) is handled by sibling changes
  (`inapp-settings-section`, `dashboard-org-widgets`) — not here.

## Approach
Reuse hermiq's existing NC admin-settings entry point end to end rather than building a
new one: `AdminSettings.php` (already registered, already `IDelegatedSettings`) keeps its
`getForm()`/`getSection()`/`getPriority()` unchanged and gains initial-state provisioning;
`templates/settings/admin.php` and the `adminSettings` webpack entry (`src/settings.js` →
`AdminRoot.vue`) are unchanged as bootstrap wiring; `AdminRoot.vue` gains one more
`NcSettingsSection` that renders the (unmodified) `AiFeatureRegister.vue` component,
imported directly instead of resolved through `registry.js`. The manifest/registry/menu
entries that made it a nav page are deleted. Details in `design.md`.

## New Dependencies
None.

## Impact
- `src/manifest.json` — remove the `AiFeatureRegister` menu entry and `/ai-features`
  page entry.
- `src/registry.js` — remove the `AiFeatureRegister` import + registry entry.
- `src/views/AdminRoot.vue` — add an `AiFeatureRegister` import + a third
  `NcSettingsSection`.
- `lib/Settings/AdminSettings.php` — inject `IInitialState` (+ reuse the existing
  `IAppManager`) and provide `is_admin` / `opencatalogi_available` in `getForm()`.
- `appinfo/info.xml` — `<version>` bump (served-asset hygiene; no schema/route change).
- No change to `lib/Controller/AiFeatureController.php`, `appinfo/routes.php`, or any
  OpenRegister schema.

## Cross-Project Dependencies
None — this is a self-contained hermiq frontend/admin-settings change. It coordinates
(via shared context, not code) with two sibling hermiq changes: `inapp-settings-section`
(owns the future "Algorithm register" in-app nav item) and `dashboard-org-widgets`
(unrelated dashboard widget moves) — neither is implemented or modified here.

## Risks

### Risk 1: Admin-settings gating narrows reachability below the existing DPO-broadening spec
**Severity:** Medium — **Mitigation:** The `ai-feature-governance-register` spec
("Acknowledge, enable, and disable are restricted to admins or the DPO role") lets an
admin broaden the `aifeature.acknowledge`/`enable`/`disable` actions to a non-admin DPO
group via `ActionAuthService`; that group's members could reach `/ai-features` in the
nav today. `AdminSettings::getAuthorizedAppConfig()` returns `[]` (no NC delegated-admin
config keys), so per NC's `IDelegatedSettings` contract the settings page itself is only
navigable by full instance admins — a broadened non-admin DPO-group member keeps working
API access (`ActionAuthService` gating is unchanged) but loses the UI entry point. This
is flagged as an Open Question / deferred decision rather than silently narrowed; no
DPO-group deployment is known to exist today (action matrix seeds to `["admin"]` only),
so the practical exposure is low, and a future change can delegate specific admin-config
keys if that changes.

### Risk 2: Two surfaces temporarily overlap on the Algoritmeregister buttons
**Severity:** Low — **Mitigation:** Until the sibling `inapp-settings-section` change
extracts the Algoritmeregister publish/withdraw UI into its own in-app "Algorithm
register" nav item, those controls simply move into admin settings along with the rest
of `AiFeatureRegister.vue` (no functional loss). Documented here so the sibling change's
author knows to remove/replace that portion from the now-admin-hosted component once its
own surface exists, rather than duplicating it.

## Rollback Strategy
Revert the manifest/registry/`AdminRoot.vue`/`AdminSettings.php` changes in one PR: restore
the `AiFeatureRegister` menu + page entries in `src/manifest.json`, restore the
`src/registry.js` import/entry, remove the `NcSettingsSection` from `AdminRoot.vue`, and
drop the two `provideInitialState` calls from `AdminSettings::getForm()`. No data
migration, no schema change, no API change — a plain frontend/config revert.

## Open Questions
- Should the `aifeature.*` action matrix ever be broadened to a non-admin DPO group in
  practice, and if so, should `AdminSettings` later implement real
  `IDelegatedSettings` delegation (`getAuthorizedAppConfig()`) so a delegated sub-admin
  (not just full admins) can reach the panel? Deferred — no current deployment relies on
  DPO-group broadening (see Risk 1).
- Should `AiFeatureRegister.vue`'s own `max-width: 960px` wrapper be relaxed to match
  `AdminRoot.vue`'s narrower `720px` sections, or left as is (it will simply render wider
  than its siblings)? Left to the implementer's visual judgement in `design.md`/tasks;
  not a functional concern.
