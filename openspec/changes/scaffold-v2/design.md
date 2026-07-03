# scaffold-v2 Design

## Capability

`scaffold-v2`

## Kind

`config` — manifest JSON edits + minimal thin-glue wiring in `main.js` and
`App.vue`. The registry component examples (`ExampleWidget.vue`,
`ExampleModal.vue`, `EmailField.vue`, `StatusBadge.vue`) are scaffold
demonstrations, not production logic. The thin-glue exception from ADR-032
applies.

## Manifest transformation strategy

### Dashboard page

V1 `config.widgets[]` + `config.layout[]` become a single top-level
`widgets[]`. The v1 `open-items` widget (`type: "stats-block"`) maps to:

```json
{
  "widgetKey": "stats-block",
  "slot": "body",
  "gridX": 0, "gridY": 0, "gridWidth": 3, "gridHeight": 1,
  "props": { ... }
}
```

`stats-block` is a known widget type handled by `CnStatsBlockWidget` in the
renderer; it is not in the five built-ins registered in `builtInWidgets.js`
(those are `object-table`, `form-renderer`, `wiki-renderer`, `map-viewer`,
`card-grid`). The renderer resolves `widgetKey` first against the built-in
registry, then against `cnRegistry`. `stats-block` is handled as a v1 named
type in `CnDashboardPage`; in v2 it becomes a `widgetKey` that the renderer
will need to support. This is an **educated guess** documented here — if the
v2 renderer does not register `stats-block` as a built-in, the scaffold's
dashboard will fall back gracefully (unknown widgetKey warning, no crash) and
a follow-up change can add a `stats-block` entry to `builtInWidgets.js`.

### ItemDetail page sidebar

V1 `config.sidebarProps.tabs[].widgets[{type:"data"}]` and `[{type:"audit-trail"}]`
become v2 `widgets[]` entries with `slot: "sidebar"`:

- Data tab: `widgetKey: "object-data"`, `tabGroup: "data"` — educated guess
  mapping to `CnObjectDataWidget`. If the renderer registers it as
  `"object-data"` this is correct; otherwise `"data"` is an alternative key.
- Audit tab: `widgetKey: "audit-trail"`, `tabGroup: "audit"` — maps to the
  existing `audit-trail` built-in slot in `CnObjectSidebar`.

Both are documented as educated guesses; the fleet adoption change will confirm
the exact keys once `manifest-v2-library` merges and the renderer's registry
is finalised.

### Settings page

V1 `config.sections[].widgets[{type:"version-info"}]` flattens to:

```json
{
  "widgetKey": "version-info",
  "slot": "section:version",
  "gridX": 0, "gridY": 0, "gridWidth": 12, "gridHeight": 1,
  "props": { ... }
}
```

`version-info` is a confirmed built-in type in `CnSettingsPage` (resolved
against `CnVersionInfoCard`).

### FeaturesRoadmap (custom)

Retains `type: "custom"` with required v2 `_note` field documenting why
decomposition is not feasible.

## Registry design

`src/registry.js` exports a single default object:

```js
export default {
  "example-widget":  { kind: "widget",  component, defaultSize, ... },
  "example-modal":   { kind: "modal",   component, propsSchema },
  "CustomExample":   { kind: "page",    component },
  "FeaturesRoadmap": { kind: "page",    component },
  "email-field":     { kind: "form-field",    component, appliesTo: { format: "email" } },
  "status-badge":    { kind: "cell-renderer", component, appliesTo: { schema: "item", property: "status" } },
}
```

Keys follow the convention:
- `widget`, `modal`, `form-field`, `cell-renderer` entries: kebab-case keys.
- `page` entries: PascalCase to match the v1 `customComponents` keys (keeps
  the manifest's `component` field values stable across the v1→v2 migration).

## Coexistence with v1

`customComponents.js` is retained and its entries still exported. `main.js`
passes both `customComponents` and `registry` props to `App.vue`. `CnAppRoot`
will warn once when a v2 manifest is loaded alongside a non-empty
`customComponents` prop (per the renderer spec). This is an intentional
transitional state; the README and `customComponents.js` comments call it out.

## Test approach

No Jest framework is used — tests are Node validator scripts consistent with
the existing `validate-manifest.js` / `validate-register.js` pattern.

- `tests/manifest-v2.spec.js`: loads `src/manifest.json`, calls
  `validateManifestV2` from the renderer worktree (or falls back to structural
  check), asserts zero errors.
- `tests/registry.spec.js`: imports `src/registry.js` (via CommonJS shim),
  asserts all five kinds are represented and each entry has its required
  metadata fields.

A `check:manifest-v2` npm script is added; `check:specs` is extended to
include it.

## Dependencies

- nc-vue PRs #254 #255 #256 must merge before the v2 schema URL
  (`app-manifest-v2.schema.json`) is resolvable in CI. Until then,
  `tests/manifest-v2.spec.js` falls back to a structural check (same pattern
  as `validate-manifest.js` with the v1 schema).
