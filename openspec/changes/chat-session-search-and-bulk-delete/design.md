# Design: chat-session-search-and-bulk-delete

Kind: code. Size M. `SessionController`, one new route, `src/api/chat.js` and the session list on `src/views/Chat.vue`. No schema changes.

## Context at development db6b74dc

- `appinfo/routes.php:566-588` session routes, `:593-636` deprecated aliases on the same methods.
- `lib/Controller/SessionController.php:124` `MAX_CONVERSATION_SCAN = 1000`; `:164-233` `index()`; `:638-735` `destroy()` (archive, or delete feedback, turns, session at `:667-673`); `:749` `restore()`; `:833-894` `destroyPermanent()` (turns and session only, `:851-856`); `:979-1012` `deleteRelatedObjects()` with the `sessionId` versus `conversationId` parent key.
- `lib/Service/MemoryService.php:368-394` `recallSessions()` and `:736-758` `findMany()`, which passes `search` to `ObjectService::findAll()`.
- `src/api/chat.js:63-75` `listSessions()`, `:150-180` `archiveSession()`, `restoreSession()`, `deleteSessionPermanent()`.
- `src/views/Chat.vue:37-67` list head with "New session" and the Active and Archive tabs; `:91-158` rows and the per-row `NcActions`; `:666-718` `visibleSessions` and `sessionGroups`; `:877-893` `loadSessions()`; `:1357-1425` `archive()`, `restore()`, `openDelete()`, `onDeleted()`.
- `src/modals/SessionDeleteModal.vue:69` imports `deleteSessionPermanent`.

## D1. Search is a parameter on the list, not a second endpoint

`GET /api/sessions?search=<term>&_deleted=<bool>` keeps every existing parameter. With no `search` the method behaves exactly as today. With a term of at least two characters:

1. It scans the caller's own sessions as `index()` does now (`userId` filter, 1000 cap), which gives the set the person may see.
2. It matches titles in that set, case-insensitively.
3. It runs OpenRegister's text search over `agentsessionturn` with `@self.owner` set to the caller, the pattern `MemoryService::recallSessions()` uses, and keeps only turns whose `sessionId` is in the set from step 1.
4. It returns sessions in the list envelope, each with `match: { turnUuid, excerpt }` for the first matching turn, or `match: { title: true }` for a title hit.

Step 3 keeps a turn only when both the owner and the session check pass. A shared session's turn written by a participant has that participant as owner, and the session belongs to someone else, so it cannot leak into the participant's results through either key alone.

The deprecated `/api/conversations` alias gets the parameter for free, since it is the same method.

Rejected: a separate `/api/sessions/search` route. It would need its own copy of the ownership scan and archive split, and the two would drift. Rejected: an index of hermiq's own. ADR-003 dropped the Hermes FTS5 store on purpose; OpenRegister's search is the substrate.

## D2. The search field lives in the list head

A search field sits under the tabs (`src/views/Chat.vue:45-66`). Typing debounces for 300 ms, then reloads the current tab with the term. A result row shows the excerpt under the title. Opening a result loads the session and scrolls to the matching turn, which gets a short highlight. Clearing the field restores the grouped list. While a search is active the groups collapse into one list, headed "Results for 'woz'".

## D3. Bulk is one route with an action

`POST /api/sessions/bulk`, `#[NoAdminRequired]`, body `{ action, uuids }`, where `action` is `archive`, `restore` or `delete` and `uuids` holds 1 to 100 values. For each uuid the controller runs the same owner check as `destroy()` (gate-7). The answer lists each uuid with `ok`, `not_found` or `refused`. A uuid that is not the caller's gets `not_found`, the same as a missing one, so the route cannot probe other people's sessions.

`delete` is permanent, as the single "Delete session" item is today (`SessionDeleteModal` calls `deleteSessionPermanent`). `archive` sets the archive marker; `restore` clears it.

Rejected: `DELETE /api/sessions` with a body. Some proxies drop a DELETE body, and one route with an explicit action reads plainly in the audit trail.

## D4. One deletion routine

The single and bulk delete paths call one private routine that deletes feedback, then turns, then the session, in the order `destroy()` already uses. `destroyPermanent()` moves onto it as well. Today it skips feedback (`SessionController.php:851-856`), so permanently deleting a session leaves its feedback objects behind with a `conversationId` that points at nothing. The fix is in the file this change edits and is part of making bulk delete trustworthy.

## D5. Selection mode on the list

A "Select" button in the list head turns on a checkbox per row. With one or more rows selected a bar appears above the list: "3 selected", then Archive (or Restore on the Archive tab), Delete and Cancel. "Select all shown" selects the rows currently rendered, including search results. Delete opens `src/modals/SessionBulkDeleteModal.vue`, which says "Delete 3 sessions? This cannot be undone." When the open session is among those deleted or archived, the thread column returns to the start surface.

Keyboard: space toggles the focused row's checkbox, and the bar's buttons are reachable in tab order (hydra ADR-059).

## Declarative versus imperative

Nothing here fits a declarative `x-openregister-*` construct. Search is a read over two schemas scoped to one person, and bulk actions are request-time owner checks. No lifecycle, aggregation, notification or relation behaviour is added.

## Risks

- A person with more than 1000 sessions cannot find the oldest ones. The cap is inherited from `index()`; this change keeps it and says so in the result envelope with `truncated: true`, so the page can say "Only your 1000 most recent sessions were searched."
- OpenRegister's text search does not reach a turn's `content` field. Mitigation: a Newman check seeds a turn with a known word and searches for it before the frontend work starts.
- A bulk request of 100 permanent deletes is slow. Mitigation: the cap of 100, and the page disables the bar and shows progress until the answer arrives.
