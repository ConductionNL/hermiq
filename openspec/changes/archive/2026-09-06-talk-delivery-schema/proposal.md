---
kind: config
---

# Proposal: talk-delivery-schema

## Why

The `talk-delivery` change delivers run output to Talk, but the user wants two things
the current `Schedule` schema cannot hold: (1) a **configurable target room** so a
`deliver=talk` schedule can post to a specific Talk conversation rather than only the
owner's Note-to-self, and (2) a persisted **`lastDeliveryError`** so a failed delivery
is visible on the schedule, not just in the logs. Both are data-shape additions to the
declarative `Schedule` schema. This change is the **head of an ADR-032 chain**: it adds
those two fields, and the follow-up `talk-delivery` (code) change consumes them. Keeping
the schema change separate preserves the config→service split (schema declared before any
service reads it) and keeps each change single-purpose.

## What Changes

- Add two OPTIONAL properties to the existing declarative `Schedule` schema in
  `lib/Settings/hermiq_register.json` (OpenAPI 3.0.0 `components.schemas.Schedule`):
  - `deliverTarget` (string, optional) — the Talk room token to post to when
    `deliver=talk`. When empty/unset, the delivery service falls back to the owner's
    Note-to-self conversation. User-supplied.
  - `lastDeliveryError` (string, optional, **derived**) — the last delivery failure
    message, written by the delivery layer on failure and user-unset (same pattern as
    `nextRun` / `lastStatus` / `lastError`).
- Both are **optional** and carry no conditional (`if`/`then`/`allOf`) requirements —
  OpenRegister's importer rejects `allOf`/`if`/`then` on import (established by
  `agent-schedule-schema`), so `deliverTarget` stays a plain optional string even though
  it is only meaningful when `deliver=talk`.
- Add a seed `Schedule` example that sets `deliverTarget` to a placeholder room token,
  demonstrating the configurable-room path.

## Capabilities

### New Capabilities
- `talk-delivery-schema`: the declarative data-shape for configurable Talk-room delivery
  and persisted delivery-error state — the two new `Schedule` properties and their
  optional/derived semantics.

### Modified Capabilities
<!-- none — the Schedule schema shape is added to declaratively; behavior that reads
     these fields lives in the talk-delivery (code) change. -->

## Impact

- **Config:** `lib/Settings/hermiq_register.json` — two new properties on
  `components.schemas.Schedule`; a union-import-safe addition that does NOT touch the
  existing `Schedule` properties or the `example` schema.
- **Chain:** head of the ADR-032 chain; `talk-delivery` (code) gains
  `depends_on: [talk-delivery-schema]` and reads `deliverTarget` + writes
  `lastDeliveryError`.
- **No code:** config-only; no PHP, no controller, no dispatcher change here.
- **Other Conduction apps:** none affected (Hermiq owns this register).
