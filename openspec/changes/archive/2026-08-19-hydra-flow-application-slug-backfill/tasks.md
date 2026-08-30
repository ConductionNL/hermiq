# Tasks: hydra-flow-application-slug-backfill

## Implementation Tasks

### Task 1: Tag the seeded Hydra Triage flow with its application slug, on create and on backfill
- **spec_ref**: `openspec/changes/hydra-flow-application-slug-backfill/specs/hydra-flow-application-slug/spec.md#requirement-the-seeded-hydra-triage-flow-declares-its-owning-application-req-001`
- **spec_ref**: `openspec/changes/hydra-flow-application-slug-backfill/specs/hydra-flow-application-slug/spec.md#requirement-an-already-seeded-flow-with-no-application-slug-is-backfilled-req-002`
- **spec_ref**: `openspec/changes/hydra-flow-application-slug-backfill/specs/hydra-flow-application-slug/spec.md#requirement-a-previously-set-application-slug-is-never-overwritten-req-003`
- **files**: `lib/Repair/SeedHydraTriageFlow.php`
- **acceptance_criteria**:
  - GIVEN no "Hydra Triage" flow exists WHEN `run()` seeds one THEN the inserted `Flow` has `applicationSlug === 'hydra-console'`
  - GIVEN a "Hydra Triage" flow already exists with an empty `applicationSlug` WHEN `run()` executes THEN `FlowMapper::update()` is called once with `applicationSlug` set to `'hydra-console'` and every other field on the entity unchanged
  - GIVEN a "Hydra Triage" flow already exists with a non-empty `applicationSlug` WHEN `run()` executes THEN neither `insert()` nor `update()` is called
- [x] Implement
- [x] Test

### Task 2: Keep the declaration-only OpenRegister stubs in sync with the new accessor
- **spec_ref**: `openspec/changes/hydra-flow-application-slug-backfill/specs/hydra-flow-application-slug/spec.md#requirement-the-seeded-hydra-triage-flow-declares-its-owning-application-req-001`
- **files**: `tests/Stubs/Db/Flow.php`, `tests/Stubs/Db/FlowMapper.php`
- **acceptance_criteria**:
  - GIVEN standalone static analysis with OpenRegister not installed WHEN `SeedHydraTriageFlow` calls `getApplicationSlug()`/`setApplicationSlug()` THEN PHPStan/Psalm resolve both against the stub's `@method` tags
  - GIVEN the stub `FlowMapper` WHEN `SeedHydraTriageFlow` calls `update()` THEN the stub declares it with the same signature shape as `insert()` (both inherited from `QBMapper` on the real class)
- [x] Implement
- [x] Test

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/Repair/SeedHydraTriageFlowTest.php`)
- No new/changed API endpoints — N/A for Newman/Postman
- No UI changes — N/A for Playwright browser tests
- All tests pass (`composer test`)
- No user-facing feature — N/A for `docs/` updates
- No new user-facing strings — N/A for i18n
- `openspec validate` passes

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (unit tests exercise every GIVEN/WHEN/THEN in the spec delta; no UI/API surface exists to test manually)
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [x] N/A — no new/changed API endpoints (Newman/Postman)
- [x] N/A — no UI changes (Playwright)
- [x] All tests pass (`composer test`)

## Documentation (company-wide ADR-010)
- [x] N/A — no user-facing feature; internal repair-step data field only

## i18n (company-wide ADR-005)
- [x] N/A — no new user-facing strings
