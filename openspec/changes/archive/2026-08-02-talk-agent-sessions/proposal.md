---
kind: code
depends_on: [talk-chat-bridge, talk-room-grouping, talk-approval-reactions]
---

# Proposal: talk-agent-sessions

## Summary

Make a Hermiq chat session a first-class Nextcloud Talk conversation owned by the agent that runs
it. Today every agent speaks through one shared bot (`nextcloudapp://hermiq`, name `Hermiq`), so
five different agents all render as "Hermiq (Bot)"; Hermiq never creates a Talk room, only binds
to one somebody else made; and the agent stays silent in its own room unless literally
`@Hermiq`-mentioned. This change registers **one Talk bot per Talk-enabled agent** so an agent
speaks under its own name, has **the session create and own its room** (named after the session,
with the owner and the agent's bot as participants), treats **every human message in a room Hermiq
created** as a turn while keeping the mention gate in rooms Hermiq was merely invited into, syncs
**room participants into the session roster**, and files the created room under each participant's
"Hermiq" Talk tag — including participants who join later.

## Motivation

`talk-chat-bridge` and `talk-approval-reactions` proved the transport: an agent is reachable from
Talk's mobile apps, a delivered report can be answered, and a gated run can be released with a 👍.
What they did not give is an *agent* in Talk — they gave one bot that several agents borrow.

Three consequences show up the moment more than one agent is switched on:

- **Identity.** `TalkBridge::BOT_URL` is a constant and `BOT_NAME` is `Hermiq`, so a triage agent,
  a release agent and a docs agent are indistinguishable in the room. spreed resolves a bot's
  display name at render time from the bot record, so the *only* lever on what a message is signed
  with is the bot record itself — `ChatManager::sendMessage()` takes no display-name argument
  (verified against spreed 24.0.1). One bot per agent is therefore not a nicety, it is the only
  available mechanism.
- **Ownership.** `TalkAgentBinding` maps an existing room token to an agent through an app-config
  JSON map an operator has to maintain by hand. Starting a session in Hermiq's own UI produces no
  room at all, so the Talk surface is only ever reachable if somebody first made a room and then
  wired it up. The session should make its own room.
- **Address discipline.** `isAddressed()` requires a one-to-one room, a literal `@Hermiq`, a
  rendered mention parameter, or a reply to the bot. In the agent's *own* session room that rule
  is pure friction — the room exists for one purpose and every message in it is for the agent. In
  a busy team room the same rule is exactly right, and must stay, because that is where scheduled
  reports land.

Now is the moment because the pieces this stands on all shipped and were live-verified in the last
week: the binding, the roster, the reaction path, and per-user tag grouping.

## Affected Projects

- [ ] Project: `hermiq` — per-agent Talk bot lifecycle, both `BotInvokeEvent` listeners' bot-URL
  guards, session-owned room creation and rename, participant sync, the addressing rule, tag
  grouping for late joiners, and one optional `Conversation` property.

## Scope

### In Scope

- Register one Talk bot per Talk-enabled agent: URL `nextcloudapp://hermiq-<agentId>`, name = the
  agent's name. Install when an agent becomes `talkEnabled`, rename when the agent is renamed,
  uninstall when it is disabled or deleted.
- Retire `TalkBridge::BOT_URL` as a constant. `botActorId()` becomes per-agent
  (`bot-` + `sha1(<per-agent url>)`).
- Rework the bot-URL guard in **both** `lib/Listener/TalkBotInvokeListener.php::readPayload()` and
  `lib/Listener/TalkApprovalReactionListener.php::readPayload()` from an equality test against one
  constant to "is this any Hermiq bot URL", resolving the agent from the URL.
- Creating a Hermiq chat session creates a Talk room named after the session, adds the session
  owner as a participant, enables that agent's bot in it, and stores the token on the session.
  Renaming the session renames the room.
- One new OPTIONAL `Conversation` property recording whether Hermiq created the room, plus the
  register `info.version` and `Conversation` schema version bumps and a forced re-import.
- In a room Hermiq created, every human message is a turn. In a room Hermiq did not create, the
  mention gate stays — matched against the agent's own name, tolerating multi-word names, case
  differences and trailing punctuation.
- Sync Talk room participants into `Conversation.participants`, including users invited later.
- Ensure a "Hermiq" conversation tag exists for each participant of a created room and file the
  room under it, including participants who join after creation.

### Out of Scope

- **A per-agent avatar or icon.** spreed's `oc_talk_bots_server` table has no avatar column and
  the bot install path exposes no avatar option (both verified). The `>_` bot glyph and the
  spreed-appended ` (Bot)` suffix are unavoidable for a bot actor. Recorded in design.md as an
  explicitly rejected alternative, not as a requirement.
- **Posting deep links for actions.** Approvals stay decided by 👍/👎 reactions in the room,
  because that is what makes them work in the Talk mobile app. No link-posting is added.
- Bridging to non-Nextcloud chat platforms (rejected by ADR-005), Talk threads as sessions,
  streaming into Talk, and calls/voice — all still deferred.
- Retro-creating rooms for sessions that already exist without one.
- **Any data migration of existing rooms or agents.** Hermiq is unpublished and runs on one
  development instance, whose agents and conversations have been cleared by hand down to the two
  Hydra agents and their three conversations. There is no installed base to migrate, so no repair
  step is written. The only migration is the register schema bump (see `migration.md`).

## Approach

`TalkBridge` grows a per-agent identity seam: `botUrlFor(agentId)`, `botNameFor(agent)` and
`botActorId(agentId)` replace the two constants, and a resolver maps an inbound bot URL back to an
agent id. `TalkBotInstaller` moves from a one-shot repair-step install to an agent-lifecycle
install/rename/uninstall driven by the agent's `talkEnabled` flag and name — through spreed's
`BotInstallEvent`, which is an upsert on `(url, secret)` and therefore already the rename
mechanism (verified in spreed's `BotListener`; no `occ`, no `BotService` method needed).

A new `TalkSessionRoom` service owns the room half: create-room-for-session, rename-room, add
participant, and the write-back of `talkRoomToken` + `talkRoomOrigin` onto the `Conversation`.
`TalkRoomGrouping` is reused unchanged for tag creation and extended with a late-joiner path. The
addressing rule moves from "is it a one-to-one room or a literal `@Hermiq`" to "did Hermiq create
this room, else does the text name this agent".

Everything is additive and inert until an agent is Talk-enabled; with spreed absent nothing runs.

## New Dependencies

None. No new packages, and no new spreed API beyond the `BotInstallEvent` / `BotUninstallEvent` /
`ConversationTagService` / `ParticipantService` / `RoomService` surfaces Hermiq already uses.

## Impact

- `lib/Service/Talk/TalkBridge.php` — `BOT_URL`/`BOT_NAME` constants retired; per-agent URL, name
  and actor id; agent-from-URL resolution.
- `lib/Service/Talk/TalkBotInstaller.php` — per-agent install/rename/uninstall.
- `lib/Listener/TalkBotInvokeListener.php` — bot-URL guard, agent resolution, addressing rule.
- `lib/Listener/TalkApprovalReactionListener.php` — bot-URL guard only; the reaction semantics are
  untouched. **This is the regression-risk edit** (see Risk 1).
- `lib/Service/Talk/TalkRoomBinding.php`, `TalkAgentBinding.php`, `TalkRoomGrouping.php`,
  `TalkBridgeStatus.php` — per-agent identity and created-room awareness.
- `lib/Settings/hermiq_register.json` — one optional `Conversation` property plus version bumps.
- `tests/e2e/spec-coverage/` — new Playwright specs and a shared `dismissTour` helper.

## Cross-Project Dependencies

None. Self-contained within `hermiq`; spreed is an optional runtime dependency Hermiq already
probes for.

## Risks

### Risk 1: The bot-URL guard rework sits on a path that was inert for months

**Severity:** High — **Mitigation:** `TalkApprovalReactionListener` was 100% dead code until
PR #75 and is only provable by live checks; a unit suite will stay green while the reaction path
is silently unreachable. Tasks require re-running all three live approval checks after the guard
change — the approval records room token and message id; a reviewer 👍 approves and 👎 denies with
`decidedVia=reaction`; a non-reviewer 👍 leaves it pending — not unit tests alone.

### Risk 2: Renaming a bot rewrites already-posted message history

**Severity:** Medium — **Mitigation:** spreed's `MessageParser` resolves a bot's display name at
render time from the bot record via `url_hash`, so renaming an agent retroactively re-signs its
past messages. Accepted as correct-but-surprising and documented. The sharper edge is the
*uninstall* case: the same lookup is scoped to the conversation, so a message from a bot that is
no longer in the room falls back to `<actorId>-bot` — which is the standing cost of the
uninstall-on-delete decision (design.md D2), paid every time an agent is deleted, not a one-off.
Documented rather than mitigated; there is no installed base for it to damage today.

### Risk 3: A per-agent bot changes what participants of an existing room see

**Severity:** Medium — **Mitigation:** rooms Hermiq did not create keep the mention gate, because
`talkRoomOrigin` is read from stored data and absent means `bound`. Nothing about an existing room
changes implicitly. The rule change that makes an agent answer everything is scoped strictly to
rooms Hermiq created for a session, which can only be rooms created after this change ships.

### Risk 4: Mention matching against a free-text agent name is fuzzier than a constant

**Severity:** Low — **Mitigation:** bots are not a source in spreed's collaborator search, so `@`
never autocompletes them and matching is on literal typed text. The matcher must tolerate
multi-word names, case differences and trailing punctuation, and is unit-tested on those cases; a
non-match is a silent no-turn, never an error.

### Risk 5: A forced register import is required and a non-forced one silently no-ops

**Severity:** Low — **Mitigation:** `importFromApp(force: false)` advances the register version
*without applying the schema* and still reports success. The task explicitly requires
`force: true` and a post-import read-back of the new property.

## Rollback Strategy

Three independent levers, coarsest first:

1. Uninstall the per-agent bots (`BotUninstallEvent` per agent, or `occ talk:bot:uninstall`). All
   inbound dispatch stops and Hermiq keeps working everywhere else — the same lever
   `talk-chat-bridge` shipped.
2. Turn `talkEnabled` off on the affected agents. No bot is installed, no room is created.
3. Revert the code. The one register property is OPTIONAL, additive and never in `required`, so
   reverting the code leaves existing `Conversation` objects valid; the property is simply ignored
   and every room falls back to the mention gate, which is the safe default.

Rooms Hermiq created survive a rollback as ordinary Talk rooms; nothing is deleted.
