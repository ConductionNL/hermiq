# Design: chat-fork-and-share-link

Kind: code. Size M. `SessionController`, three new routes, two optional `Session` properties, one notification subject, a modal and a read-only view. The two schema properties are incidental to the code, so the change is `kind: code` under hydra ADR-032.

## Context at development db6b74dc

- `appinfo/routes.php:566-588` session routes.
- `lib/Controller/SessionController.php:247-290` `show()`, `:304-372` `messages()`, both owner-only on `userId`; `:460` `create()`; `:1113-1150` the 404 and 403 bodies.
- `lib/Settings/hermiq_register.json:1575-1658` `Session` (`agentsession`, version 0.2.0), with `"authorization": { "read": ["authenticated"] }` at `:1585-1589` and `participants` at `:1631-1639`; `:1659-1760` `SessionTurn`, with the same read grant.
- `lib/Service/Talk/ConversationParticipation.php:63` `mayTakeTurn()`, `:112` `permittedUids()`.
- `lib/Notification/Notifier.php:60-68` `KNOWN_SUBJECTS`.
- `lib/Service/Engine/MessageHistoryHandler.php:292` `storeMessage()`.
- `src/views/Chat.vue:294-352` the assistant message actions; `:685-718` `sessionGroups`.
- `src/manifest.json`: page `Chat` at `/chat`, a custom page.

## D1. A fork is a new session with copied turns

`POST /api/sessions/{uuid}/fork` with `{ turnUuid }`. Only the owner may fork. hermiq creates a session with the same `agentId`, `triggerOrigin: human`, the title "<source title> (fork)", and `forkedFrom: { sessionId, turnId, forkedAt }`. It then copies every turn of the source, in order, up to and including `turnUuid`, keeping `role`, `content`, `sources`, `context`, `authorId`, `authorDisplayName` and `attachments` (file references only, from `chat-attachments-and-images` when it has landed). Feedback is not copied: it was about the original answer in its original place.

The copy runs through `ObjectService::saveObject()` with the full payload per turn, since a partial save drops fields (the lesson `session-data-migration` recorded). The source session is not written at all.

`turnUuid` must belong to the source session and have `role: assistant`; anything else is a 400. Forking at a user turn would leave the new session waiting for an answer to a question it cannot see as asked.

Rejected: branches inside one session (a `parentTurnId` and a switcher), the way n8n and Dify do it. It changes how every turn is listed and searched, and it makes "delete this session" ambiguous. A new session keeps every existing surface unchanged.

## D2. A share is a list on the session, owned by the owner

`Session` gains `sharedWith`: an array of `{ type, id, sharedBy, sharedAt }`, where `type` is `user` or `group`. Only the owner can change it, through:

- `GET /api/sessions/{uuid}/shares`, the current list with display names;
- `POST /api/sessions/{uuid}/shares` with `{ type, id }`;
- `DELETE /api/sessions/{uuid}/shares/{type}/{id}`.

All three are `#[NoAdminRequired]` and check ownership in the body (ADR-005 rule 3). A non-owner gets 404, so the routes cannot tell whether a session exists.

Nextcloud's own sharing rules apply. Before adding a share hermiq checks `OCP\Share\IManager`: sharing disabled for the owner refuses, "share only with group members" refuses a user outside the owner's groups, and group sharing switched off refuses a group. The dialog searches with Nextcloud's collaborator search, so it only offers people the owner may share with.

Every change to `sharedWith` is an object save, so it is on OpenRegister's audit trail with who and when (hermiq ADR-004).

Rejected: reusing `participants`. A participant takes turns; a share recipient reads. Mixing them would let a read share turn into a write the moment the Talk bridge honours the roster.

Rejected: a share token in a URL. A token is a public link by another name: anyone who gets the URL reads the session. Nextcloud share semantics mean a named person or group, logged in.

## D3. What a recipient can do

`show()` and `messages()` admit the owner, a user in `sharedWith`, or a member of a group in `sharedWith`. Every other session method stays owner-only. The engine's turn path is unchanged: `mayTakeTurn()` does not read `sharedWith`, so a recipient cannot send a turn even with a crafted request.

A shared turn's attachments show their names. Whether the recipient can open the file is decided by Files, not by the share: a file the recipient cannot read shows "Not shared with you".

The shared view is a new manifest page `SharedSession` at `/chat/shared/:id`, `src/views/SharedSession.vue`: the thread with sources and attachments, a line "Shared by Anne de Vries on 12 September", and no composer and no actions. On `/chat` a "Shared with you" group lists sessions shared with the caller, read from `GET /api/sessions?shared=true`.

## D4. A notification tells the recipient

On a new share hermiq sends a Nextcloud notification with subject `session_shared` to the user, or to each member of the group, "Anne de Vries shared the session 'Offerte dakrenovatie' with you", linked to `/chat/shared/<uuid>`. Revoking sends nothing and removes access at once: the next read is a 404.

## D5. OpenRegister's read grant on sessions

`Session` and `SessionTurn` declare `"read": ["authenticated"]` (`hermiq_register.json:1585-1589`, and the same on `SessionTurn`). How far that opens OpenRegister's generic object API to a logged-in user of the same organisation was not verified for this change. If it lets a user read another person's session there, the owner check in `SessionController` is not the only door, and a share model that says "only these people" is not true. Task 1 checks it with a Newman call as a second user before anything else is built; if the call returns the session, narrowing the schema grant is a prerequisite and is raised with the lane rather than folded in here.

## Declarative versus imperative

`forkedFrom` and `sharedWith` are declared on `Session` in `lib/Settings/hermiq_register.json` with a register version bump. The access rule is imperative: it is a per-request check across owner, user and group membership, which `x-openregister-*` does not express for a list property. The notification is sent imperatively because it fires on a change to one list entry, not on an object lifecycle event.

## Seed data

One demo session for Gemeente Tilburg: owner `anne.devries`, agent "Inkoopassistent", title "Offerte dakrenovatie Stadskantoor", `sharedWith: [{ "type": "group", "id": "team-inkoop", "sharedBy": "anne.devries", "sharedAt": "2026-09-12T10:04:00+02:00" }]`, and a fork of it, "Offerte dakrenovatie Stadskantoor (fork)", with `forkedFrom` pointing at its third turn.

## Risks

- A group share reaches people who join the group later. That is how Nextcloud group shares behave, and the dialog says so under the group entry: "Everyone in this group, now and later".
- A fork copies content that the owner later deletes from the source. A fork is independent of its source: deleting either leaves the other untouched, and `forkedFrom` stays a plain reference that may point at nothing.
- OpenRegister's read grant is wider than the share (D5). Mitigation: checked first, raised before build.
