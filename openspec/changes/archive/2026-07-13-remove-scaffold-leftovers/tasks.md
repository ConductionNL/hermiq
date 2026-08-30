# Tasks: remove-scaffold-leftovers

## 1. Backend: dead MCP scaffold

- [x] 1.1 Delete `lib/Mcp/ExampleToolProvider.php`.
- [x] 1.2 Delete `tests/Unit/Mcp/ExampleToolProviderTest.php`.
- [x] 1.3 Remove the `ExampleToolProvider` reference/comment in `tests/bootstrap-unit.php:38`;
      confirm no other test bootstrap relies on it. Updated the comment to name
      `HermiqToolProvider` (the class that actually consumes the `AbstractToolHandler`/
      `IMcpToolProvider` stubs now — confirmed via `lib/Mcp/HermiqToolProvider.php`).
- [x] 1.4 Confirm `lib/AppInfo/Application.php` has no registration for
      `ExampleToolProvider` (it currently does not — verify no regression during the
      edit). Confirmed: only `HermiqToolProvider` is registered
      (nc-native-tools task 2.1 already replaced it).

## 2. Backend: dead `example` schema

- [x] 2.1 Remove the `example` schema block from `lib/Settings/hermiq_register.json`
      (was `components.schemas.example`, lines 19-45).
- [x] 2.2 Bump `info.version` in `lib/Settings/hermiq_register.json` (0.10.1 → 0.10.2).
      Also bumped `appinfo/info.xml` `<version>` 0.1.51 → 0.1.52 so the register
      re-imports on next repair.
- [x] 2.3 Repair-step re-import verified at compile level: the edited register JSON
      is valid (`json_decode` + `check:register`/`check:json-strict` all PASS) and
      contains no `example` schema. Checking a *live* dev DB for orphaned `example`
      objects was NOT done — rule 8 forbids touching the running NC instance in this
      session; deferred to live verification (no `example` objects were ever expected
      per the proposal — the schema was never surfaced to real users outside the
      dead `/examples` page removed in task 3).

## 3. Frontend: dead pages + nav + component

- [x] 3.1 Remove the `Examples` and `ExampleDetail` entries from `src/manifest.json`
      `pages[]`.
- [x] 3.2 Remove the `Examples` entry from `src/manifest.json` `menu[]` (the `order: 30`
      main-nav item).
- [x] 3.3 Delete `src/views/CustomExample.vue`.
- [x] 3.4 Remove the `CustomExample` import + `example-modal` custom action + `CustomExample`
      component registration + the "example"-schema custom cell renderer from
      `src/registry.js`. Also deleted `src/modals/ExampleModal.vue` (confirmed pure
      scaffold — zero manifest actions reference `open-modal`/`example-modal`) and
      `src/cellRenderers/StatusBadge.vue` (confirmed pure scaffold — its own docblock
      says "scaffold demo"; its only binding was `appliesTo: { schema: 'example' }`).
      Updated `tests/registry.spec.js`'s `REQUIRED_KINDS` to drop `modal` and
      `cell-renderer` (mirroring the existing ADR-049 precedent for `widget` set by
      commit `10fdc34`) since those demo entries were the only reason those kinds
      were required — Hermiq's real modals are directly embedded in their owning
      custom pages, not registry-mediated, and no schema needs a custom cell-renderer.
- [x] 3.5 Remove the `CustomExample` import + registration from `src/customComponents.js`.
- [x] 3.6 (Not in original scope, found during HEAD re-verification per rule 2) Removed
      two additional scaffold leftovers that directly referenced the deleted `example`
      schema/route and would otherwise have been left dangling:
      - `lib/Listener/DeepLinkRegistrationListener.php` + its `Application.php`
        registration — registered a deep link for `schemaSlug: 'example'` pointing at
        the just-deleted `/apps/hermiq/#/examples/{uuid}` route. Its own backing spec
        (`openspec/specs/deep-linking/spec.md`) was the app-template's own
        `status: example` demo spec, never adapted to a real Hermiq schema — deleted
        both. Updated `lib/Listener/AgentRunRequestedListener.php`'s docblock (only
        remaining reference) to drop the now-dead "mirrors DeepLinkRegistrationListener"
        aside.
      - `src/store/store.js`'s `useObjectStore = createObjectStore('example', …)` and its
        `initializeStores()` boot helper — confirmed dead (never imported by `main.js`
        or any component; `main.js` boots the SPA without calling it). Removed both,
        left the real `useScheduleStore`/`useAgentStore` and the `useSettingsStore`
        re-export untouched.

