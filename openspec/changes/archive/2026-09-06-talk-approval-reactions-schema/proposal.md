---
kind: config
---

# Proposal: talk-approval-reactions-schema

## Why

`talk-approval-reactions` lets a reviewer resolve a pending approval by reacting 👍/👎 to the
agent's message in Talk. To apply that reaction it must answer one question: *which approval does
this reacted-to message decide?*

That is a **resolve-by** lookup, and this repo has already paid for getting that wrong once. In
`talk-chat-bridge-schema` the room binding was nearly put inside the free-form `metadata` object;
measured live, a top-level filter returned the bound row while the nested equivalent returned
`total: 0` — silently, with no error. The same trap applies here, so the message id goes top-level.

This change adds only the data shape. The listener that reads it is the `talk-approval-reactions`
code change (ADR-032 chain).

## What Changes

- Add three OPTIONAL properties to `components.schemas.Approval` in
  `lib/Settings/hermiq_register.json`:
  - `talkRoomToken` (string) — the room the approval request was posted into.
  - `talkMessageId` (string) — the id of the message carrying the request. **Top-level and
    filterable**, because the reaction handler resolves an approval BY this value.
  - `decidedVia` (string) — how the decision arrived (`inbox` or `reaction`), so an audit reader
    can tell a one-click approval from a considered one. Written by the decision path, never by a
    user.
- All three OPTIONAL, none added to `required`, no conditional (`if`/`then`/`allOf`) blocks — the
  OpenRegister importer rejects those.
- Every added property carries a `title` (fleet `schema-property-titles` gate).
- Bump `info.version`: a `force:false` import advances the stored version WITHOUT applying to an
  already-existing schema, so the bump is what makes the change actually land.

## Capabilities

### New Capabilities

- `talk-approval-reactions-schema`: the data shape binding an `Approval` to the Talk message that
  carries it, plus the provenance of its decision.

### Modified Capabilities

<!-- none — behaviour that reads or writes these fields is the talk-approval-reactions change. -->

## Impact

- **Config:** three new properties on `components.schemas.Approval`, plus an `info.version` bump.
  No existing property or `required` list is touched.
- **Backwards compatibility:** every field is optional and absent on existing approvals, so the
  shape is inert until the code change ships.
- **No code.**
