# Tasks — Template manifest v1: scaffold the JSON manifest renderer pattern as the template default

## 1. Manifest

- [x] 1.1 Add `src/manifest.json` with 4 pages: `Dashboard` (type `dashboard`), `Items` (type `index`), `ItemDetail` (type `detail`), `Settings` (type `settings`).
- [x] 1.2 Add 4 menu entries: 3 main (Dashboard, Items, plus a `Documentation` external href) and Settings in the `settings` section.
- [x] 1.3 Declare `dependencies: ["openregister"]` (the template's pre-wired default; downstream apps remove if they don't need OR).
- [x] 1.4 Settings page declares a `version-info` rich-section widget; the `register-mapping` widget is NOT in the template manifest (per-app decision).
- [x] 1.5 Set top-level `version` to `0.1.0` (template content version, distinct from the schema version).
- [x] 1.6 Set `$schema` to `https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest.schema.json`.

## 2. Bootstrap pattern

- [x] 2.1 Rewrite `src/main.js` to the mount-survivable Tier-4 bootstrap pattern (decidesk's `50e4df7c` + `866ff132`): import `bundledManifest from './manifest.json'` and `customComponents from './customComponents.js'`; shallow-clone `CnPageRenderer`, `defaultPageTypes`, and `customComponents` before passing to App.vue; build vue-router routes from `manifest.pages[*].{id, route}` via a `routesFromManifest()` helper.
- [x] 2.2 Mount immediately on `#content`; do NOT wait for `loadTranslations` (NC dev installs commonly 404 the `/l10n/*.json` route).
- [x] 2.3 Replace `src/App.vue` with `<CnAppRoot>` shell receiving `manifest`, `customComponents`, `pageTypes`, `app-id`, `translate`, and `permissions`. Provide an `objectSidebarState` Vue.observable channel. Mount a `CnObjectSidebar` in the `#sidebar` slot driven by that channel.
- [x] 2.4 Pass `translateForApp(key)` closure that delegates to `@nextcloud/l10n`'s `translate('app-template', key)`.

## 3. Custom components registry

- [x] 3.1 Add `src/customComponents.js` exporting an object with one example entry: `CustomExample: CustomExample` (mapping the manifest's `component` field to a Vue file).
- [x] 3.2 Add `src/views/CustomExample.vue` as a trivial example custom component (a `CnNoteCard` with copy explaining when to use a `type: "custom"` page).

## 4. Webpack + dependencies

- [x] 4.1 Bump `package.json` `@conduction/nextcloud-vue` floor to `^1.0.0-beta.12`.
- [x] 4.2 Add a webpack alias for `@nextcloud/axios$` (decidesk's `ed34703c` pattern) so the lib's axios import resolves to the app's installed copy.
- [x] 4.3 Add `npm run check:manifest` script to `package.json`.

## 5. Validator script

- [x] 5.1 Add `tests/validate-manifest.js` copied from `decidesk/tests/validate-manifest.js`. Schema lookup order: env var, `node_modules/@conduction/nextcloud-vue/src/schemas/`, sibling worktree.
- [x] 5.2 Run `node tests/validate-manifest.js` and confirm zero schema errors.

## 6. l10n

- [x] 6.1 Add `l10n/en.json` and `l10n/en_US.json` as empty placeholder translation tables.

## 7. Cleanup of legacy shell

- [x] 7.1 Delete `src/router/index.js` (folded into `src/main.js`).
- [x] 7.2 Delete `src/navigation/MainMenu.vue` (replaced by `CnAppNav` mounted by `CnAppRoot`).
- [x] 7.3 Delete `src/views/Dashboard.vue` (replaced by manifest `type: "dashboard"`).
- [x] 7.4 Delete `src/views/settings/` (replaced by manifest `type: "settings"` with rich sections).

## 8. README + docs

- [x] 8.1 Rewrite the README "Adding a page" / quickstart to be manifest-first: edit `src/manifest.json`, add a route + menu entry, only write a Vue file when the page type is `custom`.
- [x] 8.2 Document the `customComponents.js` registry's role.
- [x] 8.3 Update the directory-structure section to reflect the new file layout (manifest.json, customComponents.js, no router/, no navigation/).

## 9. Spec artifacts

- [x] 9.1 `openspec/changes/template-manifest-v1/proposal.md` — already drafted.
- [x] 9.2 `openspec/changes/template-manifest-v1/design.md` — file-by-file inventory + smoke-test recipe.
- [x] 9.3 `openspec/changes/template-manifest-v1/tasks.md` — this file.
- [x] 9.4 `openspec/changes/template-manifest-v1/specs/template-manifest-v1/spec.md` — REQ-TMV1-* requirements covering the scaffold.

## 10. Validation + commit

- [x] 10.1 Run `node tests/validate-manifest.js` and confirm zero schema errors.
- [x] 10.2 Run `npx eslint src tests` (where applicable) and confirm zero errors.
- [x] 10.3 Run `npx webpack --config webpack.config.js --mode production` and confirm zero errors.
- [x] 10.4 Stage changes in 3-4 logical chunks and commit on branch `feature/template-manifest-v1` with no `Co-Authored-By` trailer.