## 4. Verify

- [x] 4.1 `composer phpcs` (lib scope) + PHPStan; PHPUnit the CI way — confirm no test
      references the removed classes. PHPStan: 0 errors (full `lib/`, 94 files).
      phpcs: 0 errors on every file touched by this change (the 43 pre-existing
      errors elsewhere — TenantModelPolicyController.php, RemoveLegacyLlmKeys.php,
      TenantModelPolicyService.php, Engine handlers, ProviderFactory.php — are
      untouched by this change and predate it). PHPUnit: 569 tests / 0 errors /
      0 failures (down from a 576-test baseline; the 7-test delta is exactly
      `ExampleToolProviderTest`'s removed test methods).
- [x] 4.2 ESLint / build the frontend — confirm no dangling import of the deleted
      `CustomExample.vue`. `npm run lint`: 0 errors (40 pre-existing `jsdoc/check-tag-names`
      warnings for the project's `@spec` tag convention, unrelated to this change).
      `npm run check:specs` (json-strict + manifest-v2 + register + registry): all PASS.
      `npm run build` was intentionally NOT run per instructions.
- [ ] 4.3 Verify live on NC + OR: the main nav no longer shows "Examples"; `/examples` and
      `/examples/:id` are gone; the `example` schema is absent from the hermiq register in
      OpenRegister's schema browser; every other page/route in `src/manifest.json`
      (Dashboard, Agents, Approvals, Memory, Sessions, Skills, AI features, Analytics,
      Tenant ops, MCP tools, Settings, Features & roadmap) still loads with 0 console
      errors (ADR-044 no-functionality-loss invariant — confirm nothing else broke).
      Live browser coverage deferred to the playwright-regression-coverage change —
      compile-level coverage (manifest-v2 Ajv schema validation, JSON-strict, register
      validation, registry validation, ESLint, PHPStan/phpcs/PHPUnit) all verified green
      above; rule 8 forbids deploying to or touching the running NC instance in this
      session.

## Acceptance criteria

- No reference to `ExampleToolProvider`, `CustomExample`, the `example` schema, or the
  `Examples`/`ExampleDetail` pages remains anywhere in `lib/`, `src/`, or `tests/`.
  Verified via repo-wide grep — zero hits outside `openspec/changes/*` (historical
  proposal docs, left untouched as project history), `README.md`, and
  `docs/src/pages/index.js` (the docs-site's own still-scaffold landing-page copy —
  out of this change's `lib/`/`src/`/`tests/` scope; flagged as a gap for
  beta-surface-alignment/a follow-up, not fixed here).
- Every real (non-scaffold) page continues to load without error after the manifest edit.
  Confirmed at compile level (Ajv v2 manifest schema validation PASS); live-load
  confirmation deferred per rule 8 (task 4.3).

## Quality reminders

- SPDX unaffected (deletions only, no new files). Confirmed — no new files created.
- No sed/awk/scripts on code — Edit tool only; delete via the Write/Edit tool's file
  removal path or direct `rm` on files being fully deleted (not partial edits).
  Followed: all edits used the Edit tool; deletions used `rm` on whole files only.
- Re-check `lib/Settings/hermiq_register.json` is valid JSON after the edit (per the
  JSON-merge-revalidate gotcha). Confirmed via `php -r 'json_decode(...)'` and
  `npm run check:json-strict`.
