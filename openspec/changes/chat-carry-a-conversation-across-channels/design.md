# Design: chat-carry-a-conversation-across-channels

Kind: code. Size S to M. No new schema; one optional property on `SessionTurn`, a mirror step after a Chat-page turn, and three small changes on `/chat`. Not a canvas app (decision 75): no board.

## Context at development 0ff37a0b

- `lib/Service/Talk/TalkRoomBinding.php:106` `findByRoomToken()`, `:195` `bind()`, `:384` `createBound()` with the fixed title "Talk conversation".
- `lib/Service/Talk/TalkSessionRoom.php:237` writes `talkRoomOrigin = created`; `:313` renames only a created room.
- `lib/Service/Talk/TalkTurnService.php:93` `runTurn()`, the Talk side of a turn; it posts the answer into the room.
- `lib/Controller/ChatController.php` and `lib/Controller/ChatStreamController.php`: the Chat-page side of a turn; neither reaches Talk.
- `lib/Settings/hermiq_register.json`: `Session` (`agentsession`) carries `talkRoomToken` and `talkRoomOrigin`; `SessionTurn` carries `authorId` and `authorDisplayName`.
- `src/views/Chat.vue:1351` `canInvite()` already reads `session.talkRoomToken`.

## D1. One session, two doors

The session stays the single store of the conversation. Talk and the Chat page are two doors onto it. Nothing is copied between sessions. That keeps history, memory, feedback and audit in one place, and it matches the existing rule that a room resolves to one bound session.

Rejected: a "move to desktop" action that copies the thread into a new session. It leaves two half-conversations and two audit trails.

## D2. A Chat-page turn is mirrored into the room

After the engine stores the answer of a turn sent through `ChatController` or `ChatStreamController`, and the session has a `talkRoomToken`, hermiq posts two messages into the room through the agent's bot: the question, prefixed "<display name> via Hermiq:", and the answer. The mirror runs as a queued job (`MirrorTurnToTalkJob`) so the Chat page never waits on Talk, the same rule as "A reply never blocks the person who sent it" in `talk-chat-bridge`.

The mirror is best-effort: a Talk failure is logged and never fails the turn. A mirrored message carries a marker in its bot metadata, and the bot listener ignores messages with that marker, so a mirrored question never becomes a second turn.

Only turns of a human sent on `/chat` are mirrored. Turns that came from Talk are already in the room.

## D3. Where a turn was typed

`SessionTurn` gains the optional `channel` (`talk` or `hermiq`, unset reads as `hermiq`). `TalkTurnService::runTurn()` writes `talk`; the Chat-page path writes `hermiq`. The session detail shows a small "via Talk" line under a turn typed in Talk. This is declared in `hermiq_register.json` with a register version bump; the import is gated on `info.version`.

## D4. Finding the other door

- On `/chat`, a session with a `talkRoomToken` shows a Talk icon in the list and an "Open in Talk" action in its menu, linking to `/call/<token>`.
- A bound session takes the room's display name as its title at binding time and when the room is renamed, while `talkRoomOrigin` is `bound` and the owner has not renamed the session. A rename by the owner wins and is never overwritten.
- The agent's first answer in a room that has no earlier answer from that agent ends with "Continue in Hermiq: <absolute URL to /chat?session=<uuid>>". Later answers do not repeat it.

## D5. Who may continue where

Nothing changes in who may take a turn. The owner and the listed participants may take a turn through either door (`talk-shared-sessions`, "A session may be taken up by its owner or a listed participant"). A room member who is not on the roster still cannot continue the session on `/chat`, because the Chat page reads the stored roster, never live room membership.

## Declarative versus imperative

`SessionTurn.channel` is declared. The mirror is imperative: it reacts to one turn on one session and posts through Talk's bot API, which no `x-openregister-*` extension covers. The title follow-up is imperative inside the existing `TalkRoomBinding` service.

## Seed data

One demo session for Gemeente Tilburg: owner `anne.devries`, agent "Inkoopassistent", bound to the room "Inkoop overleg", with two turns typed in Talk (`channel: talk`) and one typed on `/chat` (`channel: hermiq`).

## Risks

- A busy room gets the mirrored question and answer as two bot messages. That is the cost of one shared thread; the owner who prefers not to can unbind the room.
- A mirror loop if the marker is lost. Mitigation: the listener ignores every message whose actor is the agent's own bot, besides the marker.
- A long answer exceeds Talk's message size. The mirror posts the first 30,000 characters and a link to the session for the rest.
