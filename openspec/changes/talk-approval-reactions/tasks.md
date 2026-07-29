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

- [ ] 5.1 **NOT LIVE-VERIFIED.** Live: raise a gated run, assert the approval records the room token and message id of the posted request.
- [ ] 5.2 **NOT LIVE-VERIFIED.** Live: as the reviewer react 👍 → approved with `decidedVia=reaction`; separately 👎 → denied.
- [ ] 5.3 **NOT LIVE-VERIFIED.** Live: as a NON-reviewer react 👍 → the approval stays pending and no decision is recorded. This is the security case and is the one that most needs to be seen working.

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

## Status (2026-07-28)

Implemented and unit-covered end to end, including the security case: a non-reviewer's reaction
reaches neither `approve()` nor `deny()` and records no provenance
(`TalkApprovalReactionListenerTest::testNonReviewerReactionIsIgnored`). The spreed reaction
contract was verified against 24.0.1 source (`BotService::afterReactionAdded/Removed` dispatch the
same `BotInvokeEvent` with `type: 'Like'`/`'Undo'`, the reacted-to message in `object.object.id`
and the emoji in `content`).

**The §5 live checks were NOT run.** They need a genuinely gated run (an agent or schedule with
`requiresApproval`) plus two users so the non-reviewer case can be exercised against a real
reaction, and that fixture was not built in this pass. §5.3 is the one that matters most: the
authorization check is what makes this feature safe rather than a way to bypass the approval gate,
and it deserves to be seen working, not only asserted.
