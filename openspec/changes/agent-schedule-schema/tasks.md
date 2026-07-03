# Tasks: agent-schedule-schema

## 1. Declare the Schedule schema (register patch)

- [x] 1.1 Add a `Schedule` entry under `components.schemas` in `lib/Settings/hermiq_register.json` (title, slug, icon, version, Schema.org `Schedule` type, `x-openregister`).
- [x] 1.2 Declare core properties `name` (string, required) and `agentId` (string uuid, required, reference to OpenRegister `Agent`).
- [x] 1.3 Declare `kind` as a required enum `once`|`interval`|`cron`.
- [x] 1.4 Declare trigger fields `cronExpr` (string), `intervalMinutes` (integer, minimum 1), `runAt` (datetime).
- [x] 1.5 Declare conditional requirements so `kind` selects its trigger field (cron→`cronExpr`, interval→`intervalMinutes`, once→`runAt`).
- [x] 1.6 Declare `prompt` (string, optional), `deliver` (required enum `talk`|`notification`|`none`), `enabled` (boolean, required, default true).
- [x] 1.7 Declare `repeat` (optional object `{times:int, completed:int}`) and derived fields `nextRun` (datetime), `lastStatus` (string), `lastError` (string).
- [x] 1.8 Re-validate `hermiq_register.json` as well-formed JSON and confirm the existing `example` schema is untouched (union import, no regression).

## 2. Verify import and persistence

- [ ] 2.1 Import the register via the repair step (`ConfigurationService::importFromApp()`) and confirm the `Schedule` schema lands in the `hermiq` register.
- [ ] 2.2 Persist a valid `Schedule` object per each `kind` (cron / interval / once) via OpenRegister and confirm validation passes.
- [ ] 2.3 Confirm invalid saves are rejected: missing `agentId`, `kind=cron` without `cronExpr`, `kind=interval` with `intervalMinutes` < 1, `kind=once` without `runAt`.

## Acceptance criteria

- A `Schedule` OpenRegister schema exists in the `hermiq` register with all properties from the spec.
- `kind` correctly gates the required trigger field (cron / interval / once).
- `enabled` defaults to `true`; `owner`/`organisation` come from `ObjectEntity`, not schema properties.
- The change adds no PHP, controller, service, or API surface.
- Existing schemas in the register are unchanged after import.

## Quality reminders

- Config-only change — do not add a Service class or write path; the dispatcher is the downstream `agent-schedule-dispatcher` change.
- Use the Edit tool (not sed/awk/scripts) to modify `hermiq_register.json`; re-parse the JSON after editing.
- Test schema validation against a live OpenRegister import before marking tasks done.
- Keep i18n keys (schema titles/descriptions) in English source.
