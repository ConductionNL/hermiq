## 1. Build

- [ ] 1.1 Department configuration: the admin marks Nextcloud groups as departments, and an agent names the department it runs for.
- [ ] 1.2 A per-department switch next to the organisation kill-switch; dispatch refuses and records runs of agents in a department that is off.
- [ ] 1.3 An evidence download per department from the audit trail: who switched it off, when, and each refused run.
- [ ] 1.4 The "Per afdeling" list on the approvals page (HmGoedkeuringen), with state, agent count, last run and the download button.
- [ ] 1.5 Unit tests in `tests/Unit/` for the switch, the refused run, other departments unaffected, and the evidence content.

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
