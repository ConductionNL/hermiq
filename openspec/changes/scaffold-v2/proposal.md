# scaffold-v2: update the app template scaffold to ship v2 manifest by default

## Why

`nextcloud-app-template` is the cookie-cutter every new Conduction Nextcloud
app starts from. Its current `src/manifest.json` is a v1.x manifest, and its
`src/customComponents.js` uses the old "page-only" registry shape that ADR-036
replaces.

ADR-036 (Universal Widget Manifest v2) defines a second-generation manifest
that collapses the four v1 widget shapes into a single uniform `widgets[]`
array, introduces a five-kind component registry (`widget | modal | page |
form-field | cell-renderer`), and ships a v2 JSON schema validated by
`@conduction/nextcloud-vue`.

If the template continues to ship a v1 manifest after the v2 library lands,
every new app scaffolded from this repo starts at v1 and needs a mechanical
migration before nc-vue 3.0 drops v1 support (target 2026-Q3). The codemod
handles 50-70% of that work automatically, but the audit burden is non-zero.
Updating the scaffold now ensures new apps start v2-native.

This is Phase 1c of the v2 manifest rollout chain defined in ADR-036:

  1. ADR-036 (hydra) — architecture + spec. ✓ In progress.
  2. `manifest-v2-library` (nextcloud-vue) — v2 JSON schema, dual validator,
     codemod CLI, `useRuntimeManifest`, `CnAppRoot` `registry` prop. ✓ In
     progress (nc-vue PRs #254 #255 #256).
  3. **`scaffold-v2` (this change, nextcloud-app-template)** — updated scaffold
     ships v2 manifest with example registrations for each kind.
  4. Reference migrations (parallel): procest + launchpad.
  5. Fleet rollout via `opsx-pipeline`.

## What Changes

- **Transform `src/manifest.json`** from v1.x to v2:
  - `$schema` updated to v2 URL.
  - `version` bumped to `0.2.0`.
  - Dashboard page: `config.widgets[] + config.layout[]` collapsed into
    top-level `widgets[]` with `widgetKey`, `slot`, grid coordinates.
  - Items index page: explicit `widgets[]` with `object-table` built-in.
  - ItemDetail page: `config.sidebarProps.tabs[].widgets[]` lifted to
    top-level `widgets[]` with `slot: "sidebar"` + `tabGroup`.
  - Settings page: `config.sections[].widgets[]` flattened to top-level
    `widgets[]` with `slot: "section:version"`.
  - FeaturesRoadmap: `type: "custom"` page gains required `_note` field.

- **Add `src/registry.js`** — the v2 five-kind registry.  Demonstrates all
  five kinds with minimal, working examples. Keeps `customComponents.js` for
  v1 backward-compat reference; the v2 way is `registry.js`.

- **Add example registry components** (one per kind):
  - `src/widgets/ExampleWidget.vue` — `kind: "widget"`.
  - `src/modals/ExampleModal.vue` — `kind: "modal"`, uses NcDialog.
  - `src/formFields/EmailField.vue` — `kind: "form-field"`.
  - `src/cellRenderers/StatusBadge.vue` — `kind: "cell-renderer"`.
  - `src/views/CustomExample.vue` and `src/views/FeaturesRoadmap.vue` ported
    to `kind: "page"` entries.

- **Update `src/main.js`** to import `registry.js` and pass `registry` prop
  alongside the existing `customComponents` prop (both coexist during
  transition).

- **Update `src/App.vue`** to accept and pass down the `registry` prop to
  `CnAppRoot`.

- **Update `README.md`** with a "Manifest v2 ready" subsection in the
  Development section.

- **Add `tests/manifest-v2.spec.js`** and **`tests/registry.spec.js`** — Node
  validator scripts asserting the v2 manifest and registry are structurally
  valid.

## Out of Scope

- The `template-manifest-v1` change stays intact (historical adoption record).
- Runtime smoke test inside a live Nextcloud instance (delegated to downstream
  consumers).
- Removing `customComponents.js` — apps migrating to v2 can switch
  progressively; the v1 prop continues to work for v1 manifests.
- `useRuntimeManifest` adoption — that is the launchpad migration (Phase 1d of the
  ADR-036 rollout chain).

## References

- Hydra ADR-036: `hydra/openspec/architecture/adr-036-universal-widget-manifest.md`
- nc-vue manifest-v2 spec: `hydra/openspec/specs/manifest-v2/spec.md`
- nc-vue renderer spec: `nc-vue/openspec/changes/manifest-v2-renderer/specs/manifest-v2-renderer/spec.md`
- nc-vue PRs: #254 (schema + validator), #255 (CnAppRoot registry prop), #256 (codemod CLI)
- ADR-032 (Spec Sizing): this change is `kind: config`; wiring changes in
  main.js/App.vue qualify as thin-glue under the config kind exception.
