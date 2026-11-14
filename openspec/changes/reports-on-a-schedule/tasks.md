## 1. Build

- [x] 1.1 A report subscription schema (person, report, weekly or monthly, last sent) in the hermiq register under `lib/Settings/`.
- [ ] 1.2 Subscribe and cancel on each card of the Reports page in `src/manifest.json`.
- [x] 1.3 A background job in `lib/BackgroundJob/` that sends due reports as notifications with a link to the period.
- [x] 1.4 Unit tests in `tests/Unit/` for the due calculation and the cancelled case.

Build note: the schema is `reportsubscription` in `lib/Settings/hermiq_register.json` (person, report, weekly or monthly, status, last sent); a person reads, changes and cancels only their own. `ReportSubscriptionJob` (hourly) sends every due one as a `report_due` notification that links to the report with `from` and `to` for its week or month, and records `lastSent`. A subscription whose `person` is not its owner is skipped, so nobody can subscribe somebody else. Left: 1.2, because the Reports page's cards (`CnReportsPage` in `@conduction/nextcloud-vue`) take no per-card actions, so a subscribe button on each card needs a library change first; and the AI oversight page does not yet read `from`/`to`, so the link opens the report but not yet narrowed to the period.

## 2. Hand-over

- [x] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
  - The new methods carry `@spec openspec/changes/reports-on-a-schedule/...`; both scenarios are background-job timing a browser cannot wait out, so each carries an `@e2e exclude` naming the unit test that asserts it.
