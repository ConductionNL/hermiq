---
kind: code
depends_on: [session-api-rename, session-frontend-rename]
---

# Proposal: chat-session-search-and-bulk-delete

## Summary

On `/chat` you can search your own sessions by title and by what was said in them, and open a result at the turn that matched. You can select several sessions in the list and archive, restore or delete them in one action, instead of one by one. Both work on the sessions the list already shows you, and nothing else.

## Why

Two rows of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:ch-search-history` | no | build: core area (chat); two competitors rate yes |
| `hermiq:dm-bulk-delete-chats` | no | build: core area (chat); a featureRequest demand row and two competitors rate yes |

Demand row:

- `dm-bulk-delete-chats`: featureRequest https://github.com/nextcloud/assistant/issues/639

Competitor cells rated yes, quoted from the matrix:

- `ch-search-history`, Hermes agent: "hermes_cli/web_routers/sessions.py:268 GET /api/sessions/search behind web/src/i18n/en.ts sessions.searchPlaceholder 'Search message content'; tools/session_search_tool.py:1-9 FTS5 recall over the session DB for the agent".
- `ch-search-history`, Open WebUI: "backend/open_webui/routers/chats.py:865 GET /chats/search over title and message content with snippet (:178 chat_search_snippet); src/lib/components/layout/SearchModal.svelte 'Search Chats'".
- `dm-bulk-delete-chats`, Hermes agent: "hermes_cli/web_routers/sessions.py:414-428 POST /api/sessions/bulk-delete deletes a list of ids, driven by the selectable rows in web/src/pages/SessionsPage.tsx:1395 via web/src/lib/api.ts:504-505".
- `dm-bulk-delete-chats`, Open WebUI: "backend/open_webui/routers/chats.py:707 DELETE / removes all of a user's chats, called from src/lib/components/chat/Settings/DataControls.svelte:110-135 'Delete All Chats'".

## What hermiq already has

- The session routes: list, show, turns, rename, archive, restore and permanent delete, one uuid each (`appinfo/routes.php:566-588`), with deprecated `/api/conversations/*` aliases on the same methods (`:593-636`).
- `SessionController::index()` lists the caller's sessions by `userId`, sorted by last update, scanning at most 1000 and splitting active from archived in PHP (`lib/Controller/SessionController.php:164-233`, cap at `:124`). It takes no search term.
- `SessionController::destroy()` archives an active session and deletes an archived one with its feedback and turns (`:638-735`, the order at `:667-673`). `destroyPermanent()` deletes the turns and the session, but not the feedback (`:833-894`, the delete at `:851-856`).
- The Chat page lists sessions in groups ("Started by you", "Started automatically") with a per-row action menu: Continue, Archive session or Restore session, Delete session (`src/views/Chat.vue:91-158`, `:685-718`). Delete opens `SessionDeleteModal` for one session (`src/views/Chat.vue:461-465`, `src/modals/SessionDeleteModal.vue:69`).
- `src/api/chat.js:63-75` `listSessions()` sends only `_deleted`, `limit` and `offset`.
- `MemoryService::recallSessions()` already runs an OpenRegister text search over session turns, scoped to the caller with `@self.owner` (`lib/Service/MemoryService.php:368-394`). That is the agent's recall, not a person's search, but it shows the search substrate hermiq uses (ADR-003).

The session rename chain (`session-schema-declaration`, `session-data-migration`, `session-api-rename`, `session-frontend-rename`) has every task ticked at db6b74dc and shipped in #870, but is not archived yet. This change builds on its routes and its list, hence the `depends_on`.

## What this change builds

1. A search term on the session list: `GET /api/sessions?search=...` matches the caller's own sessions by title and by turn text, and returns each match with a short excerpt and the uuid of the turn that matched.
2. A search field above the session list on `/chat`, which keeps the active and archive tabs and highlights the matching turn when a result is opened.
3. `POST /api/sessions/bulk` with an action (`archive`, `restore`, `delete`) and up to 100 session uuids, checked one by one as the caller's own, answered per uuid.
4. Selection mode in the session list: a checkbox per row, "Select all shown", and a bar with Archive, Restore or Delete for the selection. Delete asks for confirmation with the number of sessions.
5. One deletion routine shared by the single and the bulk path, which removes feedback, turns and then the session.

## Out of scope

- Searching other people's sessions, including sessions you only take part in. The list shows your own sessions, and search matches the list.
- The agent's own recall of past sessions (`hermiq.recallMemory`). It already exists and is not changed.
- Semantic search over sessions. Keyword search on OpenRegister's text search is enough for "find the chat where I asked about the WOZ bezwaar". The vector substrate belongs to `vector-rag`.
- What happens to a Talk room when its session is deleted. That is the Talk bridge's rule, unchanged here.
