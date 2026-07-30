# Design: talk-approval-reactions

## Context

Hermiq's approval gate is its Art. 14 human-oversight mechanism: a gated run stops and waits for a
person. `DeliveryService` already posts that request into Talk, and `talk-chat-bridge` already put
an in-process bot in the room. So the reviewer already sees the request on their phone — and then
has to open a browser.

What is missing is one wire. spreed invokes bots on reactions exactly as it does on messages:

| Surface | Fact |
|---|---|
| `BotService::afterReactionAdded` | Invokes bots with `type: 'Like'`, the reacted-to message in `object`, the reactor in `actor`, and the emoji in `content` |
| `BotService::afterReactionRemoved` | Same shape with `type: 'Undo'` |
| `ApprovalService` | Already owns the full pending → approved/denied lifecycle |
| `Approval` | Already carries `reviewer`, `reviewerType`, `decidedBy`, `decidedAt`, `status` |

## Goals / Non-Goals

**Goals**

- A reviewer resolves a pending approval with one tap, from the room they already got it in.
- A Talk-originated decision is indistinguishable downstream from an inbox one, except that its
  provenance is recorded.
- Inert without Talk; the inbox stays authoritative.

**Non-Goals**

- Creating approvals, or changing which runs need one, or who may approve.
- Interactive message widgets, buttons, or notification actions.
- Undo.

## Decisions

### D1: Authorize the reactor before anything else

This is the decision the change lives or dies on. A reaction is a **public, one-click act
available to every participant of the room**. The approval gate exists to require a specific
person's judgement; if any bystander's 👍 released a gated run, this change would make Hermiq's
oversight mechanism weaker than not having it.

So the reactor is resolved against the approval's `reviewer`/`reviewerType` — named user, or
membership of the named group — and a reaction from anyone else is **ignored**, not merely
unrecorded. It gets a direct negative test.

Note the asymmetry with the chat bridge: there, room membership plus the participant roster is
enough to *talk to an agent*. Here, being in the room is explicitly not enough to *decide*.

### D2: 👍 and 👎 only; everything else is ignored

An emoji is a lossy signal. Rather than interpret 🎉 or ✅ as assent, the handler recognises exactly
two and ignores the rest. Guessing would be a governance decision made by an emoji-matching
heuristic.

### D3: Resolve by a top-level `talkMessageId`

The handler's hot path is *"which approval does this reacted-to message decide?"* — a filter query.
The schema change puts `talkMessageId` top-level for exactly the reason `talkRoomToken` is:
measured live, a nested dot-path filter returns `total: 0` silently. A nested key here would mean
every reaction resolves nothing and decides nothing, with green unit tests throughout.

### D4: The decision runs inline; the chat turn does not

`talk-chat-bridge` insists a turn must leave the request, because an LLM call is 5–60s inside a
synchronous bot listener. Applying an approval is a small object write. It runs inline
deliberately — a background hop would add latency and a failure mode to a governance action whose
whole value is immediacy, and would make confirming the outcome in the room harder, not easier.

### D5: Un-reacting does not undo, and says so

spreed dispatches reaction removal, and a user will reasonably read removing 👍 as taking it back.
It cannot be: the run has already been released. The handler answers the removal in the room
explicitly. Ignoring it silently would be worse than either alternative, because silence reads as
"it worked".

### D6: Provenance is recorded, not inferred

`decidedVia` distinguishes a one-click reaction from a decision made in the inbox with the full
context in front of the reviewer. Both are valid; an auditor should be able to tell them apart
without archaeology.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Reaction → approval decision | **Imperative** | External-integration seam: consuming spreed's reaction invocation and applying it through `ApprovalService`. An explicit ADR-031 exception, same as the chat bridge's listener. |
| Approval↔message binding | **Declarative (data)** | Plain persisted properties, declared in `talk-approval-reactions-schema`. |
| Decision provenance | **Declarative (data)** | A persisted string written at decision time. |

No lifecycle, aggregation, calculation, relation or widget behaviour is introduced. The approval
lifecycle itself already exists and is not changed — this adds a new way to trigger a transition,
not a new transition.

## Seed Data (ADR-001)

No new schemas; seeds belong to `talk-approval-reactions-schema`. Live verification needs a gated
run (an agent or schedule with `requiresApproval`), a Talk room with the bot enabled, and two
users — the reviewer and a bystander — so D1's negative case can actually be exercised.

## Risks / Trade-offs

- **A bystander could decide** → D1, with a negative test. The single highest risk here.
- **Emoji as governance input** → D2 narrows it to two; provenance (D6) keeps the record honest.
- **Un-reacting misleads** → D5 answers rather than ignores.
- **A dangling binding** → if the message is deleted the approval resolves to nothing, and is
  decidable only from the inbox. Correct degradation.
- **Two surfaces can race** → inbox and reaction can both decide. First decision wins and the
  second is a visible no-op, which is already true of two people in the inbox.
