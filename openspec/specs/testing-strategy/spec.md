# testing-strategy Specification

## Purpose
TBD - created by archiving change playwright-regression-coverage. Update Purpose after archive.
## Requirements
### Requirement: The default Playwright regression project MUST run at least one real spec
The system's `tests/e2e/` MUST contain at least one Playwright spec, included in
`playwright.config.ts`'s default (`chromium`) project, that drives a real UI flow and
makes assertions — not a documentation-screenshot skeleton excluded via `testIgnore`.

#### Scenario: A PR pipeline runs the default regression project
- **GIVEN** `npx playwright test --project chromium` is run against a live Hermiq
  instance
- **WHEN** the run completes
- **THEN** at least one spec file MUST have executed real assertions (not zero matched
  test files)

### Requirement: State-mutating controllers with no test coverage MUST gain unit tests
`TenantOpsController`, `SkillMarketplaceController`, and `MemoryController` MUST each have a PHPUnit test file covering their public methods' happy path and at least one error path (403/401/400), per ADR-008.

#### Scenario: A reviewer checks controller test coverage
- **GIVEN** `tests/Unit/Controller/`
- **WHEN** `TenantOpsController`, `SkillMarketplaceController`, and `MemoryController` are
  checked for a corresponding `*ControllerTest.php`
- **THEN** each MUST have one, with at least 3 test methods per ADR-008's minimum

