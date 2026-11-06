# Tasks: chat-about-a-document-cites-its-sources

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 16. -->

Kind: code. Size M. Rows `filinq:wk-ai-chat`, `ch-citations` (hermiq).

## Implementation tasks

### Task 1: A turn carries a scope
- **spec_ref**: `openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-one-document-req-dchat-001`
- **files**: `lib/Controller/ChatController.php` (`extractMessageRequestParams()`), `lib/Controller/ChatStreamController.php`, `lib/Service/Engine/Engine.php`
- **acceptance_criteria**:
  - GIVEN a turn with `scope: {type: "file", fileId}` WHEN it is parsed THEN the scope reaches `retrieveContext()` unchanged; any other shape is dropped with a 400
- [ ] Implement
- [ ] Test (PHPUnit on the controller parsing)

### Task 2: Retrieval honours a file or folder scope
- **spec_ref**: `openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-a-turn-can-be-about-a-collection-req-dchat-002`
- **files**: `lib/Service/Engine/ContextRetrievalHandler.php` (`searchScoped()`), new `lib/Service/Chat/ScopedPassageSource.php` (reads an unindexed file through `AttachmentTextSource` as the speaker and splits it into numbered passages with page numbers), `tests/Unit/Service/Engine/ContextRetrievalScopeTest.php`
- **acceptance_criteria**:
  - GIVEN a folder scope WHEN results come back from inside and outside the folder THEN only the inside ones are kept
  - GIVEN a file the speaker cannot open WHEN the turn is scoped to it THEN 404 and no model call
- [ ] Implement
- [ ] Test

### Task 3: The answer cites numbered sources, or says nothing matched
- **spec_ref**: `openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-every-scoped-answer-cites-its-sources-req-dchat-003`
- **files**: `lib/Service/Engine/ContextAssembler.php` (numbered passages and the citation instruction), `lib/Service/Engine/ResponseGenerationHandler.php` (stores `sources` in citation order with `page`), `lib/Controller/ChatController.php` (`serializeMessage()`), `tests/Unit/Service/Engine/ScopedCitationTest.php`
- **acceptance_criteria**:
  - GIVEN two passages used WHEN the answer is stored THEN markers [1] and [2] match `sources[0]` and `sources[1]`
  - GIVEN no passage in scope WHEN the turn runs THEN the model is not asked to answer from general knowledge and the reply says the document does not answer it
- [ ] Implement
- [ ] Test

### Task 4: The companion shows the scope chip and the sources (nextcloud-vue)
- **spec_ref**: `openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-the-companion-shows-the-scope-and-the-sources-req-dchat-004`
- **files**: ConductionNL/nextcloud-vue `src/components/CnAiCompanion/CnAiChatPanel.vue` (scope from `context.fileId` and `pageKind`, removable chip, `scope` on each send), `src/components/CnAiCompanion/CnAiMessageList.vue` (source list under an answer, links through `generateUrl('/f/{fileId}')`), `tests/components/CnAiChatPanel.spec.js`; Dutch and English strings
- **acceptance_criteria**:
  - GIVEN a context with a `fileId` WHEN the panel opens THEN the chip reads "Over dit document" and the next send carries the scope
  - GIVEN an answer with sources WHEN it renders THEN each source is a link with the file name and page
- [ ] Implement (PR in ConductionNL/nextcloud-vue, release, bump the hermiq and filinq locks)
- [ ] Test

### Task 5: filinq passes the open document or folder to the companion
- **spec_ref**: `openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-the-companion-shows-the-scope-and-the-sources-req-dchat-004`
- **files**: ConductionNL/filinq `src/App.vue` (companion page context) and the document and dossier views that know the open file id
- **acceptance_criteria**:
  - GIVEN a filinq document page WHEN the companion opens THEN its context carries that document's `fileId` and `pageKind: "document"`; on a dossier, the dossier folder's id and `pageKind: "folder"`
- [ ] Implement (PR in ConductionNL/filinq)

### Task 6: The chat page links every answer's sources
- **spec_ref**: `openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-the-chat-page-lists-the-sources-of-every-answer-as-links-req-dchat-005`
- **files**: `src/views/Chat.vue` (the `chat-page__sources` list: a link through `generateUrl('/f/{fileId}')` for `type === 'file'`, page when present), `tests/javascript/views/ChatSources.spec.js`
- **acceptance_criteria**:
  - GIVEN an unscoped answer with two file sources WHEN it renders THEN each source is a link to its file; a non-file source shows its name only
- [ ] Implement
- [ ] Test

### Task 7: End to end
- **spec_ref**: `openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-every-scoped-answer-cites-its-sources-req-dchat-003`
- **files**: `tests/e2e/spec-coverage/document-chat.spec.ts`, `docs/features/document-chat.md`
- **acceptance_criteria**:
  - The four `@e2e` scenarios pass on the dev instance with a stub model; docs show the chip and the source list
- [ ] Implement
- [ ] Test
