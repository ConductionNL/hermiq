---
kind: code
depends_on: [talk-chat-bridge, talk-approval-reactions-schema]
---

# Proposal: talk-approval-reactions

## Summary

Let a human resolve a pending approval by reacting to the agent's message in Nextcloud Talk —
👍 approves, 👎 denies — so a gated run can be released from a phone without opening Hermiq.

The machinery already exists on both sides and has never been connected: spreed invokes bots on
reactions (`BotService::afterReactionAdded`) exactly as it does on messages, and Hermiq already
has a full approval lifecycle (`ApprovalService`, `Approval` objects with
`pending`/`approved`/`denied`, an approvals inbox, and a notification channel that already posts
into Talk). This change is the wire between them.

## Motivation

The human-approval gate is Hermiq's EU AI Act Art. 14 oversight mechanism: a run that needs a
person's say-so stops and waits. Today "waiting" means the reviewer gets a notification, then has
to open Hermiq's approvals inbox in a browser to act.

That is a poor fit for the thing being asked. An approval is a one-bit decision — yes or no —
made by someone who is often not at a desk, and the cost of friction is not a worse UI, it is a
run that sits blocked for hours. Meanwhile `DeliveryService` already posts the approval request
into Talk, so the reviewer usually *sees* the request on their phone and then cannot act on it.

A reaction is exactly the right shape for a one-bit decision, and it is the interaction Talk
already offers on every message.

## Affected Projects

- [ ] Project: `hermiq` — a reaction listener on the existing bot, an approval↔message binding so
  a reaction can be resolved to the approval it decides, and the decision path that applies it.

## Scope

### In Scope

- **Post approval requests as the bot** into the room the reviewer already receives them in, and
  record which message carries which approval.
- **Resolve a reaction to an approval**: 👍 approves, 👎 denies. Any other emoji is ignored.
- **Authorize the reactor** — only the approval's resolved reviewer (or a member of the reviewer
  group) may decide. A reaction from anyone else is ignored, not applied.
- **Apply the decision** through the existing `ApprovalService` path, so a Talk-originated
  decision is indistinguishable downstream from an inbox-originated one — same audit trail, same
  `decidedBy`/`decidedAt`.
- **Confirm in the room** what happened, and make an already-decided approval a visible no-op
  rather than a silent one.
- **Removing a reaction does NOT undo a decision.** An approval is a governance record, not a
  toggle.

### Out of Scope

- **Approving anything other than an existing pending approval.** This does not create approvals
  or change which runs need one.
- Threaded/inline approval dialogs, buttons, or Talk "interactive messages".
- Approving from a notification (that is Nextcloud's notification actions, a different surface).
- Any change to who must approve, or to the gate's semantics.

## Approach

When the approval notifier posts a request into a Talk room, it records the resulting message id
on the `Approval` object. A new listener on the same in-process bot handles spreed's reaction
invocations (`type: 'Like'` for add, `'Undo'` for remove), resolves the reacted-to message id back
to its approval, checks the reactor is the reviewer, and applies the decision through
`ApprovalService`.

The reaction handler runs in the same synchronous bot-listener context as the message bridge, so
it does only cheap work — resolve, authorize, decide, confirm. Applying an approval is a small
object write, not a model call, so unlike a chat turn it does not need to leave the request.

## New Dependencies

None. Talk remains an optional runtime dependency, probed exactly as the chat bridge probes it.

## Impact

- **New:** a reaction listener, an approval↔Talk-message binding, and the decision path.
- **Modified:** the approval notifier gains "record where I posted this". The `Approval` schema
  fields it writes are added by the `talk-approval-reactions-schema` predecessor (ADR-032 chain),
  not here.
- **Behaviour when Talk is absent or the bot is not enabled:** unchanged — approvals continue to
  work exactly as they do now, through the inbox.

## Cross-Project Dependencies

- **spreed** — consumed. `BotService::afterReactionAdded` / `afterReactionRemoved` invoke bots
  with `type: 'Like'` / `'Undo'`, carrying the reacted-to message in `object` and the reactor in
  `actor`. Same optional-dependency posture as `talk-chat-bridge`.
- **`talk-chat-bridge`** — must ship first; this reuses its bot, its availability probe and its
  posting path.
- **`talk-approval-reactions-schema`** — must ship first; the reaction handler resolves an
  approval BY its recorded Talk message id, which therefore has to be a filterable top-level
  property (the lesson `talk-chat-bridge-schema` paid for: nested dot-path filters return zero
  rows silently).

## Risks

### Risk 1: Anyone in the room can react

**Severity:** High — **Mitigation:** a reaction is a public, one-click act available to every
participant, so without an authorization check the gate would be trivially bypassable by a
bystander. The reactor MUST be resolved against the approval's reviewer (user or group) before any
decision is applied, and a reaction from anyone else is ignored. This is the single reason this
change could be worse than no change, and it gets a direct negative test.

### Risk 2: An emoji is a lossy record of intent

**Severity:** Medium — **Mitigation:** 👍 and 👎 only; every other emoji is ignored rather than
guessed at. The audit entry records that the decision arrived by reaction, so a reviewer of the
record can tell a one-click approval from a considered one in the inbox.

### Risk 3: Un-reacting looks like undoing

**Severity:** Medium — **Mitigation:** spreed dispatches reaction *removal* too, and a user will
reasonably expect removing 👍 to take it back. It will not: the run has already been released.
The removal is acknowledged in the room with an explicit "this cannot be undone" note rather than
silently ignored, because silence here reads as "it worked".

### Risk 4: A stale request is re-decided

**Severity:** Low — **Mitigation:** an approval that is no longer `pending` is a no-op, and says
so in the room.

## Rollback Strategy

Uninstalling or disabling the bot stops reaction dispatch along with message dispatch, exactly as
for the chat bridge. Reverting the code leaves the recorded message ids on existing approvals as
inert data. Decisions already applied stay applied — they are governance records, and unwinding
them is not a rollback concern.

## Open Questions

- **Should the reviewer group's members each be able to decide, or only the first responder?**
  Provisionally: any member may decide, and the first decision wins — matching the inbox, where
  the same is already true.
