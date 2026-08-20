# Design: talk-chat-bridge-schema

## Context

`talk-delivery` made Talk an **outbound** channel: `DeliveryService` posts a run's output to
a room, Note-to-self, or a notification, per the ADR-005 fallback chain. ADR-005 justified
choosing Talk over Hermes' 22-platform gateway partly because "Talk gives a real two-way
channel and a reply path" — but only the outbound half was ever built.

The follow-up `talk-chat-bridge` (code) change builds the inbound half: Hermiq registers as
an in-process Talk bot (`nextcloudapp://hermiq`), so an agent becomes reachable from the
Talk **mobile apps** with no mobile-client work at all — bot messages are server-side, and
the iOS/Android clients render them like any other message.

Two facts about the existing code make that cheap, and one data gap makes it impossible:

- A scheduled run **already materialises a real `Conversation`** (`ScheduleService:2489`,
  owner = schedule owner, bound to `agentId`) and runs `Engine::processMessage` against it.
  The session a user would want to reply to already exists at delivery time.
- `Engine::processMessage(string $conversationId, string $userId, ...)` takes an explicit
  `userId` and has no `IUserSession` dependency, so it already fits a background job — which
  the bridge needs, because Talk's bot listener is synchronous (see the code change).
- But nothing records **which room a conversation was delivered to**, and nothing records
  **who spoke** — `Conversation` has one `userId`, and `Message` has no author field at all.

This change closes only that data gap. It is the head of an ADR-032 chain, mirroring
`talk-delivery-schema` → `talk-delivery` in the same feature area.

## Goals / Non-Goals

**Goals**

- Give `Conversation` a **filterable** binding to a Talk room, so an inbound message can be
  resolved to the session that produced a report.
- Give `Conversation` a participant roster, so a session can be shared by more than its
  owner.
- Give `Message` a human author, so a shared session can record and render who said what,
  and so the model can tell two humans apart.
- Stay completely inert until the code change ships: every field optional, no backfill.

**Non-Goals**

- Any behaviour. No listener, no guard change, no delivery-side write — all of that is
  `talk-chat-bridge` (code).
