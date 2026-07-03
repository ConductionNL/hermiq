---
status: in-progress
---

# scaffold-v2 Specification

## Purpose

Update the `nextcloud-app-template` scaffold so new apps cloned from the
template start with a v2 manifest (`src/manifest.json`) and a five-kind
component registry (`src/registry.js`) by default. The v2 manifest is defined
in hydra ADR-036 and validated by `@conduction/nextcloud-vue`. This spec
covers the normative requirements for the scaffold update only; the underlying
v2 contract lives in `hydra/openspec/specs/manifest-v2/spec.md`.

## ADDED Requirements

### Requirement: Manifest declares v2 schema

The scaffolded `src/manifest.json` SHALL declare the v2 `$schema` field
pointing to the canonical v2 schema URL published by `@conduction/nextcloud-vue`.

The manifest SHALL pass `validateManifestV2` (from `@conduction/nextcloud-vue`)
with zero errors.

#### Scenario: Manifest validates as v2

- **WHEN** `node tests/manifest-v2.spec.js` is executed
- **THEN** the script SHALL exit with code 0
- **THEN** the output SHALL report zero validation errors

#### Scenario: Manifest has no layout[] arrays

- **WHEN** the manifest is parsed as JSON
- **THEN** no page entry SHALL have a top-level `layout` key
- **THEN** every page entry that has widgets SHALL express them via top-level
  `widgets[]` with the uniform `{ widgetKey, slot, gridX, gridY, gridWidth, gridHeight }` shape

### Requirement: Dashboard page uses unified widgets

The scaffolded dashboard page SHALL use a top-level `widgets[]` array with the
unified `widgetEntry` shape instead of `config.widgets[]` + `config.layout[]`.

#### Scenario: Dashboard widgets array present

- **WHEN** the Dashboard page entry is read from the manifest
- **THEN** the page SHALL have a top-level `widgets` array
- **THEN** the page SHALL NOT have a top-level `layout` key
- **THEN** each widget entry SHALL include `widgetKey`, `slot`, `gridX`, `gridY`, `gridWidth`, `gridHeight`

### Requirement: Detail page sidebar uses tabGroup

The scaffolded detail page's sidebar content SHALL be expressed via top-level
`widgets[]` with `slot: "sidebar"` and `tabGroup` identifying the tab.

#### Scenario: Detail sidebar widgets use tabGroup

- **WHEN** the ItemDetail page entry is read from the manifest
- **THEN** the page SHALL NOT have a `config.sidebarProps` key
- **THEN** the page SHALL have a top-level `widgets` array
- **THEN** each sidebar widget entry SHALL have `slot: "sidebar"` and a `tabGroup` field
- **THEN** `gridWidth` for sidebar entries SHALL be exactly 1

### Requirement: Settings page uses section slots

The scaffolded settings page's widget content SHALL be expressed via top-level
`widgets[]` with `slot: "section:<id>"` slots.

#### Scenario: Settings widgets use section slot

- **WHEN** the Settings page entry is read from the manifest
- **THEN** the page SHALL have a top-level `widgets` array
- **THEN** each widget entry's `slot` SHALL match the pattern `section:<id>`
- **THEN** the version-info widget SHALL use `widgetKey: "version-info"` with `slot: "section:version"`

### Requirement: Custom pages include _note

All pages with `type: "custom"` SHALL include a `_note` field documenting why
decomposition into a typed page was not feasible.

#### Scenario: FeaturesRoadmap has _note

- **WHEN** the FeaturesRoadmap page entry is read from the manifest
- **THEN** the page SHALL have a non-empty `_note` string field

### Requirement: Five-kind registry shipped

`src/registry.js` SHALL export a default object containing at least one entry
per kind from the five-kind registry (`widget`, `modal`, `page`, `form-field`,
`cell-renderer`).

#### Scenario: All five kinds present

- **WHEN** `node tests/registry.spec.js` is executed
- **THEN** the script SHALL exit with code 0
- **THEN** the output SHALL confirm at least one entry for each of the five kinds

#### Scenario: Kind-required metadata present

- **WHEN** the registry is loaded
- **THEN** every `kind: "widget"` entry SHALL include `defaultSize`, `minSize`, `maxSize`, `allowedSlots`, `propsSchema`
- **THEN** every `kind: "modal"` entry SHALL include `propsSchema`
- **THEN** every `kind: "form-field"` entry SHALL include `appliesTo` with at least one of `format` or `property`
- **THEN** every `kind: "cell-renderer"` entry SHALL include `appliesTo` with `schema` and `property`

### Requirement: main.js passes registry prop

`src/main.js` SHALL import `registry` from `src/registry.js` and pass it as
a shallow-cloned `registry` prop to `App.vue` alongside the existing
`customComponents` prop.

#### Scenario: Registry prop wired at boot

- **WHEN** `src/main.js` bootstraps the Vue app
- **THEN** the `registry` prop SHALL be set on the root Vue instance
- **THEN** the `customComponents` prop SHALL remain set (coexistence)

### Requirement: App.vue accepts and forwards registry prop

`src/App.vue` SHALL declare a `registry` prop (Object, default `{}`) and pass
it to `CnAppRoot` alongside `customComponents`.

#### Scenario: App.vue passes registry to CnAppRoot

- **WHEN** `App.vue` renders with a non-empty `registry` prop
- **THEN** `CnAppRoot` SHALL receive the `registry` value via its `registry` prop

### Requirement: README documents v2 quickstart

`README.md` SHALL include a "Manifest v2 ready" subsection explaining the v2
default, pointing to ADR-036, pointing to the migration guide, and documenting
how to add new pages/widgets/modals via the registry.

#### Scenario: README has v2 section

- **WHEN** README.md is read
- **THEN** the phrase "Manifest v2 ready" SHALL appear as a heading or within
  a subsection under the Development section
- **THEN** the text SHALL reference ADR-036
- **THEN** the text SHALL reference `src/registry.js` as the v2 extension point

### Requirement: check:manifest-v2 script added

`package.json` SHALL include a `check:manifest-v2` script that runs
`node tests/manifest-v2.spec.js`. The `check:specs` script SHALL include
`check:manifest-v2`.

#### Scenario: check:manifest-v2 exits 0

- **WHEN** `npm run check:manifest-v2` is executed
- **THEN** the process SHALL exit with code 0
- **THEN** the output SHALL confirm the manifest passes v2 validation
