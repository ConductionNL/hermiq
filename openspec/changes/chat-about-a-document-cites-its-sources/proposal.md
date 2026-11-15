---
kind: code
depends_on: [chat-attachments-and-images, session-entity-links]
---

# Proposal: chat-about-a-document-cites-its-sources

## Summary

A person on a page that shows a document opens the companion and asks about
that document; on a folder or dossier they ask about everything in it. The
answer is drawn only from that document or that collection, and every claim
points at the passage it came from. Under the answer the companion lists the
sources as links that open the file.

## Why

Capability row `filinq:wk-ai-chat` ("Ask questions about one document or a
whole collection in an AI chat that cites its sources"), state specified,
provider hermiq. The coverage audit of 30 October 2026 found that
`companion-everywhere`, the change the row cited, holds only mount and
bundle-size checks and no build task for either half of the row.

What is there today, read on `development`:

- `ChatController::sendMessage` returns `sources` per message
  (`serializeMessage()`), filled by `ContextRetrievalHandler::retrieveContext()`
  with `file_id`, `file_path` and the matched `text`.
- The companion in `@conduction/nextcloud-vue` (`CnAiChatPanel`) receives the
  page context `{ appId, pageKind, fileId, route }` but renders no sources
  (`CnAiMessageList.vue` has no source list).
- Retrieval searches every file the agent may reach. Nothing limits a turn to
  one document or one folder, so "about this document" cannot be asked.
- `chat-attachments-and-images` lets a person attach a file to a turn, which
  reads it whole; it does not scope retrieval and does not cite passages.

Competitor reference from the row: paperless-ngx scopes its chat to one
document when an id is given and lists the referenced documents under the
answer (`src/documents/views.py:2410`, `chat.component.html:16`).

## What changes

- A turn carries an optional `scope`: `{ type: "file", fileId }` or
  `{ type: "folder", fileId }`. The companion fills it from the page context
  when the page shows a file or a folder, and the person can clear it.
- Retrieval with a scope searches only that file, or only the files under
  that folder the speaker can read. A file without an index entry is read
  through `AttachmentTextSource` and split into numbered passages.
- The engine asks the model to mark each claim with the number of its
  passage, and the stored `sources` keep that order, with page or passage
  number. An answer for which nothing in scope matched says so and does not
  answer from general knowledge.
- The companion renders the sources under the answer as links that open the
  file in Nextcloud at the cited page where the viewer supports it.

## Out of scope

- Indexing policy (which folders hermiq vectorises): `vector-rag` and
  `memory-retrieval-scope`.
- "How this answer came about" (model, tools, filters): `chat-how-an-answer-came-about`.

## Impact

hermiq: `ChatController`, `ChatStreamController`, `ContextRetrievalHandler`,
a new `ScopedPassageSource`, the engine's prompt assembly. Cross-repo:
`@conduction/nextcloud-vue` `CnAiChatPanel` and `CnAiMessageList` (scope chip
and source list); filinq passes `fileId` in the companion's page context on
its document and dossier pages.
