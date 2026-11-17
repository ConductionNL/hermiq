# Tasks: talk-delivery-schema

## 1. Add the two Schedule properties

- [x] 1.1 In `lib/Settings/hermiq_register.json`, add `deliverTarget` to `components.schemas.Schedule.properties` as `{"type": "string", "description": "..."}` — describe it as the Talk room token used when `deliver=talk`, empty/unset ⇒ fall back to the owner's Note-to-self.
- [x] 1.2 Add `lastDeliveryError` to `Schedule.properties` as `{"type": "string", "description": "Derived: last delivery failure message — written by the delivery layer, not the user"}`.
- [x] 1.3 Do NOT add either property to `Schedule.required`; do NOT add any `allOf`/`if`/`then` conditional (the importer rejects them); do NOT touch existing properties or the `example` schema.

## 2. Seed data

- [x] 2.1 Seed `Schedule` documented in design.md (Team standup digest, `deliver=talk`, `deliverTarget`=`<room-token>`, NIL-UUID agent). Live seed objects deferred (consistent with agent-schedule-schema) — a nil-agent/placeholder-room seed is broken demo data; real seeds land once agents exist.

## 3. Verify

- [x] 3.1 Verified live on NC 34 + OR 0.2.17: re-import (register info.version 0.1.0→0.2.0 to force update of the existing schema — `force:false` won't update an existing schema on app-version bump alone) — `Schedule` now exposes 15 props incl. `deliverTarget` + `lastDeliveryError`; existing props + `required` unchanged; a `deliver=talk` object with `deliverTarget` persists.
- [x] 3.2 Verified: a `deliver=talk` object without `deliverTarget` validates (both fields optional); register JSON re-validates clean (validate-register PASS, no dup keys).

## Acceptance criteria

- `Schedule` carries optional `deliverTarget` (string) and derived optional `lastDeliveryError` (string).
- Neither field is in `required`; no conditional (`if`/`then`/`allOf`) block is present.
- The addition is union-import-safe: existing `Schedule` props, `required`, and the `example` schema are untouched.
- A seed `Schedule` demonstrates the configurable-room path with a placeholder `<room-token>`.

## Quality reminders

- Config-only change — no PHP, no dispatcher edits; behavior that reads these fields is the downstream `talk-delivery` change.
- Use `<room-token>` / NIL UUID `00000000-0000-0000-0000-000000000000` placeholders in seeds and examples (gitleaks scans them).
- Re-validate `hermiq_register.json` as JSON after editing (a merge can silently dup keys); keep OpenAPI 3.0.0 shape.
- Edit the JSON with the Edit tool — do NOT use sed/awk/scripts.
