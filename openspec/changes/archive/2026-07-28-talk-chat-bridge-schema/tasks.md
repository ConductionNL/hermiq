# Tasks: talk-chat-bridge-schema

## 1. Add the two Conversation properties

- [x] 1.1 In `lib/Settings/hermiq_register.json`, add `talkRoomToken` to `components.schemas.Conversation.properties` as `{"type": "string", "title": "Talk room token", "description": "..."}` — describe it as the Talk room this conversation is bound to, written by the delivery layer and the bridge; empty/unset ⇒ no Talk binding (web-UI only). Add it as a **top-level** property, NOT inside `metadata` (design.md D1 — nested dot-path filters match nothing).
- [x] 1.2 Add `participants` to `Conversation.properties` as `{"type": "array", "default": [], "title": "Participants", "items": {"type": "string"}, "description": "..."}` — describe it as the uids permitted to take a turn beyond the owner, and state that the `userId` owner is implicitly a participant and need not be listed (design.md D2).

## 2. Add the two Message properties (plus the Agent opt-in flag)

- [x] 2.1 Add `authorId` to `components.schemas.Message.properties` as `{"type": "string", "title": "Author", "description": "..."}` — describe it as the uid of the human who produced the turn, set on `role = user` messages and unset for `system`/`assistant`/`tool` turns (design.md D3).
- [x] 2.2 Add `authorDisplayName` to `Message.properties` as `{"type": "string", "title": "Author display name", "description": "..."}` — describe it as the author's display name **captured at send time**, deliberately not re-resolved, so history stays legible after a rename or a deleted account (design.md D4).
- [x] 2.3 Add `talkEnabled` to `components.schemas.Agent.properties` as `{"type": "boolean", "default": false, "title": "Reachable from Talk", "description": "..."}` — the Hermiq half of the two-sided opt-in, default false so no existing agent becomes reachable.

## 3. Keep the addition union-import-safe

- [x] 3.1 Do NOT add any of the four properties to `Conversation.required` or `Message.required`; do NOT add any `allOf`/`if`/`then` conditional (the importer rejects them); do NOT touch existing properties, either `required` list, or any other schema in the register.
- [x] 3.2 Bump `info.version` in `lib/Settings/hermiq_register.json` (currently `0.19.2`) — a `force:false` import advances the version WITHOUT applying the change to the already-existing `Conversation`/`Message` schemas (design.md D5).
- [x] 3.3 Re-validate `hermiq_register.json` as JSON after editing and confirm no duplicate keys were introduced.

## 4. Seed data

- [x] 4.1 Add the seed objects documented in design.md — one Talk-bound `Conversation` with a `participants` entry, one authored `role = user` `Message`, one unauthored `role = assistant` `Message` — using `<room-token>` / `<owner-uid>` / `<colleague-uid>` placeholders and the NIL UUID.

## 5. Verify live

- [x] 5.1 Re-import the register on the live instance and confirm `Conversation` exposes `talkRoomToken` + `participants` and `Message` exposes `authorId` + `authorDisplayName`, with existing properties and both `required` lists unchanged.
- [x] 5.2 Confirm a `Conversation` object saved with `talkRoomToken` is **retrievable by a filter on that property** — this is the requirement D1 exists to satisfy, and a passing schema import does not prove it.
- [x] 5.3 Confirm a pre-existing `Conversation` (no `talkRoomToken`, no `participants`) still reads and re-saves cleanly with all four fields unset.

## Acceptance criteria

- `Conversation` carries optional `talkRoomToken` (string, top-level) and `participants` (array of string, default `[]`).
- `Message` carries optional `authorId` (string) and `authorDisplayName` (string).
- `Agent` carries optional `talkEnabled` (boolean, default false).
- None of the four is in a `required` list; no conditional (`if`/`then`/`allOf`) block is present; each carries a non-empty `title`.
- A filter query on `talkRoomToken` returns the bound conversation.
- The addition is union-import-safe: existing properties, both `required` lists, and every other schema are untouched.
- `info.version` is bumped so the import applies to the existing schemas.
- Seed data demonstrates a shared, Talk-bound conversation with an authored user turn and an unauthored assistant turn.

## Quality reminders

- Config-only change — no PHP, no listener, no guard edits; every behaviour that reads or writes these fields is the downstream `talk-chat-bridge` change.
- Use `<room-token>` / `<owner-uid>` / NIL UUID `00000000-0000-0000-0000-000000000000` placeholders in seeds and examples (gitleaks scans them).
- Every added property needs a `title` — the fleet's `schema-property-titles` gate fails otherwise.
- Edit the JSON with the Edit tool — do NOT use sed/awk/scripts.
- Keep the OpenAPI 3.0.0 shape; re-validate the file as JSON after any merge (a union merge can silently duplicate keys).

## Verification record (2026-07-28, live on NC 34 + spreed 24.0.1)

- Register `info.version` 0.19.2 → 0.20.0; forced re-import applied via app disable/enable.
  `register_version_applied` advanced to 0.20.0 AND the schemas actually changed — both were
  checked, because the version advancing without the schema changing is the known trap.
- `conversation` (schema 5018) exposes `talkRoomToken` + `participants`; `message` (5019) exposes
  `authorId` + `authorDisplayName`; `agent` (4365) exposes `talk_enabled`. Magic-table columns
  reconciled automatically — no manual `tables:reconcile` needed.
- **The filter requirement was verified directly, and the counter-case with it:** a conversation
  saved with a top-level `talkRoomToken` is returned by `?talkRoomToken=<token>` (`total: 1`),
  while the same value stored at `metadata.talkRoomToken` returns `total: 0` — silently, with no
  error. That is exactly the green-but-dead failure this design avoids, now measured rather than
  asserted.
- Existing `required` lists and all pre-existing properties unchanged; JSON re-validated with
  duplicate-key detection.
