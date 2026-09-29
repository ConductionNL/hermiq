# Design: chat-work-together-in-one-session

Kind: code. Size M. No schema change: `Session.participants` exists.

## Context at development 8bd35603

- `appinfo/routes.php:571-590` session routes.
- `lib/Controller/SessionController.php`: `index()` filters `userId` only; `show()` and `messages()` refuse anyone but `userId`; `update()`, `destroy()`, `restore()` stay owner only.
- `lib/Service/Talk/ConversationParticipation.php`: `mayTakeTurn()`, `roster()`, `permittedUids()`.
- `lib/Service/Talk/TalkRoomBinding.php:280-330` `syncParticipants()` rewrites the roster for Talk-bound sessions.
- `lib/Notification/Notifier.php` fixed list of known subjects.
- `src/views/Chat.vue`, `src/api/chat.js` (`SESSIONS_BASE`).

## Decisions

- New `lib/Service/SessionParticipantService.php` holds add, remove and list. It refuses a session with a `talkRoomToken` (409), an unknown uid (400), the owner as participant (400), and any caller who is not the owner (404, the same answer as a session that does not exist, so a non-owner learns nothing). It saves the full object payload, so no other field is lost.
- `index()` runs a second `findAll` with a `participants` contains filter and tags each row `role: owner|participant`. `show()` and `messages()` admit `permittedUids()`.
- A participant cannot rename, archive, delete or restore: those keep the owner check.
- Adding someone sends the notification subject `session_participant_added` with the session title and the owner's display name.
- Chat.vue: an "Invite colleagues" item in the session menu (owner only) opens the modal, which uses `NcSelect` with the Nextcloud user search and `inputLabel`. Human turns show `authorDisplayName` when the session has participants.
