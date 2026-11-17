# Design: agent-schedule-schema

## Context

Hermiq is a thin Nextcloud app that owns no database tables — all persistence goes
through OpenRegister's `ObjectService` against declarative schemas declared in the
app's schema register (ADR-001). The `hermiq` register currently ships only an
`example` schema (`lib/Settings/hermiq_register.json`), imported via
`ConfigurationService::importFromApp()` in the repair step.

The `agent-schedule` feature was split under ADR-032 into a config head
(`agent-schedule-schema`, this change) and a code change
(`agent-schedule-dispatcher`). This change declares the `Schedule` schema only. No
imperative code is added here — a schema is data, and OpenRegister validates and
persists it.

## Goals / Non-Goals

**Goals:**
- Declare a single `Schedule` schema in `lib/Settings/hermiq_register.json` covering
  identity (`name`), agent binding (`agentId`), trigger (`kind` + `cronExpr` /
  `intervalMinutes` / `runAt`), payload (`prompt`), delivery (`deliver`),
  enablement (`enabled`), repeat control (`repeat`), and derived run-state
  (`nextRun`, `lastStatus`, `lastError`).
- Express the cron/interval/once conditional-requirement rules declaratively.
- Keep the change config-only and union-import-safe (existing `example` schema
  untouched).

**Non-Goals:**
- Any PHP: no `ScheduleService`, no `ScheduleTask`, no controller. Those belong to
  the downstream `agent-schedule-dispatcher` change.
- Next-run computation, timezone anchoring, and firing logic — downstream.
- Frontend UI for creating schedules — a later change.
- Memory/skills schemas, delivery adapters, approval/kill-switch — out of scope.

## Decisions

**Declarative schema in the register (ADR-001 / ADR-031).** The `Schedule` schema is
pure data declared in `components.schemas.Schedule` of the OpenAPI-3.0.0 register
document. This is the declarative side of ADR-031 — no derived-field/lifecycle/
aggregation logic lives here; it is a plain object schema. There is no imperative
code in this change at all.

**Conditional requirements.** `kind` is an enum (`once`|`interval`|`cron`). The
trigger fields are conditionally required by `kind` — expressed with the register's
declarative conditional-requirement mechanism (e.g. an `oneOf`/`if`-`then` block or
`x-openregister` conditional) so that `cronExpr` is required only when `kind=cron`,
`intervalMinutes` (integer, minimum 1) only when `kind=interval`, and `runAt` only
when `kind=once`.

**Reference to Agent.** `agentId` is declared as a required string UUID referencing
the OpenRegister `Agent` entity (schema-level reference annotation) so the dispatcher
can resolve the bound agent. Hermiq does not own the `Agent` schema — it lives in
OpenRegister (ADR-001).

**Tenant scoping is inherited.** `owner` and `organisation` are NOT schema
properties; OpenRegister's `ObjectEntity` supplies them automatically, and the
dispatcher impersonates `owner` at fire time. This keeps a single write-path and the
multi-tenant model (ADR-001 / ADR-004).

**Derived fields are declared but user-unset.** `nextRun`, `lastStatus`, `lastError`
are declared so a schedule can carry run state, but they are written only by the
dispatcher. `enabled` defaults to `true`.

## Seed Data (ADR-001)

Realistic example `Schedule` objects across general organisation types. `agentId`
placeholders use the NIL UUID `00000000-0000-0000-0000-000000000000` — never a
realistic-looking UUID or secret.

```json
[
  {
    "name": "Daily permit-status briefing",
    "agentId": "00000000-0000-0000-0000-000000000000",
    "kind": "cron",
    "cronExpr": "0 8 * * *",
    "prompt": "Summarise permit applications that changed status in the last 24 hours and flag any past their statutory deadline.",
    "deliver": "talk",
    "enabled": true,
    "repeat": null,
    "nextRun": null,
    "lastStatus": null,
    "lastError": null
  },
  {
    "name": "Weekly lead digest",
    "agentId": "00000000-0000-0000-0000-000000000000",
    "kind": "cron",
    "cronExpr": "0 9 * * 1",
    "prompt": "Produce a digest of new leads and pipeline movement from the past week, ranked by expected value.",
    "deliver": "notification",
    "enabled": true,
    "repeat": null,
    "nextRun": null,
    "lastStatus": null,
    "lastError": null
  },
  {
    "name": "Nightly booking summary",
    "agentId": "00000000-0000-0000-0000-000000000000",
    "kind": "interval",
    "intervalMinutes": 1440,
    "prompt": "List bookings created or amended since the last run and highlight any with missing traveller details.",
    "deliver": "talk",
    "enabled": true,
    "repeat": { "times": 30, "completed": 0 },
    "nextRun": null,
    "lastStatus": null,
    "lastError": null
  }
]
```

- A **municipality** runs "Daily permit-status briefing" every morning at 08:00
  (`cron 0 8 * * *`), delivered to Talk.
- A **consultancy** runs "Weekly lead digest" every Monday at 09:00
  (`cron 0 9 * * 1`), delivered as a notification.
- A **travel agency** runs "Nightly booking summary" on an interval
  (`intervalMinutes=1440`), capped at 30 runs via `repeat`, delivered to Talk.

## Risks / Trade-offs

- **Conditional-requirement expressiveness.** If the register's validator does not
  support full `if`/`then` conditional requirements, the fallback is to declare the
  trigger fields as optional at the schema level and enforce the cron/interval/once
  rule in the dispatcher/UI. **Outcome: the validator rejected `if`/`then`.** Live import
  on NC 34 + OpenRegister 0.2.17 failed with `SchemaMapper::loadSchema(): Argument #1
  ($identifier) must be of type string|int, array given` when the schema carried an `allOf`
  block of `if`/`then` conditionals. The `allOf` block was removed; trigger fields are
  optional at the schema level and the kind→trigger rule is enforced downstream in
  `agent-schedule-dispatcher`. Required-field validation and the object round-trip were
  verified live.
- **Union import.** The register import unions duplicate schema definitions; the new
  `Schedule` schema must not collide with any existing schema name (`example` only
  today), and the import must be re-validated as valid JSON after merge.
- **agentId is a soft reference.** OpenRegister does not enforce that `agentId`
  points at a live `Agent`; a dangling reference surfaces at dispatch time as a
  `lastError`, handled by the downstream change.