- Thread-level bindings. Session mapping is **room = session** for this chain; Talk threads
  are a deliberate follow-up (see the code change's Non-Goals).
- Modelling Talk participants as the source of truth. `participants` is Hermiq's own
  roster; room membership is Talk's and is checked at turn time by the code change.
- A `channel`/provenance field on `Message` (web vs Talk). Deferred — `authorId` plus the
  conversation's `talkRoomToken` already answers the questions we have today.

## Decisions

### D1: `talkRoomToken` is a top-level property, not `metadata.talkRoomToken`

`Conversation.metadata` is free-form JSON and looks like the natural home for a binding key.
It is the wrong home. The bridge's hot path is *"given this inbound room token, find the
bound conversation"* — a filter query. OpenRegister's dot-path filters on nested JSON match
nothing, a behaviour this fleet has hit repeatedly. A `metadata.talkRoomToken=<token>` query
would return zero rows **silently**: every inbound message would open a fresh blank session,
the feature would look wired but be dead, and unit tests with in-memory doubles would stay
green throughout. A top-level property is filterable and is the only shape that works.

### D2: The owner is implicitly a participant

`participants` lists uids *in addition to* `userId`. The alternative — requiring the owner
to also appear in the roster — makes every existing conversation inconsistent the moment the
field exists, and creates a failure mode where removing yourself from the roster locks you
out of a session you own. Implicit ownership means an empty or unset roster is exactly
today's semantics, which is what keeps this change inert.

### D3: Author fields are optional and unset for non-human turns

`role` already distinguishes `system` / `user` / `assistant` / `tool`. Only `user` turns have
a human behind them. Binding the author fields to `role` with a conditional block is not
available — the OpenRegister importer rejects `if`/`then`/`allOf` (established by
`agent-schedule-schema`, restated by `talk-delivery-schema`) — so the constraint lives in the
property `description` and is upheld by the writer in the code change.

### D4: Display name is captured, not resolved

Storing `authorDisplayName` alongside `authorId` duplicates data that `IUserManager` could
resolve on read. That is deliberate: a session transcript is an audit record (ADR-004), and
a renamed or deleted account would otherwise silently rewrite or blank the history. Captured
at send time, it stays legible.

### D5: The register version must be bumped

An OpenRegister import with `force:false` advances the stored register version **without
applying** the change to schemas that already exist — a trap this fleet has hit before, where
the version moves and the schema does not. `Conversation` and `Message` both already exist,
so the `info.version` bump is not cosmetic: without it the four properties never land, and
the downstream code change fails against a schema that looks updated.

## Seed Data (ADR-001)

The four fields are additive to two existing schemas, so seeding means showing one shared,
Talk-bound conversation and its first two turns. Room tokens are **placeholders**
(`<room-token>` — never a real token; gitleaks scans these) and uuids use the NIL UUID.

```json
[
  {
    "_schema": "conversation",
    "title": "Nightly triage digest",
    "userId": "<owner-uid>",
    "agentId": "00000000-0000-0000-0000-000000000000",
    "talkRoomToken": "<room-token>",
    "participants": ["<colleague-uid>"],
    "metadata": {}
  },
  {
    "_schema": "message",
    "conversationId": "00000000-0000-0000-0000-000000000000",
    "role": "assistant",
    "content": "Nightly triage: 3 new incidents, 1 regression on the ingest path.",
    "authorId": null,
    "authorDisplayName": null
  },
  {
    "_schema": "message",
    "conversationId": "00000000-0000-0000-0000-000000000000",
    "role": "user",
    "content": "Which regression? Give me the failing step.",
    "authorId": "<colleague-uid>",
    "authorDisplayName": "A. Colleague"
  }
]
```

- A **municipality's platform team** runs a nightly triage agent. Its cron report lands in a
  shared Talk room (`talkRoomToken`), so the conversation is bound to that room.
- A **second team member** — not the schedule owner — replies from the Talk mobile app. Their
  uid is on `participants`, and their turn carries `authorId` + `authorDisplayName`.
- The agent's own turn (`role = assistant`) carries **no** author, demonstrating D3.
- A conversation with neither `talkRoomToken` nor `participants` (every seed shipped by
  `agent-engine-schemas`) is still valid and still owner-only — demonstrating inertness.

## Declarative-vs-imperative decision (ADR-031)

Pure declarative schema change: four optional properties added to two existing
`components.schemas` definitions in `lib/Settings/hermiq_register.json`. No lifecycle, no
aggregation, no calculation, no notification, no widget — the canonical declarative case, and
nothing here warrants an ADR-031 exception. Every service that reads or writes these fields
lives in the separate `talk-chat-bridge` (code) change.

## Risks / Trade-offs

- **`participants` is a roster, not an ACL** → it records *who may take a turn*, not what
  they may see or do. Data-layer authorization stays OpenRegister's job (ADR-023 Rule 1);
  the code change's participant check is defense-in-depth on top of it, not a replacement.
  Documented here so the downstream change does not mistake the roster for a permission
  model.
- **No enforcement that `authorId` is only set on `role = user`** → the importer forbids
  conditional requirements, so this is a `description` contract upheld by the writer, exactly
  as `talk-delivery-schema` handles `deliverTarget` vs `deliver=talk`. Acceptable.
- **`talkRoomToken` is not unique-constrained** → the register cannot express uniqueness, so
  two conversations could in principle claim the same room. The code change must resolve
  deterministically (most recent binding wins) and must not assume a single row. Called out
  here so it is designed for rather than discovered.
- **Captured display names go stale by design** → a transcript shows the name as it was, not
  as it is. That is the intent (D4), but it will read as a bug to someone who does not know;
  the property `description` must say so.
- **Import must union, not replace** → append optional properties only; do not touch either
  `required` list or any existing property, so a re-import cannot corrupt the existing shape.
  Re-validate the JSON after any merge — a union merge can silently duplicate keys.
