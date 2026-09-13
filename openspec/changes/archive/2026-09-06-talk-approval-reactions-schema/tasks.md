# Tasks: talk-approval-reactions-schema

## 1. Add the three Approval properties

- [x] 1.1 In `lib/Settings/hermiq_register.json`, add `talkMessageId` to `components.schemas.Approval.properties` as `{"type": "string", "title": "Talk message", "description": "..."}` — the id of the message carrying this approval request. Declare it **top-level**, because the reaction handler resolves an approval BY this value and nested dot-path filters match nothing (design.md D1).
- [x] 1.2 Add `talkRoomToken` as `{"type": "string", "title": "Talk room token", "description": "..."}` — the room the request was posted into, so the decision can be confirmed in the right place (design.md D2).
- [x] 1.3 Add `decidedVia` as `{"type": "string", "title": "Decided via", "description": "..."}` — `inbox` or `reaction`, written by the decision path and never by a user (design.md D3/D4). Do NOT enumerate the values.

## 2. Keep the addition union-import-safe

- [x] 2.1 Do NOT add any of the three to `Approval.required`; do NOT add any `allOf`/`if`/`then` conditional; do NOT touch existing properties or any other schema.
- [x] 2.2 Bump `info.version` — a `force:false` import advances the version WITHOUT applying to the already-existing `Approval` schema.
- [x] 2.3 Re-validate the register as JSON and confirm no duplicate keys.

## 3. Verify live

- [x] 3.1 Re-import and confirm `Approval` exposes all three, with existing properties and `required` unchanged.
- [x] 3.2 Confirm an `Approval` saved with `talkMessageId` is **retrievable by a filter on that property** — the requirement D1 exists to satisfy, which a passing import does not prove.
- [x] 3.3 Confirm a pre-existing `Approval` still reads and re-saves with all three unset.

## Acceptance criteria

- `Approval` carries optional `talkMessageId` (top-level), `talkRoomToken` and `decidedVia`.
- None is in `required`; no conditional block; each has a non-empty `title`.
- A filter on `talkMessageId` returns the approval carrying that message.
- Existing approvals validate unchanged with all three unset.
- `info.version` is bumped so the import applies.

## Quality reminders

- Config-only — no PHP. The listener that reads these fields is `talk-approval-reactions`.
- Use `<room-token>` / `<message-id>` / NIL UUID placeholders in examples (gitleaks scans them).
- Edit the JSON with the Edit tool — no sed/awk/scripts.
- Re-validate as JSON after editing; a merge can silently duplicate keys.

## Verification record (2026-07-28, live on NC 34 + spreed 24.0.1)

- Register `info.version` 0.20.0 → 0.21.0; forced re-import applied; `register_version_applied`
  advanced AND the schema actually changed — both checked, since the version moving without the
  schema moving is the known trap.
- `approval` (schema 4342) exposes `talk_message_id`, `talk_room_token` and `decided_via`;
  magic-table columns reconciled automatically on import.
- **The filter requirement was verified directly**: an approval saved with
  `talkMessageId=probe-msg-9001` is returned by `?talkMessageId=probe-msg-9001` (`total: 1`).
  That is the whole reason the property is top-level, and a passing import does not prove it.
- Existing `required` (`status`, `requestedAt`) and all pre-existing properties unchanged; JSON
  re-validated with duplicate-key detection.
