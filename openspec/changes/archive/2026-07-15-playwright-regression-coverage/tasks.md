# Tasks: playwright-regression-coverage

## 1. Frontend: first real Playwright regression spec

- [x] 1.1 Add `tests/e2e/dashboard-and-agents.spec.ts` — log in, navigate to the Hermiq
      app, assert the Dashboard renders (per `openspec/specs/dashboard-page/spec.md`),
      navigate to the Agents list, assert it renders without console errors.
- [x] 1.2 Confirm `playwright.config.ts`'s `chromium` project (the default regression
      project) picks this file up — it must NOT be added to `testIgnore`.
- [x] 1.3 Add stable `data-testid` attributes to the touched components if missing (use
      `/journeydoc-instrument` per ADR-030 if this repo already uses it) so selectors
      aren't brittle CSS-class matches.
- [x] 1.4 Reference this spec from the relevant scenarios in `openspec/specs/dashboard-page/spec.md`
      and `openspec/specs/agent-management-ui/spec.md` via `@e2e tests/e2e/dashboard-and-agents.spec.ts`
      (or the fleet's chosen tag convention — check `hydra-gate-e2e-coverage`'s expected
      format before finalizing).

## 2. Backend: unit coverage for uncovered security-relevant controllers

- [x] 2.1 Add `tests/Unit/Controller/TenantOpsControllerTest.php` — cover `quota()` and
      `auditExport()` (happy path + at least one error path: unauthenticated/forbidden
      per ADR-008).
- [x] 2.2 Add `tests/Unit/Controller/SkillMarketplaceControllerTest.php` — cover
      `installFromSource()`, `approve()`, `publish()` (happy path + rejection path for
      each).
- [x] 2.3 Add `tests/Unit/Controller/MemoryControllerTest.php` — cover `memory()`,
      `addMemory()`, and at least one of `userProfiles()`/`sessions()`/`consolidate()`/`recall()`.

## 3. Verify

- [x] 3.1 `composer phpunit` (unit suite) — new tests pass.
- [x] 3.2 `npx playwright test --project chromium` against a running dev instance — the
      new spec passes and the project is no longer empty.
- [x] 3.3 `composer check:strict`.

## Acceptance criteria

- `npx playwright test --project chromium` runs at least one real assertion-bearing spec
  (not the docs-capture skeleton).
- `TenantOpsController`, `SkillMarketplaceController`, `MemoryController` each have a
  PHPUnit test file with ≥3 test methods covering happy + error paths.

## Quality reminders

- Playwright UI-only; do not substitute API-direct calls for what should be a real
  browser-driven assertion (per the fleet's "Playwright UI-only, Newman API" rule).
- No sed/awk/scripts on code — Edit tool only.
- SPDX header on every new PHP/TS test file.
