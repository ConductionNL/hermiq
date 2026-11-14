## 1. Build

- [x] 1.1 Department configuration: the admin marks Nextcloud groups as departments, and an agent names the department it runs for.
- [x] 1.2 A per-department switch next to the organisation kill-switch; dispatch refuses and records runs of agents in a department that is off.
  - Built: the `departmentcontrol` schema (admin-only writes) marks a group as a department, Agent gains `department`; `DepartmentSwitch` reads them. `POST /api/department-control/{group}/toggle` (admins only, beside the tenant-control toggle) records who, when and why. Scheduled runs (`ScheduleService::dispatch` and `gateFor`) and flow runs (`FlowAgentRunService`) refuse an agent of an off department after the kill-switch, recorded as `skipped_department_off`.
- [x] 1.3 An evidence download per department from the audit trail: who switched it off, when, and each refused run.
  - Built: every switch writes a `department-switch` audit entry on the department's object. `DepartmentEvidence` reads those and the `run` / `agent-run` entries with status `skipped_department_off` of the department's agents. `GET /api/department-control/{group}/evidence` (admins only) sends them as a JSON attachment; `GET /api/department-control` lists each department with state, who and when, agent count, last run and runs refused since the switch-off.
- [ ] 1.4 The "Per afdeling" list on the approvals page (HmGoedkeuringen), with state, agent count, last run and the download button.
- [ ] 1.5 Unit tests in `tests/Unit/` for the switch, the refused run, other departments unaffected, and the evidence content.
  - Done for the switch, the refused run and other departments: `DepartmentSwitchTest`, `DepartmentControlControllerTest`, `ScheduleDepartmentGateTest`. The evidence content waits for 1.3.

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
