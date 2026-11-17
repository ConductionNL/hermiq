# Design: talk-approval-reactions-schema

## Context

Hermiq's approval gate (ADR-004 governance, EU AI Act Art. 14) already stops a run and waits for a
person. `DeliveryService` already posts that request into Talk. The reviewer therefore already
*sees* the request on their phone — and then has to open a browser to act on it.

`talk-approval-reactions` closes that gap by letting a 👍/👎 reaction decide. To do that it must
resolve a reacted-to message back to the approval it carries, which is a lookup — and lookups need
a filterable key.

## Goals / Non-Goals

**Goals**

- A filterable binding from a Talk message to the `Approval` it carries.
- Provenance on the decision, so an audit reader can tell a reaction from an inbox decision.
- Inert until the code change ships: every field optional, no backfill.

**Non-Goals**

- Any behaviour. No listener, no decision path, no notifier change — all `talk-approval-reactions`.
- Changing who may approve, or the gate's semantics.

## Decisions

### D1: `talkMessageId` is top-level, not nested

The same decision as `talk-chat-bridge-schema`'s `talkRoomToken`, for the same measured reason: a
nested `metadata.x` filter returns `total: 0` silently while the object sits there. A reaction
handler built on a nested key would resolve nothing, decide nothing, and look wired — with unit
tests green, because in-memory doubles return what a real filter would not.

This is the second time this shape has come up in this feature area. It is now a rule for the
repo: **a property you must resolve BY is declared top-level.**

### D2: Room token is stored alongside, even though it is not the lookup key

`talkMessageId` is what resolves; `talkRoomToken` is what the handler needs afterwards, to confirm
the decision in the right room. Storing it avoids a second lookup and keeps the approval record
self-describing.

### D3: `decidedVia` is a plain string, not an enum

The schema *could* enumerate `inbox`/`reaction`. It deliberately does not: the OpenRegister
importer is unforgiving about schema shape changes on existing objects, and a future surface
(notification action, email reply) would then require a schema change to record itself honestly.
A string with a documented vocabulary costs nothing and does not gate the next surface.

### D4: Derived, not user-supplied

`decidedVia` is written by the decision path. The schema cannot enforce that (no conditional
blocks — the importer rejects them), so it is a `description` contract upheld by the writer,
exactly as `talk-delivery-schema` handles `lastDeliveryError`.

## Seed Data (ADR-001)

No new schema is introduced — three optional properties are added to an existing one — so there is
no new seed object. An illustrative decided approval, with placeholders:

```json
{
  "_schema": "approval",
  "status": "approved",
  "sourceType": "schedule",
  "agentId": "00000000-0000-0000-0000-000000000000",
  "reviewer": "<reviewer-uid>",
  "reviewerType": "user",
  "talkRoomToken": "<room-token>",
  "talkMessageId": "<message-id>",
  "decidedBy": "<reviewer-uid>",
  "decidedVia": "reaction"
}
```

An approval created before this change carries none of the three and is simply not reachable from
Talk — which is the pre-existing behaviour.

## Declarative-vs-imperative decision (ADR-031)

Pure declarative schema change: three optional properties on an existing `components.schemas`
definition. No lifecycle, aggregation, calculation, notification, relation or widget behaviour, so
nothing belongs in `x-openregister-*`. Every service that reads or writes these fields lives in the
`talk-approval-reactions` code change.

## Risks / Trade-offs

- **`talkMessageId` is not unique-constrained** → the register cannot express uniqueness, so the
  handler must resolve deterministically and must not assume a single row. Same posture as
  `talkRoomToken`.
- **`decidedVia` is unenforced** → a client could set it. Same exposure as the existing derived
  `lastError`/`nextRun` strings; treated as writer-owned by convention.
- **Message ids are spreed's, not ours** → if a message is deleted the binding dangles. A dangling
  binding resolves to nothing, which is the correct degradation: no message, no reaction, no
  decision.
