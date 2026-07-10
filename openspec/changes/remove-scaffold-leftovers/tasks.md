# Tasks: remove-scaffold-leftovers

## 1. Backend: dead MCP scaffold

- [ ] 1.1 Delete `lib/Mcp/ExampleToolProvider.php`.
- [ ] 1.2 Delete `tests/Unit/Mcp/ExampleToolProviderTest.php`.
- [ ] 1.3 Remove the `ExampleToolProvider` reference/comment in `tests/bootstrap-unit.php:38`;
      confirm no other test bootstrap relies on it.
- [ ] 1.4 Confirm `lib/AppInfo/Application.php` has no registration for
      `ExampleToolProvider` (it currently does not — verify no regression during the
      edit).

## 2. Backend: dead `example` schema

- [ ] 2.1 Remove the `example` schema block from `lib/Settings/hermiq_register.json`
      (currently `components.schemas.example`, lines ~19-45).
- [ ] 2.2 Bump `info.version` in `lib/Settings/hermiq_register.json`.
- [ ] 2.3 Verify the repair step re-imports the register cleanly with the schema removed
      (per `reference_or-register-import-via-repair-step` gotchas — re-import, don't
      hand-edit already-imported OR state). Check for any existing `example` objects in a
      dev DB before removing; if any exist, log rather than silently drop.

## 3. Frontend: dead pages + nav + component

- [ ] 3.1 Remove the `Examples` and `ExampleDetail` entries from `src/manifest.json`
      `pages[]`.
- [ ] 3.2 Remove the `Examples` entry from `src/manifest.json` `menu[]` (the `order: 30`
      main-nav item).
- [ ] 3.3 Delete `src/views/CustomExample.vue`.
- [ ] 3.4 Remove the `CustomExample` import + `example-modal` custom action + `CustomExample`
      component registration + the "example"-schema custom cell renderer from
      `src/registry.js`.
- [ ] 3.5 Remove the `CustomExample` import + registration from `src/customComponents.js`.

## 4. Verify

- [ ] 4.1 `composer phpcs` (lib scope) + PHPStan; PHPUnit the CI way — confirm no test
      references the removed classes.
- [ ] 4.2 ESLint / build the frontend — confirm no dangling import of the deleted
      `CustomExample.vue`.
- [ ] 4.3 Verify live on NC + OR: the main nav no longer shows "Examples"; `/examples` and
      `/examples/:id` are gone; the `example` schema is absent from the hermiq register in
      OpenRegister's schema browser; every other page/route in `src/manifest.json`
      (Dashboard, Agents, Approvals, Memory, Sessions, Skills, AI features, Analytics,
      Tenant ops, MCP tools, Settings, Features & roadmap) still loads with 0 console
      errors (ADR-044 no-functionality-loss invariant — confirm nothing else broke).

## Acceptance criteria

- No reference to `ExampleToolProvider`, `CustomExample`, the `example` schema, or the
  `Examples`/`ExampleDetail` pages remains anywhere in `lib/`, `src/`, or `tests/`.
- Every real (non-scaffold) page continues to load without error after the manifest edit.

## Quality reminders

- SPDX unaffected (deletions only, no new files).
- No sed/awk/scripts on code — Edit tool only; delete via the Write/Edit tool's file
  removal path or direct `rm` on files being fully deleted (not partial edits).
- Re-check `lib/Settings/hermiq_register.json` is valid JSON after the edit (per the
  JSON-merge-revalidate gotcha).
