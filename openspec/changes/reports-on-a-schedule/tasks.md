## 1. Build

- [ ] 1.1 A report subscription schema (person, report, weekly or monthly, last sent) in the hermiq register under `lib/Settings/`.
- [ ] 1.2 Subscribe and cancel on each card of the Reports page in `src/manifest.json`.
- [ ] 1.3 A background job in `lib/BackgroundJob/` that sends due reports as notifications with a link to the period.
- [ ] 1.4 Unit tests in `tests/Unit/` for the due calculation and the cancelled case.

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
