---
kind: code
---

# Proposal: playwright-regression-coverage

# Why

Hermiq has 18 synced specs under `openspec/specs/` totaling 66 `#### Scenario:` blocks
(counted via `grep -rc '#### Scenario' openspec/specs/*/spec.md`), all describing
already-implemented (archived/synced) behavior. None of them carry an `@e2e` tag —
neither a real `@e2e` pointer nor a reason-bearing `@e2e exclude` (the one place `@e2e
exclude` appears in the whole repo is
`openspec/changes/ai-feature-governance-register/specs/ai-feature-governance/spec.md`,
a change not yet synced into `openspec/specs/`). Per hydra ADR-020's gate-19
(`hydra-gate-e2e-coverage`), a scenario that ships without either an e2e reference or an
excuse is exactly the "phantom green" case that gate exists to catch — it just hasn't
been run against these specs yet.

Concretely, `tests/e2e/` (`playwright.config.ts:testDir`) contains exactly one spec file:
`docs-screenshots.spec.ts`. Its own header says it plainly: "This spec is *not* a
regression test... The tests below are SKELETONS — selectors are TODOs." And
`playwright.config.ts`'s `chromium` project — the one PR pipelines actually run —
explicitly excludes it: `testIgnore: ['**/docs-screenshots.spec.ts']`. The net effect:
**the default regression Playwright project has zero test files to run.** Every UI flow
across dashboard, agents, schedules, approvals, memory, skills marketplace, settings,
and tenant ops is validated only by PHPUnit calling controllers directly (bypassing
Nextcloud's router/middleware stack) or by manual smoke-testing — never by a browser
actually driving the app.

Compounding this, ten of sixteen controllers under `lib/Controller/` have no PHPUnit
coverage at all (`tests/Unit/Controller/` + `tests/unit/Controller/` only cover
`AiFeatureController`, `ApprovalController`, `RunHistoryController`, `RunNowController`,
`TenantControlController`, `SettingsController`). The uncovered set includes
security-relevant, state-mutating surfaces:

- `lib/Controller/TenantOpsController.php:102` — `auditExport()`, an audit-trail export
  endpoint (GDPR/AVG-adjacent data-export surface).
- `lib/Controller/SkillMarketplaceController.php:79,118,170` — `installFromSource()`,
  `approve()`, `publish()` — installs and publishes agent skills, a supply-chain-style
  write path.
- `lib/Controller/MemoryController.php:81-241` — six methods reading/writing per-agent
  memory, user profiles, and sessions.
- `lib/Controller/DashboardController.php`, `SkillController.php`, `PreferencesController.php`,
  `MetricsController.php`, `HealthController.php`, `SetupController.php` — no test file at
  all.

# What Changes

- Stand up at least one real Playwright regression spec under `tests/e2e/` that drives an
  actual UI flow end-to-end (login → navigate → assert rendered state), included in the
  default `chromium` project (not excluded like `docs-screenshots.spec.ts`). Start with
  the highest-value flow: Dashboard load + Agent list render (covers `dashboard-page` and
  part of `agent-management-ui` specs).
- Add PHPUnit coverage (≥3 methods per ADR-008) for the highest-risk uncovered
  controllers: `TenantOpsController`, `SkillMarketplaceController`, `MemoryController` —
  prioritized because they mutate state or export data, not just read it.
- Not BREAKING: test-only change. No production code paths change.

# Impact

- Affected: `tests/e2e/` (new spec), `playwright.config.ts` (if a new project boundary is
  needed), `tests/Unit/Controller/` (three new test files).
- Explicitly out of scope for this change (tracked as follow-up, not silently dropped):
  full e2e coverage of all 66 scenarios, and unit coverage for the remaining uncovered
  controllers (`DashboardController`, `SkillController`, `PreferencesController`,
  `MetricsController`, `HealthController`, `SetupController`). This change establishes
  the pattern and closes the highest-risk gaps; it does not claim full closure.
