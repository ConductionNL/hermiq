# Template — manifest v1: scaffold the JSON manifest renderer pattern as the template default

## Why

`nextcloud-app-template` is the cookie-cutter every new Conduction
Nextcloud app starts from. Today the template ships a hand-rolled
Vue-router shell (`MainMenu.vue` + `<router-view>` + per-page Vue
files) that pre-dates the JSON manifest renderer.

Hydra ADR-024 mandates the opposite default:

> "Every Conduction app SHOULD ship a `src/manifest.json` validated
> against the canonical schema. **New apps MUST adopt at least Tier 1
> from inception.**"

The fleet adoption spec
(`hydra/openspec/changes/adopt-app-manifest/specs/adopt-app-manifest/spec.md`)
adds: every consumer MUST place the manifest at `src/manifest.json`,
load it with `useAppManifest`, and gate the build with
`npm run check:manifest`.

If the template doesn't ship the manifest pattern, every new app
that scaffolds off this repo starts at Tier 0 and has to migrate
later — the exact cost ADR-024 is trying to eliminate. Decidesk's
20-of-20 `type: "custom"` migration (`decidesk-manifest-v1`)
demonstrates the cost of getting it wrong.

`@conduction/nextcloud-vue@1.0.0-beta.12` (just published) ships
the full Tier-4 surface: `CnAppRoot`, `CnAppNav`, `CnPageRenderer`,
plus seven page types (`index | detail | dashboard | logs |
settings | chat | files | custom`). All gaps that previously forced
consumers into `type: "custom"` are closed.

This change rebuilds the template as the canonical Tier-4
scaffolding for the manifest renderer pattern. After this change,
generating a new app from this template produces a working
manifest-driven shell on first `npm install && npm run build`.

## What Changes

- **Rewrite `src/main.js`** to the mount-survivable Tier-4
  bootstrap pattern (decidesk's `50e4df7c` + `866ff132`):
  - Import `bundledManifest from './manifest.json'` and pass it to
    `<CnAppRoot>` via the App.vue `manifest` prop.
  - Build vue-router routes from `manifest.pages[*].{id, route}`
    via a `routesFromManifest()` helper.
  - Shallow-clone `CnPageRenderer`, `defaultPageTypes`, and
    `customComponents` to avoid Vue 2's `Vue.extend()`
    "Cannot add property `_Ctor`" errors against the lib's frozen
    barrel exports.
  - Mount immediately on `#content`; do NOT wait for
    `loadTranslations` (NC dev installs commonly 404 the
    `/l10n/*.json` route, blocking boot).

- **Replace `src/App.vue`** with a `<CnAppRoot>` shell:
  - `manifest`, `customComponents`, `pageTypes` props from
    main.js.
  - `#sidebar` slot wired to a `CnObjectSidebar` driven by an
    `objectSidebarState` provide/inject channel — the standard
    pattern for `CnDetailPage` → host-rendered sidebar.
  - `translateForApp(key)` closure passes app-id-aware `t()`
    down through CnAppRoot.

- **Add `src/manifest.json`** as a minimal, valid, opinionated
  manifest:
  - 4 pages: `Dashboard` (type: `dashboard`), `Items` (type:
    `index`), `ItemDetail` (type: `detail`), `Settings` (type:
    `settings`).
  - 4 menu entries (3 main + Settings in the settings section).
  - `dependencies: ["openregister"]` (the template's pre-wired
    integration; downstream apps remove this if they don't use OR).
  - Settings page demonstrates the `version-info` widget rich
    section (the pattern for app-info) — explicitly NOT the
    `register-mapping` widget, since that's a per-app schema-list
    decision.

- **Add `src/customComponents.js`** as the empty-by-default
  registry, with one example placeholder
  (`CustomExample` → `views/CustomExample.vue`) so the registry
  has something to demonstrate.

- **Replace `src/views/Dashboard.vue` + `src/views/settings/`
  + `src/navigation/MainMenu.vue` + `src/router/index.js`**
  with the manifest-driven equivalents.

- **Keep `src/views/CustomExample.vue`** as a trivial example
  custom component referenced by `customComponents.js`.

- **Bump `package.json` `@conduction/nextcloud-vue` floor**
  to `^1.0.0-beta.12` (the published lib version that includes
  the Vue.extend frozen-component fix).

- **Add the webpack `@nextcloud/axios$` alias** (decidesk's
  `ed34703c` pattern) so the lib's axios import resolves to a
  single instance.

- **Add `tests/validate-manifest.js`** copied from
  decidesk's reference. Validates `src/manifest.json` against
  `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`.

- **Add `npm run check:manifest`** to `package.json` scripts
  (per the fleet adoption spec).

- **Add empty `l10n/en.json` + `l10n/en_US.json`** translation
  files as placeholders for future strings.

- **Update `README.md`** with the manifest-first quickstart:
  tell users to edit `src/manifest.json` to add pages and only
  write a custom Vue component when the page type is `custom`.

## Reference

- Hydra ADR-024:
  `hydra/openspec/architecture/adr-024-app-manifest.md`.
- Fleet adoption spec:
  `hydra/openspec/changes/adopt-app-manifest/specs/adopt-app-manifest/spec.md`.
- Reference consumer migration:
  `decidesk/openspec/changes/decidesk-manifest-v1/`. Key commits:
  - `b5c88cd2` initial manifest migration
  - `4b49bca1` CnAppRoot adoption + cleanup
  - `ed34703c` lib bump + webpack alias
  - `50e4df7c` mount-survivable bootstrap pattern
  - `866ff132` final dep bump to 1.0.0-beta.12

## Out of scope

- Runtime smoke test inside a live Nextcloud instance (the
  template has no fleet schemas to point register-mapping at;
  smoke is delegated to the first downstream consumer).
- Backend `/api/manifest` override endpoint — Tier 4 consumes
  the bundled manifest only; the override hook is opt-in per
  ADR-024 §4.
- A `make new-app NAME=...` scaffolder target — left for a
  follow-up; this change keeps the existing `make dev-link`
  helper.
- Removing the OpenRegister wiring from PHP backend
  (`lib/Service`, `lib/Repair/InitializeSettings.php`) — those
  remain useful defaults for the OR-dependent majority of new
  apps. Apps that don't need OR continue to follow the existing
  README guidance ("remove the dependency from `appinfo/info.xml`
  and `openspec/app-config.json`").
