---
kind: code
depends_on: [session-api-rename, session-frontend-rename]
---

# Proposal: chat-fork-and-share-link

## Summary

On any answer in a session you can choose "Fork from here": hermiq starts a new session with the same agent and every turn up to that answer, and the original stays as it was. You can share a session read-only with named colleagues or groups, the way you share a file in Nextcloud, and take the share back at any time. A colleague opens the shared session from the notification or from "Shared with you" on `/chat`, reads it, and cannot add to it or change it. There is no public link.

## Why

Two rows of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-fork-chat` | no | build: core area (chat); changelog demand and two competitors rate yes |
| `hermiq:ch-share-link` | no | build: core area (chat); one competitor rates yes (open-webui) |

Demand row:

- `dm-fork-chat`: changelog https://github.com/open-webui/open-webui/releases/tag/v0.11.0

Competitor cells rated yes, quoted from the matrix:

- `dm-fork-chat`, n8n: "editing or regenerating a message keeps the original and adds a branch (packages/cli/src/modules/chat-hub/chat-hub-message.entity.ts:103-114 previousMessageId, retryOfMessageId, revisionOfMessageId; chat-hub.controller.ts:224,283 edit and regenerate)".
- `dm-fork-chat`, Open WebUI: "backend/open_webui/routers/chats.py:1672 POST /chats/{id}/fork copies the chat up to a message and records the branch (utils/chat_fork.py:4 build_fork_history); fork button per response src/lib/components/chat/Messages/ResponseMessage.svelte:1528 (CHANGELOG.md:665)".
- `ch-share-link`, Open WebUI: "backend/open_webui/routers/chats.py:1945 POST /chats/{id}/share, :1208 GET /chats/share/{share_id}, :2029 access grants on the shared chat; src/lib/components/chat/ShareChatModal.svelte:30 shareChatById and 'Copy Share Link'; src/routes/s/[id]/+page.svelte viewer".

## What hermiq already has

- Session routes for create, read, rename, archive, restore and delete (`appinfo/routes.php:566-588`). None copies a session or grants read access to someone else.
- `SessionController::show()` and `messages()` admit only the owner, by `userId`, and answer 403 to anyone else (`lib/Controller/SessionController.php:247-290`, `:304-372`, 403 body at `:1132-1150`).
- `Session.participants` lets named users take a turn in a shared Talk session (`lib/Settings/hermiq_register.json:1631-1639`), read through `ConversationParticipation::mayTakeTurn()` (`lib/Service/Talk/ConversationParticipation.php:63`). A participant writes; nothing lets a person read without writing.
- The Chat page's per-message actions are feedback and "Save as skill" (`src/views/Chat.vue:294-352`, `:329-330`). There is no fork action.
- `Notifier` renders a fixed list of subjects, none for a shared session (`lib/Notification/Notifier.php:60-68`).

## What this change builds

1. `POST /api/sessions/{uuid}/fork` with a turn uuid: a new session owned by the caller, with the same agent, a copy of every turn up to and including that turn, and `forkedFrom` recording the source session and turn.
2. "Fork from here" on each assistant answer on `/chat`, which opens the new session ready for the next question.
3. Read-only shares on a session: `sharedWith`, a list of users and groups, managed by the owner through `GET`, `POST` and `DELETE /api/sessions/{uuid}/shares`, honouring the instance's Nextcloud sharing rules.
4. Read access for a share recipient on the session and its turns, and nothing else: no turn, no rename, no archive, no delete, no fork.
5. A share dialog (`src/modals/SessionShareModal.vue`) with the Nextcloud sharee search, a notification to each new recipient, and a "Shared with you" group on `/chat` that opens a read-only view.

## Out of scope

- A public link that works without logging in. It is not offered. An organisation that wants one later needs its own decision on exposure and retention.
- Letting a recipient take part. That is `Session.participants` and the Talk bridge (`talk-shared-sessions`), unchanged.
- Forking by a recipient into their own session. A recipient reads; the owner forks.
- Branches inside one session with a switcher between alternatives, as n8n and Dify do. A fork is a new session, which keeps the list and the search from `chat-session-search-and-bulk-delete` simple.
