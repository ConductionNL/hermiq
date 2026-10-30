## 1. Server

- [ ] 1.1 Add a `labels` map to `lib/actions.seed.json` with an English label and one-sentence
      description per seeded action and the Dutch strings in `l10n/nl.json` and `l10n/nl.js`; fix the
      seed's `$comment`, which points at an admin settings page that does not exist (design D4).
      Verify: a unit test that every seeded action has a label.
- [ ] 1.2 Add `lib/Controller/ActionMatrixController.php` with `index()` (`GET /api/actions`) and
      `update(string $action)` (`PUT /api/actions/{action}`) in `appinfo/routes.php`, admin-only by
      default (no `#[NoAdminRequired]`), validating action and groups and keeping `admin` before
      calling `ActionAuthService::setMatrix()` (REQ-ACTMX-002, design D2, D3). Verify:
      `ActionMatrixControllerTest` for list, a valid change, an unknown action, an unknown group,
      admin kept, and the reset.

## 2. Screen

- [ ] 2.1 Add the section "Rechten per actie" to the `TenantOps` page in `src/manifest.json` as a
      widget shown only to Nextcloud administrators: a table of action label, description and groups
      (`NcSelect` with `inputLabel` for groups), a changed mark, save and reset per action, and the
      sentence that the matrix applies to every organisation (REQ-ACTMX-001). Verify: Vitest tests for
      rendering, a change, a reset and the hidden case; `npm run lint` exits 0.

## 3. Verification

- [ ] 3.1 `@spec` tags on every new method (gate 16) and an e2e test in
      `tests/e2e/action-matrix.spec.ts` for the scenarios without an `@e2e exclude` (gate 19).
- [ ] 3.2 When this change is archived, the `## Purpose` of `openspec/specs/action-authorization`
      drops "No screen edits the action matrix yet".
