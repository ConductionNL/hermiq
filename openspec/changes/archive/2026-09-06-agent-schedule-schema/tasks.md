# Tasks: agent-schedule-schema

## 1. Declare the Schedule schema (register patch)

- [x] 1.1 Add a `Schedule` entry under `components.schemas` in `lib/Settings/hermiq_register.json` (title, slug, icon, version, Schema.org `Schedule` type, `x-openregister`).
- [x] 1.2 Declare core properties `name` (string, required) and `agentId` (string uuid, required, reference to OpenRegister `Agent`).
- [x] 1.3 Declare `kind` as a required enum `once`|`interval`|`cron`.
- [x] 1.4 Declare trigger fields `cronExpr` (string), `intervalMinutes` (integer, minimum 1), `runAt` (datetime).
- [x] 1.5 Conditional requirements (cron→`cronExpr`, interval→`intervalMinutes`, once→`runAt`): OpenRegister's schema importer rejects JSON-Schema `allOf`/`if`/`then` (`SchemaMapper::loadSchema` expects a string identifier). Per the design's documented fallback, the trigger fields are declared **optional** at the schema level and the kind→trigger rule is enforced downstream in the `agent-schedule-dispatcher` change (and the future create-schedule UI).
- [x] 1.6 Declare `prompt` (string, optional), `deliver` (required enum `talk`|`notification`|`none`), `enabled` (boolean, required, default true).
- [x] 1.7 Declare `repeat` (optional object `{times:int, completed:int}`) and derived fields `nextRun` (datetime), `lastStatus` (string), `lastError` (string).
- [x] 1.8 Re-validate `hermiq_register.json` as well-formed JSON and confirm the existing `example` schema is untouched (union import, no regression).

## 2. Verify import and persistence

- [x] 2.1 Import the register via the repair step (`ConfigurationService::importFromApp()`): verified live — `Hermiq Register` (id 2428) + `Schedule` schema (slug `schedule`, id 4328) imported cleanly on NC 34 + OpenRegister 0.2.17.
- [x] 2.2 Persist a valid `Schedule` object via OpenRegister: verified — a `kind=cron` Schedule saved via `POST /api/objects/hermiq/schedule` (HTTP 200, object `ba4e339f…`).
- [x] 2.3 Confirm invalid saves are rejected: verified — a Schedule missing required fields returns HTTP 400 "The required properties (name, agentId, kind, deliver) are missing." (kind→trigger conditional is enforced downstream, not at schema level — see 1.5.)

## Acceptance criteria

- A `Schedule` OpenRegister schema exists in the `hermiq` register with all properties from the spec.
- `kind` gates the required trigger field (cron / interval / once) — enforced downstream in the dispatcher/UI, since OpenRegister's importer does not accept declarative `if`/`then` conditionals.
- `enabled` defaults to `true`; `owner`/`organisation` come from `ObjectEntity`, not schema properties.
- The change adds no PHP, controller, service, or API surface.
- Existing schemas in the register are unchanged after import.

## Quality reminders

- Config-only change — do not add a Service class or write path; the dispatcher is the downstream `agent-schedule-dispatcher` change.
- Use the Edit tool (not sed/awk/scripts) to modify `hermiq_register.json`; re-parse the JSON after editing.
- Test schema validation against a live OpenRegister import before marking tasks done.
- Keep i18n keys (schema titles/descriptions) in English source.
