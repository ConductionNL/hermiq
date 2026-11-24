---
kind: code
---

## Why

The AI companion now follows the user onto every page, including office editors
showing a specific document. It still opens the same undifferentiated chat it would
open on the dashboard: a flat list of the five most recent conversations, none of
which is necessarily about the document on screen.

The thing a user wants when they click the hex on `subsidiebesluit.docx` is **"what
have I already said about THIS document"**, and nothing in the model can answer that,
because a conversation records no relationship to the thing it was about.

## What Changes

- **A session records what it is about.** Zero or more links from a chat thread to
  the entities it concerns — a Nextcloud file, an OpenRegister object of any schema,
  a contact, a document.
- **Opening the companion in a context pre-filters the session list.** With a
  `contextFileId` (or object reference) present, the recent list is headed
  *"Recent sessions about this {entity label}"*, and the unfiltered list stays one
  click away behind *View all sessions*.
- **A link is recorded when it is used, not only when it is declared.** Sending a
  turn from a page that carries a context creates the link if it does not exist, so
  the history builds itself from ordinary use rather than from a curation step
  nobody performs.
- **Per-session settings** reachable from the window's titlebar, where the links are
  visible and removable — a link recorded automatically must be removable manually,
  or the feature is a tracker rather than a convenience.

## What this is NOT

**Not the `Context` schema.** `Context` already exists and is a *named, reusable,
budgeted bundle* of files and object-queries resolved into a prompt preamble — a
thing an author curates once and many sessions consume. This change records the
opposite direction: an incidental, per-session fact about what a thread touched.
Conflating them would make every ad-hoc chat create a reusable Context object.

## ⚠️ The terminology rename is deliberately NOT in this change

"Sessions" is the settled term and `conversation` should disappear from the UI, the
API and the schema. That rename is **blocked on a collision this change surfaced**:

| Schema | What it is today |
|---|---|
| `Conversation` / `Message` | the live chat thread — what the UI calls a conversation |
| `Session` / `SessionTurn` | a DIFFERENT thing: the FTS5 search port for cross-session recall |

`Session` is already taken, by a schema with different properties and a different
purpose. Renaming `Conversation` → `Session` collides with it; renaming it to
something else and *then* to `Session` is two migrations. Neither is a find-and-
replace: 45 files in `lib/` say `conversation`, the API path is
`/api/chat/conversations`, and `Message.conversationId` is a stored property — a
property rename is a data migration.

Doing that rename inside this change would bury a real capability under a migration,
and doing it badly breaks every boundary where the name is a contract. It gets its
own change, `sessions-terminology`, sequenced after this one, and this change uses
**"session" in all NEW user-facing strings** so the vocabulary starts converging
immediately without a migration.

## Capabilities

### New Capabilities
- `session-entity-links`: what a chat session may be linked to, when a link is
  recorded, and how a context narrows the session list.

## Impact

- **Code**: hermiq — link persistence, the scoped-list query, the settings surface.
- **Schema**: a link property or join, declared in `lib/Settings/hermiq_register.json`
  (⚠️ `info.version` MUST be bumped or the import is skipped on every existing
  install).
- **UI**: `@conduction/nextcloud-vue` — `CnAiChatPanel`'s sessions menu gains the
  scoped heading and a settings entry.
- **Not in scope**: the `conversation` → `session` rename, and any change to
  `Context`.
