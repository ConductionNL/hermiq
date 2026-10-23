# Tasks: memory-knowledge-bases

Kind: code. Size L. Rows `hermiq:me-kb-upload`, `me-external-kb`.

## Implementation tasks

### Task 1: The schema and the agent reference
- **spec_ref**: `openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Repair/SeedKnowledgeBases.php`
- **acceptance_criteria**:
  - GIVEN the register import WHEN it runs THEN `agentknowledgebase` exists and `Agent.knowledgeBaseRefs` is declared, and the register version is bumped
- [ ] Implement
- [ ] Test (`tests/validate-register.js`; PHPUnit on the seed step)

### Task 2: Upload into the knowledge base folder
- **spec_ref**: `openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001`
- **files**: `appinfo/routes.php`, `lib/Controller/KnowledgeBaseController.php`, `lib/Service/KnowledgeBase/KnowledgeBaseService.php`
- **acceptance_criteria**:
  - GIVEN the owner WHEN they upload two PDFs THEN both land in `folderPath` and appear as `file` sources
  - GIVEN a non-owner WHEN they upload THEN the answer is 404
- [ ] Implement
- [ ] Test (PHPUnit; Newman upload as owner and non-owner)

### Task 3: Retrieval scoped to the knowledge bases
- **spec_ref**: `openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-agent-answers-from-its-attached-knowledge-bases-req-kb-002`
- **files**: `lib/Service/Engine/ContextRetrievalHandler.php`, `lib/Service/KnowledgeBase/KnowledgeBaseScopeResolver.php`
- **acceptance_criteria**:
  - GIVEN an agent with a knowledge base WHEN a turn runs THEN the facade is called with the resolved file ids and the rows become file sources
  - GIVEN the facade absent WHEN a turn runs THEN no knowledge base search happens and the turn completes
- [ ] Implement
- [ ] Test (PHPUnit with a fake facade, present and absent)

### Task 4: The file scope and indexing state on the facade
- **spec_ref**: `openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-the-vector-facade-offers-a-file-scope-and-an-indexing-state-req-kb-005`
- **files**: `lib/Service/KnowledgeBase/KnowledgeBaseScopeResolver.php`, `lib/Service/KnowledgeBase/VectorFacadeProbe.php`
- **acceptance_criteria**:
  - GIVEN the facade without `fileIds` support WHEN hermiq probes it THEN knowledge bases report search as not available
  - GIVEN the facade with `fileIds` and `indexStatus` WHEN the detail page loads THEN each document shows its state
- [ ] Implement
- [ ] Test (PHPUnit on the probe with both facade shapes; the OpenRegister side is tracked through `vector-rag`)

### Task 5: The person-scoped read and the unshared-source notice
- **spec_ref**: `openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003`
- **files**: `lib/Service/KnowledgeBase/KnowledgeBaseScopeResolver.php`, `lib/Service/KnowledgeBase/SourceSharingInspector.php`
- **acceptance_criteria**:
  - GIVEN a folder only the owner can read WHEN a colleague's turn resolves scope THEN that folder's files are not in the scope
  - GIVEN the same folder WHEN the owner opens the detail page THEN the notice is shown
- [ ] Implement
- [ ] Test (PHPUnit with two users and a share)

### Task 6: Pages, agent form picker and integriq labels
- **spec_ref**: `openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-outside-sources-come-in-through-integriq-req-kb-004`
- **files**: `src/manifest.json`, `src/views/KnowledgeBaseDetail.vue`, `src/modals/KnowledgeBaseFormModal.vue`, `src/modals/AgentFormModal.vue`, `src/views/AgentMemory.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Memory page WHEN the owner follows "Knowledge bases" THEN the list opens, and the detail page shows sources with their indexing state
  - GIVEN a folder integriq marks as synced WHEN it is a source THEN its row names the outside system
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/knowledge-bases.spec.ts`)

## Verification
- [ ] `openspec validate memory-knowledge-bases --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance with an OpenRegister vector backend: upload a PDF, wait for indexed, ask a question it answers
