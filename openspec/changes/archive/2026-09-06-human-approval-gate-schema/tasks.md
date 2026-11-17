# Tasks: human-approval-gate-schema

## 1. Declare the Approval schema (register patch)

- [x] 1.1 Add an `Approval` entry under `components.schemas` in `lib/Settings/hermiq_register.json` (title, slug `approval`, icon, version, `x-openregister`).
- [x] 1.2 Declare `status` as a required enum `pending`|`approved`|`denied` (the Art. 14 state machine).
- [x] 1.3 Declare `scheduleId` (string uuid, required), `agentId` (string uuid, required), `prompt` (string, optional), `requestedAt` (datetime, required).
- [x] 1.4 Declare the decision fields `decidedAt` (datetime), `decidedBy` (string uid), `reason` (string) — written by the decision endpoint, not the creating dispatcher.
- [x] 1.5 Declare the resolved reviewer fields `reviewer` (string uid or group id) and `reviewerType` (enum `user`|`group`) — copied from the gated Schedule so the decision is routed to a party distinct from the owner.

## 2. Declare the TenantControl (kill-switch) schema

- [x] 2.1 Add a `TenantControl` entry under `components.schemas` (title, slug `tenantcontrol`, icon, version, `x-openregister`).
- [x] 2.2 Declare `engaged` (boolean, required, default false) and `reason` (string), `engagedAt` (datetime), `engagedBy` (string uid).
- [x] 2.3 Do NOT declare `organisation`/`owner` as properties — tenant scope comes from `ObjectEntity`; keep the schema flat (no `if`/`then` conditionals — the importer rejects them).

## 3. Extend the Schedule schema

- [x] 3.1 Add an optional boolean `requiresApproval` (default `false`) to the existing `Schedule` schema; leave all other `Schedule` properties untouched.
- [x] 3.2 Add optional `reviewer` (string uid or group id) and `reviewerType` (enum `user`|`group`, default `user`) to `Schedule`; empty `reviewer` means the dispatcher defaults to the owner (backward compatible).

## 4. Validate import and persistence

- [x] 4.1 Re-validate `hermiq_register.json` as well-formed JSON and confirm `example`, `Schedule`, and all other existing schemas are unchanged (union import, no regression; re-parse after the merge).
- [x] 4.2 Import the register via the repair step (`ConfigurationService::importFromApp()`) against live OpenRegister and confirm `Approval` + `TenantControl` schemas are created cleanly.
- [x] 4.3 Verified live (NC34+OR0.2.17): pending `Approval` + engaged `TenantControl` persist; invalid `status='maybe'` → HTTP 400; `Schedule` without `requiresApproval` defaults false. — originally: Persist a valid `Approval` (`status=pending`) and a valid `TenantControl` (`engaged=true`) via OpenRegister; confirm an invalid `status` enum and a `Schedule` created without `requiresApproval` (defaults to `false`) behave as specified.

## Acceptance criteria

- An `Approval` OpenRegister schema exists with the `pending`/`approved`/`denied` state machine plus `scheduleId`, `agentId`, `prompt`, `requestedAt`, `decidedAt`, `decidedBy`, `reason`, `reviewer`, `reviewerType`.
- A `TenantControl` OpenRegister schema exists with `engaged` (default false), `reason`, `engagedAt`, `engagedBy`; tenant scope comes from `ObjectEntity.organisation`, not a schema property.
- The `Schedule` schema gains optional `requiresApproval` (default `false`), `reviewer`, and `reviewerType` (default `user`) fields; existing ungated schedules are behaviorally unchanged and an empty reviewer defaults to the owner.
- The change adds no PHP, controller, service, or API surface.
- Existing schemas in the register are unchanged after import (no regression).

## Quality reminders

- Config-only change — do NOT add a Service class, controller, or write path; enforcement is the downstream `human-approval-gate-enforcement` change.
- Use the Edit tool (not sed/awk/scripts) to modify `hermiq_register.json`; re-parse the JSON after editing (a merge can silently dup-keys JSON).
- Keep schemas flat — OpenRegister's importer rejects declarative `if`/`then`/`allOf` conditionals; cross-field rules are enforced downstream.
- Test schema validation against a live OpenRegister import before marking tasks done.
- Keep i18n keys (schema titles/descriptions) in English source; use NIL UUID / `<angle-bracket>` placeholders in any seed/example data.
