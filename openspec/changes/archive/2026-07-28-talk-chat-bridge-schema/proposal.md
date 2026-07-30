---
kind: config
---

# Proposal: talk-chat-bridge-schema

## Why

Hermiq's Talk integration is currently **one-way**: `DeliveryService` posts a scheduled
run's output into a Talk room (ADR-005, `talk-delivery`) and the conversation ends there.
The user cannot reply. The follow-up `talk-chat-bridge` (code) change makes Talk a real
two-way surface — the reply path ADR-005 promised — so an agent is reachable from the Talk
**mobile apps** with zero mobile-client work, and so a cron report can be answered with
"why did that drop?" straight from a phone.

Two data-shape gaps block that, and both live in the declarative register:

1. **No room↔session link.** A run already materialises a real `Conversation`
   (`ScheduleService:2489`), and `DeliveryService` already knows which room it posted to —
   but nothing records the pair. Without it an inbound Talk message cannot be resolved back
   to the session that produced the report, so a reply can only ever start a blank thread.
2. **No notion of who is speaking.** `Conversation` has a single `userId` owner and
   `Message` has no author at all — messages are role-tagged (`user`/`assistant`) only. A
   Talk room has many participants, so a shared session literally cannot record who said
   what, and the model cannot tell two humans apart.

This change is the **head of an ADR-032 chain**: it adds the data shape only. The
`talk-chat-bridge` (code) change consumes it. Keeping the register edit separate preserves
the config→service split and matches the existing `talk-delivery-schema` → `talk-delivery`
precedent in this same feature area.

## What Changes

- Add two OPTIONAL properties to `components.schemas.Conversation` in
  `lib/Settings/hermiq_register.json`:
  - `talkRoomToken` (string, optional) — the Talk room token this conversation is bound
    to. Written by the delivery layer when it posts a run's output to a room, and by the
    bridge when a room's first message opens a session. Empty/unset means the conversation
    has no Talk binding and is web-UI only (today's behaviour for every existing object).
  - `participants` (array of string, optional, default `[]`) — the uids permitted to take a
    turn in this conversation, beyond the owner. The `userId` owner is **implicitly** a
    participant and is not required to appear in the list.
- Add two OPTIONAL properties to `components.schemas.Message`:
  - `authorId` (string, optional) — the uid of the human who produced this turn. Set on
    `role = user` messages; unset for `system` / `assistant` / `tool` turns, which have no
    human author.
  - `authorDisplayName` (string, optional) — the author's display name as it read at send
    time, captured so history stays legible after a rename or a deleted account.
- All four are **optional**, none is added to any `required` list, and none carries a
  conditional (`if`/`then`/`allOf`) block — OpenRegister's importer rejects conditional
  blocks (established by `agent-schedule-schema`, restated by `talk-delivery-schema`).
- Every added property carries a `title`, per the fleet's `schema-property-titles` gate.
- Bump the register's `info.version` so the import actually applies to the **existing**
  schemas (a `force:false` import advances the version without applying — see Risk 2).

### Why `talkRoomToken` is a top-level property, not `metadata`

`Conversation.metadata` is a free-form JSON object and is the obvious-looking home for a
binding key. It is the wrong one. The bridge's hot path is a **lookup by room token** —
given an inbound Talk message, find the conversation bound to that room. OpenRegister's
dot-path filters on nested JSON match nothing (a known, repeatedly-observed fleet
behaviour), so a `metadata.talkRoomToken=<token>` query would silently return zero results
and every inbound message would open a fresh blank session — a green-but-dead bridge that
unit tests would not catch. A top-level property is filterable, so it is the only shape
that works.

## Capabilities

### New Capabilities

- `talk-chat-bridge-schema`: the declarative data-shape for two-way, multi-participant Talk
  sessions — the room↔conversation binding, the participant roster, and per-message human
  authorship, with their optional/derived semantics.

### Modified Capabilities

<!-- none — only the Conversation/Message shapes are added to declaratively; every
     behaviour that reads or writes these fields lives in the talk-chat-bridge (code)
     change. -->

## Impact

- **Config:** `lib/Settings/hermiq_register.json` — two new properties on
  `components.schemas.Conversation`, two on `components.schemas.Message`, plus an
  `info.version` bump. A union-import-safe addition that does NOT touch any existing
  property or either schema's `required` list.
- **Chain:** head of the ADR-032 chain; `talk-chat-bridge` (code) gains
  `depends_on: [talk-chat-bridge-schema]` and reads `talkRoomToken` + `participants`,
  writes `authorId` + `authorDisplayName`.
- **Backwards compatibility:** every field is optional and absent on existing objects. A
  `Conversation` with no `talkRoomToken` and no `participants` behaves exactly as today
  (owner-only, web-UI only), so the change is inert until the code change ships.
- **No code:** config-only; no PHP, no listener, no guard change here.
- **Other Conduction apps:** none affected — Hermiq owns this register. Talk (spreed) is a
  consumer-side runtime dependency of the *code* change, not of this one.
