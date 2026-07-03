---
status: draft
---
# Template manifest v1 — JSON manifest renderer scaffold

## Purpose

Establish `nextcloud-app-template` as the canonical Tier-4
scaffolding for the JSON manifest renderer pattern. After this
change, every new Conduction Nextcloud app generated from this
template starts with a working manifest-driven shell.

This implements hydra ADR-024's "New apps MUST adopt the manifest
from inception" requirement at the source — the template — rather
than retrofitting it into every downstream consumer.

## ADDED Requirements

### Requirement: REQ-TMV1-1 The template MUST ship `src/manifest.json` at the canonical location

The template's `src/manifest.json` MUST exist, MUST be valid JSON,
and MUST set `$schema` to
`https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest.schema.json`.
The file MUST validate against the v1.x schema published by
`@conduction/nextcloud-vue` with zero errors.

#### Scenario: validate-manifest passes on first clone
- GIVEN a fresh clone of the template
- AND `npm install` has resolved `@conduction/nextcloud-vue@^1.0.0-beta.12`
- WHEN `node tests/validate-manifest.js` runs
- THEN the script MUST exit with status code 0
- AND no validation errors MUST be printed

### Requirement: REQ-TMV1-2 The template manifest MUST include exactly four example pages, one per primary built-in type

`src/manifest.json` MUST declare exactly four pages, one each of
`type: "dashboard"`, `type: "index"`, `type: "detail"`, and
`type: "settings"`. No `type: "custom"` pages MUST be present in
the template manifest (the registry's example custom component
exists for documentation purposes; the manifest itself MUST NOT
reference it by default).

#### Scenario: Four pages of distinct types
- GIVEN `src/manifest.json`
- WHEN counting `pages[]`
- THEN the count MUST be exactly 4
- AND the set `{p.type for p in pages}` MUST equal `{"dashboard", "index", "detail", "settings"}`

#### Scenario: No custom-type pages by default
- GIVEN `src/manifest.json`
- WHEN counting `pages[*].type === "custom"`
- THEN the count MUST be exactly 0

### Requirement: REQ-TMV1-3 The template MUST declare openregister as a default dependency

`manifest.dependencies` MUST include `"openregister"` as the
template's default. Downstream consumers that do not need
OpenRegister remove the entry per the README guidance; the
template ships it because the majority of Conduction apps use OR.

#### Scenario: Default openregister dependency
- GIVEN `src/manifest.json`
- WHEN inspecting `manifest.dependencies`
- THEN the array MUST contain `"openregister"`

### Requirement: REQ-TMV1-4 The template MUST mount `<CnAppRoot>` at Tier 4

`src/App.vue` MUST mount `<CnAppRoot>` from
`@conduction/nextcloud-vue` and MUST receive `manifest`,
`customComponents`, and `pageTypes` as props. The `App` component
MUST provide an `objectSidebarState` reactive channel via
`provide()` and MUST mount `<CnObjectSidebar>` in the `#sidebar`
slot driven by that channel.

#### Scenario: App.vue is Tier-4
- GIVEN `src/App.vue`
- WHEN reading the `<template>` block
- THEN the root element MUST be `<CnAppRoot>`
- AND a `#sidebar` slot MUST contain a `<CnObjectSidebar>`

### Requirement: REQ-TMV1-5 main.js MUST follow the mount-survivable bootstrap pattern

`src/main.js` MUST:

1. Import `bundledManifest from './manifest.json'`.
2. Import `customComponents from './customComponents.js'`.
3. Import `defaultPageTypes` from `@conduction/nextcloud-vue`.
4. Build vue-router routes from `manifest.pages[*].{id, route}`
   via a `routesFromManifest()` function (or equivalent inline
   construction) that uses a shallow-cloned `CnPageRenderer`
   (`{ ...CnPageRenderer }`) as each route's component.
5. Pass shallow-cloned `defaultPageTypes` and shallow-cloned
   `customComponents` to App.vue as props.
6. Mount on `#content` immediately, NOT inside the
   `loadTranslations` callback.

#### Scenario: main.js shallow-clones CnPageRenderer
- GIVEN `src/main.js`
- WHEN searching the source for `{ ...CnPageRenderer }`
- THEN the pattern MUST appear at least once

#### Scenario: main.js does not block mount on translations
- GIVEN `src/main.js`
- WHEN reading the source
- THEN the `new Vue(...).$mount('#content')` call MUST NOT be wrapped inside `loadTranslations(...)`'s callback

### Requirement: REQ-TMV1-6 The template MUST ship `src/customComponents.js` as the registry contract

`src/customComponents.js` MUST exist and MUST `export default` a
plain object. The object MAY be empty, MAY contain example
entries, but MUST always be a valid registry CnAppRoot can consume
without throwing. At least ONE example entry MUST be present so
the registry's role is demonstrated to first-time cloners.

#### Scenario: Registry exports a plain object
- GIVEN `src/customComponents.js`
- WHEN imported as `import registry from './customComponents.js'`
- THEN `typeof registry` MUST equal `"object"`
- AND `Object.keys(registry).length` MUST be at least 1

### Requirement: REQ-TMV1-7 webpack.config.js MUST alias `@nextcloud/axios$`

`webpack.config.js` MUST add an explicit alias for
`@nextcloud/axios$` resolving to the app's installed
`node_modules/@nextcloud/axios`. This prevents the lib's transitive
axios import from resolving to a separate copy when nested in the
lib's `node_modules/`.

#### Scenario: axios alias is present
- GIVEN `webpack.config.js`
- WHEN inspecting `webpackConfig.resolve.alias`
- THEN the key `'@nextcloud/axios$'` MUST be present
- AND its value MUST be an absolute path under the app's `node_modules`

### Requirement: REQ-TMV1-8 package.json MUST pin `@conduction/nextcloud-vue` to the published lib version

`package.json` MUST list `@conduction/nextcloud-vue` as a direct
dependency with a semver range that admits `1.0.0-beta.12` (the
published lib version with the Vue.extend frozen-component fix).
The legacy `^0.1.0-beta.3` floor MUST be replaced.

#### Scenario: package.json admits the published lib
- GIVEN `package.json`
- WHEN inspecting `dependencies['@conduction/nextcloud-vue']`
- THEN the value MUST be `"^1.0.0-beta.12"` or a semver range covering it

### Requirement: REQ-TMV1-9 README MUST document the manifest-first workflow

The template `README.md` MUST include a quickstart that:

1. Tells cloners to edit `src/manifest.json` to add pages
   (dashboard, index, detail, settings) WITHOUT writing a Vue
   file.
2. Explains that custom Vue components are only required for
   `type: "custom"` pages, registered via `src/customComponents.js`.
3. References hydra ADR-024 (or its eventual public link) as the
   architectural source of the convention.

#### Scenario: README mentions manifest as the page-add surface
- GIVEN `README.md`
- WHEN searching for "manifest.json" in the development / quickstart section
- THEN the file MUST contain language that directs cloners to edit `src/manifest.json` to add a page
- AND the file MUST contain language explaining `customComponents.js` is the escape hatch

### Requirement: REQ-TMV1-10 The template MUST provide an `npm run check:manifest` script

`package.json` MUST declare `"check:manifest": "node tests/validate-manifest.js"` (or equivalent invocation) as a `scripts` entry. This satisfies the fleet adoption spec's build-time validation gate (`hydra-gate-manifest-validation`).

#### Scenario: check:manifest exits zero on a clean template
- GIVEN a fresh clone with `npm install` complete
- WHEN running `npm run check:manifest`
- THEN the command MUST exit with status code 0
