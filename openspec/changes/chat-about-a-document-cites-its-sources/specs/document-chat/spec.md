# document-chat Specification (delta)

## ADDED Requirements

### Requirement: A turn can be about one document (REQ-DCHAT-001)

A chat turn MUST accept an optional `scope` of `{ type: "file", fileId }`.
With that scope, retrieval MUST search only the passages of that file and
MUST read the file as the person who sends the turn; a file they cannot
open MUST refuse the turn with 404 before the model is called. A file with
no index entry MUST be read through `AttachmentTextSource` and split into
numbered passages for that turn.

#### Scenario: A question about the open document uses only that document

- GIVEN a person who can open `besluit-2026-0042.pdf` and several other files that mention the same applicant
- WHEN they ask "Wat is de beslistermijn?" with the scope set to that file
- THEN every source of the answer is `besluit-2026-0042.pdf`
- @e2e tests/e2e/spec-coverage/document-chat.spec.ts

#### Scenario: A document the person cannot open is refused

- GIVEN a file id the person cannot open
- WHEN they send a turn scoped to it
- THEN the turn is refused with 404 and no model request is made
- @e2e exclude negative API path; covered by PHPUnit (tests/Unit/Service/Engine/ContextRetrievalScopeTest.php)

### Requirement: A turn can be about a collection (REQ-DCHAT-002)

A chat turn MUST accept `scope` `{ type: "folder", fileId }`. With that
scope, retrieval MUST search only files under that folder, at any depth,
that the person can read, and MUST NOT search outside it.

#### Scenario: A question about a dossier folder stays inside it

- GIVEN a dossier folder with twelve documents and a file outside it that matches the question better
- WHEN the person asks a question scoped to the folder
- THEN every source of the answer lies under that folder
- @e2e tests/e2e/spec-coverage/document-chat.spec.ts

### Requirement: Every scoped answer cites its sources (REQ-DCHAT-003)

An answer to a scoped turn MUST mark each claim with the number of the
source it rests on, and the message's `sources` MUST list those sources in
that order, each with `file_id`, the file name, its path, the passage text
and, when known, the page number. When nothing in scope matches, the
answer MUST say that the document or collection does not answer the
question and MUST NOT answer from general knowledge.

#### Scenario: Claims point at numbered sources

- GIVEN a scoped turn whose answer uses two passages
- WHEN the answer is stored
- THEN the text carries the markers [1] and [2] and `sources` holds the two passages in that order with their file ids and pages
- @e2e exclude prompt and storage contract; covered by PHPUnit on the engine with a stub model

#### Scenario: Nothing in scope matches

- GIVEN a document about a building permit
- WHEN the person asks about parking fines with the scope set to that document
- THEN the answer says the document does not answer this, and `sources` is empty
- @e2e tests/e2e/spec-coverage/document-chat.spec.ts

### Requirement: The companion shows the scope and the sources (REQ-DCHAT-004)

On a page whose context names a file or folder, the companion MUST show
the scope as a removable chip ("Over dit document" or "Over deze map")
and send it with each turn until the person removes it. Under each answer
it MUST list the sources as links that open the file, at the cited page
where the viewer supports it.

#### Scenario: The person asks from a filinq document page

- GIVEN a person on a filinq document page with the companion open
- WHEN they ask a question
- THEN the chip reads "Over dit document", and the answer lists its sources as links to that file
- @e2e tests/e2e/spec-coverage/document-chat.spec.ts

#### Scenario: Removing the chip widens the question again

- GIVEN the chip is shown
- WHEN the person removes it and asks again
- THEN the turn carries no scope
- @e2e exclude component behaviour; covered by vitest in nextcloud-vue (CnAiChatPanel.spec.js)

### Requirement: The chat page lists the sources of every answer as links (REQ-DCHAT-005)

On hermiq's own chat page, every answer whose message carries `sources`,
scoped or not, MUST list them under the answer in the stored order, each
with its name and, for a file source, a link that opens the file
(`/f/{fileId}`), at the cited page where the viewer supports it. A source
the person can no longer open MUST show its name without a link.

Rows: `ch-citations` (hermiq matrix). Board: `hermiq/HmChat`.

#### Scenario: An unscoped answer shows linked sources

- GIVEN an agent with a knowledge context and a turn without a scope
- WHEN the answer rests on two files
- THEN the chat page lists both files under the answer, each as a link that opens it
- @e2e tests/e2e/spec-coverage/document-chat.spec.ts
