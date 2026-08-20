# Design: talk-delivery-schema

## Context

The `Schedule` schema (declared by `agent-schedule-schema`, stored in
`lib/Settings/hermiq_register.json` as an OpenAPI 3.0.0 `components.schemas.Schedule`)
already carries trigger fields, a `deliver` enum (`talk`|`notification`|`none`), and
derived run-state fields (`nextRun`, `lastStatus`, `lastError`) that are written by the
dispatcher and left unset by users. To support a configurable Talk room and a persisted
delivery error, two more properties must live on that schema **before** any service reads
them. This change adds only the data shape; the reading/writing behavior is the
downstream `talk-delivery` (code) change (the config→service half of the ADR-032 chain).

A hard constraint from `agent-schedule-schema`: OpenRegister's register importer **rejects
`allOf` / `if` / `then` conditional blocks** (it threw `Argument #1 ($identifier) must be
of type string|int, array given` on an `allOf` of `if`/`then`). So neither new property may
carry a conditional requirement — `deliverTarget` is only *meaningful* when `deliver=talk`,
but that relationship is documented in the `description`, not enforced in the schema.

## Goals / Non-Goals

**Goals:**
- Add `deliverTarget` (string, optional, user-supplied) to `Schedule`.
- Add `lastDeliveryError` (string, optional, derived — written by the delivery layer).
- Keep the addition **union-import-safe**: existing `Schedule` properties, `required`
  list, and the `example` schema are untouched, so a re-import unions cleanly.
- Provide a seed `Schedule` demonstrating the `deliverTarget` path with a placeholder token.

**Non-Goals:**
- Any delivery behavior (room resolution, membership check, fallback chain, error
  persistence) — that is the `talk-delivery` (code) change.
- Conditional requirements binding `deliverTarget` to `deliver=talk` — forbidden by the
  importer; left as a `description` note.
- Changing the `deliver` enum, `required` list, or any existing property.
- NC Mail (`IMailer`) outbound — separate future scope.

## Decisions

**Two plain optional strings, mirroring the existing derived fields.** `deliverTarget`
and `lastDeliveryError` are declared exactly like `nextRun`/`lastStatus`/`lastError`:
`{"type": "string", "description": "..."}` with no `format`, no `enum`, and NOT added to
`required`. `deliverTarget`'s description states it is the Talk room token used when
`deliver=talk` and that an empty/unset value means "fall back to the owner's Note-to-self".
`lastDeliveryError`'s description marks it **derived — written by the delivery layer on
failure, not the user** (matching the `lastError` wording).

Alternatives considered:
- *`if`/`then` making `deliverTarget` required when `deliver=talk`* — rejected: the
  importer rejects conditional blocks (`agent-schedule-schema` regression). Also
  undesirable: Note-to-self is a valid `deliver=talk` target with no room token.
- *A nested `delivery` object grouping target + error* — rejected: needlessly changes the
  flat shape the dispatcher already round-trips through `sanitizeForSave`, and risks the
  nullable-object materialization artifact seen with `repeat`.
- *A `format: "uuid"` on `deliverTarget`* — rejected: Talk room tokens are short opaque
  strings, not UUIDs.

**Union-import safety.** The two properties are appended to `Schedule.properties` only;
`required` is not modified. Because OpenRegister's import unions schema definitions, adding
optional properties is safe and idempotent. The separate `example` schema is not touched.

## Seed Data (ADR-001)

One additional realistic `Schedule` demonstrating the configurable-room path. The room
token is a **placeholder** `<room-token>` (never a real token — gitleaks scans these);
`agentId` uses the NIL UUID.

```json
[
  {
    "name": "Team standup digest",
    "agentId": "00000000-0000-0000-0000-000000000000",
    "kind": "cron",
    "cronExpr": "0 9 * * 1-5",
    "prompt": "Summarise yesterday's merged PRs and open blockers for the team.",
    "deliver": "talk",
    "deliverTarget": "<room-token>",
    "enabled": true,
    "repeat": null,
    "nextRun": null,
    "lastStatus": null,
    "lastError": null,
    "lastDeliveryError": null
  }
]
```

- A **software team** runs "Team standup digest" every weekday at 09:00, delivered to a
  **specific Talk room** (`deliverTarget = <room-token>`) rather than Note-to-self.
- A schedule with `deliver=talk` and no `deliverTarget` (see `agent-schedule-schema`'s
  seeds) still delivers — it falls back to the owner's Note-to-self.

## Declarative-vs-imperative decision (ADR-031)

Pure declarative schema change: two optional properties added to a JSON `components.schemas`
definition. No imperative code, no lifecycle, no aggregation — the canonical declarative
case. The service that reads/writes these fields is the separate `talk-delivery` change.

## Risks / Trade-offs

- **No enforcement that `deliverTarget` is only set with `deliver=talk`** → the importer
  forbids conditional requirements; documented in the property `description`, and the
  delivery service simply ignores `deliverTarget` unless `deliver=talk`. Acceptable.
- **Derived `lastDeliveryError` could be user-supplied via the API** → same exposure as
  the existing `lastError`/`nextRun` derived strings; treated as dispatcher-owned by
  convention, not schema-enforced. Consistent with the current design.
- **Import must union, not replace** → append optional properties only; do not touch
  `required` or existing props, so a re-import cannot corrupt the existing shape
  (re-validate the JSON after any merge).
