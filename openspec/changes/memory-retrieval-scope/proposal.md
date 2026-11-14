---
kind: code
depends_on: [vector-rag, memory-knowledge-bases]
---

# Proposal: memory-retrieval-scope

## Summary

An agent owner can limit which documents the agent searches by who owns them, by file name and by modified date, for example "only files from the team Juridische Zaken, changed since 1 January 2025". An organisation can mark documents as validated with a Nextcloud tag that only named groups may assign. An agent set to answer only from validated documents searches nothing else, calls no tools, and when no validated document matches it gives a fixed answer without calling the model at all. That refusal is decided by hermiq, not asked of the model.

## Why

Two rows of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-metadata-filter` | partial, built | build: changelog demand and three competitors rate yes for the missing half, file metadata filters |
| `hermiq:td-validated-sources` | partial, built | build: tender demand (Veiligheidsregio Utrecht 371293 requirement 4339) for the missing half, an enforced sources-only mode over validated documents |

Demand rows:

- `dm-metadata-filter`: changelog https://learn.microsoft.com/en-us/microsoft-copilot-studio/whats-new
- `td-validated-sources`: tender https://www.tenderned.nl/aankondigingen/overzicht/371293, "Veiligheidsregio Utrecht klantcontactsysteem (2025-03-17) requirement 4339"

Competitor cells rated yes, quoted from the matrix:

- `dm-metadata-filter`, Copilot Studio: "https://learn.microsoft.com/en-us/microsoft-copilot-studio/knowledge-add-sharepoint SharePoint metadata filters (file name, owner, modified date) refine knowledge retrieval (November 2025)."
- `dm-metadata-filter`, Dify: "api/core/rag/index_processor/constant/built_in_field.py:4-9 built-in document_name, uploader, upload_date, last_update_date, source; api/core/workflow/nodes/knowledge_retrieval/entities.py:46-48 metadata_filtering_mode disabled|automatic|manual with conditions".
- `dm-metadata-filter`, n8n: "packages/@n8n/nodes-langchain/nodes/vector_store/VectorStorePGVector/VectorStorePGVector.node.ts:185 and VectorStorePinecone/VectorStorePinecone.node.ts:29 add metadataFilterField from @n8n/ai-utilities".
- `td-validated-sources`, Copilot Studio: "https://learn.microsoft.com/en-us/microsoft-copilot-studio/agents-experience/knowledge-add-existing-copilot https://learn.microsoft.com/en-us/microsoft-copilot-studio/knowledge-add-sharepoint standard harness \"Use general knowledge\" off limits answers to added knowledge sources".

The other competitors rate `td-validated-sources` partial or no for the same reason, in Dify's words: "no hard switch that blocks answers outside validated documents, and no validated flag on documents".

## What hermiq already has

- The agent form offers "Ground responses in your data (RAG)", "Search in objects", "Search in files" and a source count (`src/modals/AgentFormModal.vue:220-241`), and the Chat page sends them per session (`src/views/Chat.vue:848-867`).
- `ContextRetrievalHandler::retrieveContext()` narrows object search by the agent's views and the caller's selection (`lib/Service/Engine/ContextRetrievalHandler.php:134-140`, `:297`), and skips object search when no view resolves (`:334-350`). Files cannot be narrowed by anything, and today no file comes back at all (see `vector-rag`).
- `Context` bundles pin named file paths and object queries with equality filters (`lib/Settings/hermiq_register.json:2973`, `:3026-3061`), resolved in `lib/Service/Engine/ContextAssembler.php:354-355`, `:394`. That is a fixed preamble, not a filter on search.
- The only limit to sources is a sentence in the prompt, added only when context was found: "If the context doesn't contain relevant information, say so honestly. Always cite which sources you used when answering." (`lib/Service/Engine/ResponseGenerationHandler.php:354-358`). With no context the model answers from its own knowledge.
- `memory-knowledge-bases` adds a file scope to the OpenRegister vector facade; this change reuses it.

## What this change builds

1. File filters on an agent: owners (users or groups), a file name pattern, and modified after and before. hermiq resolves them through Nextcloud's own file search into a file set, and passes that set as the facade's file scope.
2. A validated status on documents: a restricted Nextcloud system tag that an administrator names in hermiq's settings. Who may assign it is set on the tag, so the organisation decides who validates.
3. "Answer only from validated documents" on an agent. When on, retrieval is limited to files carrying the tag, object search and all tools are off, and the chat's per-session settings cannot widen it.
4. A deterministic abstention: in that mode, when no validated passage reaches the agent's similarity floor, hermiq returns the agent's fixed answer and does not call the model.
5. The filters, the mode and the abstention recorded on each answer, so a reviewer can see why an answer was or was not given.

## Out of scope

- Knowledge bases themselves. `memory-knowledge-bases`.
- Validating objects from other apps' registers. The mode covers documents in Files; object search is off while it is on.
- Checking that the model's answer only uses the passages it was given. The mode controls what the model sees and when it is not asked; judging the answer afterwards is a different, harder problem and not claimed here.
