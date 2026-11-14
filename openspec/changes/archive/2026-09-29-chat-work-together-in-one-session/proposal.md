---
kind: code
depends_on: []
---

# Proposal: chat-work-together-in-one-session

## Summary

The owner of a session on `/chat` invites named colleagues into it. An invited colleague finds the session under "Shared with me" on `/chat`, reads it, and asks the agent questions in it; every turn shows who asked. The owner can take a colleague off the list at any time. Today this only works inside a Nextcloud Talk room, where the roster follows the room's members.

## Why

Row `hermiq:ch-shared-session` (area chat, own rating partial, state building), decided build on 2026-09-29: core area (chat) and two competitors rate yes.

Competitor cells rated yes, quoted from the matrix:

- Open WebUI: "Channels put colleagues and models in one timeline: backend/open_webui/routers/channels.py:976 model_response_handler answers @mentioned models (:1244) in rooms and threads".
- Hermes Agent: "gateway/config.py:574-596 thread_sessions_per_user false by default, so everyone in a thread shares one session with the agent".

## What hermiq already has (development 8bd35603)

- `Session.participants`, a list of user ids that may take a turn besides the owner (`lib/Settings/hermiq_register.json`, `agentsession`), and the read rule that lets a listed participant read the session object in OpenRegister.
- `ConversationParticipation::mayTakeTurn()` (`lib/Service/Talk/ConversationParticipation.php:63`), enforced in `ChatController` (send, stream, feedback) and inside `Engine` (`lib/Service/Engine/Engine.php:304`).
- Per-turn authorship (`authorId`, `authorDisplayName`) and speaker-scoped files and credentials, from the archived `talk-chat-bridge`.
- `TalkRoomBinding::syncParticipants()` fills the roster from a Talk room's members. That is the only writer of `participants`.

## What is missing, and what this change builds

1. No way to set the roster outside Talk. Build `GET`, `POST` and `DELETE /api/sessions/{uuid}/participants`, owner only, with a notification to each person added.
2. `SessionController::index()`, `show()` and `messages()` admit the owner only (`userId` filter and checks at `:164-246`, `:247-290`, `:304-372`), so a participant cannot open the session on `/chat` even though the engine would accept their turn. Build participant read access on those three, and a "Shared with me" group in the session list.
3. `/chat` has no invite action and does not show who asked a turn. Build `src/modals/SessionParticipantsModal.vue` and an author line on each human turn in a session with more than one person.

## Out of scope

- Talk-bound sessions keep their room-driven roster. The new routes refuse a session with a `talkRoomToken`: invite the person to the room instead.
- Read-only sharing (`chat-fork-and-share-link`). A participant here writes.
- Groups as participants. The roster is user ids, as the schema already defines it.
