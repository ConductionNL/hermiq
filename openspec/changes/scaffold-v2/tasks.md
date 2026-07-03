# scaffold-v2 Tasks

## Phase 1: OpenSpec change scaffold

- [x] Create `openspec/changes/scaffold-v2/` directory structure
- [x] Write `proposal.md`
- [x] Write `design.md`
- [x] Write `specs/scaffold-v2/spec.md`
- [x] Write `tasks.md` (this file)

## Phase 2: Transform src/manifest.json to v2

- [x] Update `$schema` to v2 schema URL
- [x] Bump `version` to `0.2.0`
- [x] Dashboard page: collapse `config.widgets[]` + `config.layout[]` into top-level `widgets[]`
- [x] Items index page: add explicit `widgets[]` with `object-table` built-in
- [x] ItemDetail page: lift `config.sidebarProps.tabs[].widgets[]` to top-level `widgets[]` with `slot: "sidebar"` + `tabGroup`
- [x] Settings page: flatten `config.sections[].widgets[]` to top-level `widgets[]` with `slot: "section:version"`
- [x] FeaturesRoadmap: add `_note` field

## Phase 3: Add src/registry.js and example components

- [x] Create `src/widgets/ExampleWidget.vue`
- [x] Create `src/modals/ExampleModal.vue`
- [x] Create `src/formFields/EmailField.vue`
- [x] Create `src/cellRenderers/StatusBadge.vue`
- [x] Create `src/registry.js` with all five kinds

## Phase 4: Wire up main.js and App.vue

- [x] Update `src/main.js` to import and pass `registry` prop
- [x] Update `src/App.vue` to accept and forward `registry` prop

## Phase 5: Update README.md

- [x] Add "Manifest v2 ready" subsection under Development

## Phase 6: Add test scripts

- [x] Create `tests/manifest-v2.spec.js`
- [x] Create `tests/registry.spec.js`
- [x] Add `check:manifest-v2` to `package.json` scripts
- [x] Extend `check:specs` to include `check:manifest-v2`

## Phase 7: Verification

- [x] `npm run lint` — zero errors
- [x] `npm run check:manifest-v2` — passes
- [x] `npm run check:specs` — all pass
- [x] `npm run build` — succeeds
