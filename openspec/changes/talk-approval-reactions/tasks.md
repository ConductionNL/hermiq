# Tasks: talk-approval-reactions

Depends on `talk-chat-bridge` (the bot, its availability probe and its posting path) and on
`talk-approval-reactions-schema` (the binding fields the handler resolves by).

## 1. Bind an approval to the message that carries it

- [x] 1.1 When an approval request is posted into a Talk room, record the room token and the resulting message id on the `Approval`. Best-effort: a recording failure must never prevent the approval being raised, because the inbox stays authoritative.
- [x] 1.2 Post the request AS THE BOT so the message is one the bot can later be reacted to on, reusing `TalkBridge`'s posting path rather than a second mechanism.

## 2. Reaction listener

- [x] 2.1 Handle spreed's reaction invocations on the SAME in-process bot: `type: 'Like'` (added) and `type: 'Undo'` (removed). Register unconditionally and probe availability at invoke time, exactly as the message listener does — never a `class_exists()` guard at `register()` time.
- [x] 2.2 Resolve the reacted-to message id to its `Approval` by filtering on the **top-level** `talkMessageId`, deterministically when more than one matches (design.md D3).
- [x] 2.3 Recognise 👍 as approve and 👎 as deny; ignore every other emoji rather than guessing (design.md D2).

## 3. Authorization — the load-bearing part

- [x] 3.1 Verify the reacting user is the approval's resolved reviewer: the named user, or a member of the named reviewer group. Ignore a reaction from anyone else. A reaction is a public one-click act available to every room participant, so without this the gate is bypassable by a bystander (design.md D1).
- [x] 3.2 Add a direct negative test proving a non-reviewer's reaction leaves the approval pending and records no decision. Flag for security review.

## 4. Apply and confirm

- [x] 4.1 Apply the decision through the SAME `ApprovalService` path the inbox uses, so a Talk-originated decision is indistinguishable downstream, and record `decidedVia = reaction`.
- [x] 4.2 Confirm the outcome in the originating room.
- [x] 4.3 An approval that is no longer pending is a visible no-op — say so rather than appearing to accept the reaction.
- [x] 4.4 On reaction REMOVAL, state in the room that the decision cannot be undone; do not reverse it and do not stay silent (design.md D5).

## 5. Verify live

- [x] 5.1 Live: raise a gated run, assert the approval records the room token and message id of the posted request. **Verified 2026-07-30** — approval `0505e53f` recorded `talkRoomToken=wh6exgao`, `talkMessageId=285`. Found and fixed a defect on the way: see §Live verification below.
- [x] 5.2 Live: as the reviewer react 👍 → approved with `decidedVia=reaction`; separately 👎 → denied. **Verified 2026-07-30** — 👍 → `approved`/`decidedBy=admin`/`decidedVia=reaction` + "✅ Approved" in the room; 👎 on a second gated run → `denied`/`decidedVia=reaction` + "🚫 Denied". Un-reacting afterwards left the decision standing and posted the "not a toggle" reply.
- [x] 5.3 Live: as a NON-reviewer react 👍 → the approval stays pending and no decision is recorded. **Verified 2026-07-30** — `hermiq-outsider`'s 👍 on the request message left the approval `pending` with no `decidedBy`/`decidedVia`, and the rejection was observed IN THE LOG (`[TalkApprovalReactionListener] Reaction from a non-reviewer ignored`), proving the reaction reached the authorization check rather than never being delivered.

## 6. Documentation

- [x] 6.1 Document deciding an approval from Talk: which emoji, who may use them, that other emoji do nothing, that un-reacting does not undo, and that the inbox remains available and authoritative.

## Acceptance criteria

- An approval request posted to Talk records the room and message that carry it; a recording failure still raises the approval.
- The reviewer's 👍 approves and 👎 denies; every other emoji is ignored.
- A non-reviewer's reaction is ignored — the approval stays pending and no decision is recorded.
- A decision applied by reaction is indistinguishable downstream from an inbox decision, except that `decidedVia` records it arrived by reaction.
- The outcome is confirmed in the room; an already-decided approval is a visible no-op.
- Removing a reaction does not reverse the decision, and the room is told so.
- With Talk absent, approvals behave exactly as before, through the inbox.

## Quality reminders

- Depends on both predecessors; do not start until the schema import has landed live.
- The approval decision runs INLINE (design.md D4) — unlike a chat turn, it is a small write, not a model call.
- Hydra gates apply: `@spec` on changed methods, `@e2e` on added scenarios, SPDX headers, no stubs.
- Do not use sed/awk/scripts to modify code — use the Edit tool.
- The authorization check in §3 is the reason this change can be safe. Treat a shortcut there as a defect, not a simplification.

## Live verification (2026-07-30, NC 34 + spreed 24.0.1)

All of §5 is now verified against the real instance, in room `wh6exgao` bound to a
`requiresApproval` schedule with `reviewer=admin`, using `hermiq-outsider` as the non-reviewer.

**The live run found two defects that every unit test had passed over. Both were total —
the reaction feature had never worked once.**

1. **The reaction payload was misread, so no reaction could ever decide anything.** The earlier
   status above asserted the spreed contract as "the reacted-to message in `object.object.id` and
   the emoji in `content`". That is not one shape — it is one field taken from each of two. A
   `Like` IS the envelope (`object.id` = message, top-level `content` = emoji); an `Undo` wraps the
   undone Like, so BOTH move one level deeper. Reading `content` from the top and the id from
   `object.object` therefore matched NEITHER type: a 👍 had no message id, an un-react had no
   emoji, and both returned null before reaching the authorization check. The unit fixture encoded
   the same hybrid, so the two bugs cancelled out and the suite stayed green while the feature was
   inert. `readDecision()` now normalises to the Like envelope first; the fixture mirrors
   `BotService::afterReactionAdded/Removed` exactly.

2. **`bind()` re-read the approval it had just been handed, and lost it silently.** A just-created
   approval is not reliably findable from inside the request that created it, so the fetch missed
   and the method returned false through a branch that logged NOTHING. The request was posted to
   Talk with no record of which message carried it, and every reaction on that message then
   resolved to no approval. This is why it was intermittent: the first gated run bound fine, the
   second posted message 296 and recorded neither room nor message id. `bind()` now takes the
   `ObjectEntity` the caller already holds — no read that can fail — and refuses loudly.

Both fixes were confirmed by outcome, not by inspection: after them a fresh gated run bound inline
(`room=wh6exgao, msg=297`), 👎 denied it via reaction, and un-reacting posted the "not a toggle"
reply — a message that was previously unreachable, since the `Undo` branch could never be entered.

The lesson worth carrying: a mock that accepts a payload the server never sends certifies a path
that cannot run. Assert the shape the producer actually emits.
