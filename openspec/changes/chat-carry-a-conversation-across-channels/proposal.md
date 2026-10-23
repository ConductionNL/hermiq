---
kind: code
depends_on: []
---

# Proposal: chat-carry-a-conversation-across-channels

## Summary

A conversation with an agent can start in a Nextcloud Talk room and go on in the Chat page, or the other way round, without losing the thread. A session bound to a Talk room shows on `/chat` with the room's name and a Talk badge. A question typed there is answered in both places, so the room keeps the whole conversation. From the Chat page you open the room with one click, and the agent's first answer in a room carries a link back to the session on `/chat`.

## Why

One row of hermiq's capability matrix has no change yet.

| row | own rating | what the row says |
|---|---|---|
| `hermiq:dm-cross-channel` | unknown | "Carry one conversation over from one channel to another, such as from a messenger to the desktop." |

The row's note: "lib/Settings/hermiq_register.json Session.talkRoomOrigin shows a Hermiq chat session can own or bind a Talk room, so one session spans the Chat page and Talk; whether a user can pick up in the Chat page a session begun in Talk was not traced to a page".

Demand: https://github.com/NousResearch/hermes-agent/issues/4335. Hermes Agent rates partial (cross-origin resume is admin-only in its gateway), Dify partial; the other four competitors rate no.

## What hermiq already has

- A session for a Talk-enabled agent creates and owns its room (`openspec/specs/talk-agent-sessions`, `lib/Service/Talk/TalkSessionRoom.php`).
- A message in a room becomes a turn on the bound session and is answered in the room (`openspec/specs/talk-chat-bridge`, requirement "A room message becomes a turn on the bound session and is answered in the room").
- First contact in a room opens a bound session: `TalkRoomBinding::createBound()` (`lib/Service/Talk/TalkRoomBinding.php:384`) saves an `agentsession` with `userId`, `agentId`, `talkRoomToken`, `participants` and the fixed title "Talk conversation".
- The Chat page lists the caller's readable sessions (`SessionController::index()`, `lib/Controller/SessionController.php:165`), so a bound session is in the list, under the title "Talk conversation".
- A turn sent from the Chat page runs through `ChatController` and the engine and is never posted to the bound room. Nothing in `lib/Controller/ChatController.php` or `ChatStreamController.php` reaches Talk. So a session continued on `/chat` and the room drift apart.

## What this change builds

1. A bound session's title follows its room's name when Hermiq did not create the room, instead of "Talk conversation".
2. On `/chat`, a session with a room shows a Talk badge, the room name and an "Open in Talk" action.
3. A turn sent from `/chat` in a session with a room is mirrored into that room: the question as "<name> via Hermiq: ..." and the answer as the agent's bot.
4. The agent's first answer in a room carries a link "Continue in Hermiq" to `/chat?session=<uuid>`.
5. The session detail on `/chat` shows per turn where it was typed: Talk or Hermiq.

## Out of scope

- Channels outside Nextcloud (WhatsApp, Telegram, Teams, e-mail). Those reach hermiq through integriq, and carrying a thread over there needs its own decision on identity matching.
- Moving a session between agents.
- Merging two existing sessions into one.
- The Nextcloud Assistant side panel. It keeps its own conversation store (`taskprocessing-consume-ui`).
