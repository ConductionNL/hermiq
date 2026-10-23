---
kind: code
depends_on: [vector-rag]
---

# Proposal: memory-knowledge-bases

## Summary

An agent owner can create a knowledge base, fill it with documents, and attach it to one or more agents. Documents are uploaded into a folder in Files or picked from Files, and a whole Files folder can be a source, including a folder that integriq keeps in sync with SharePoint or a wiki. When someone asks the agent a question, hermiq searches the attached knowledge bases through OpenRegister's vector search and answers from the passages it finds, with the documents named as sources. Each person only gets answers from documents they may read themselves.

## Why

Two rows of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:me-kb-upload` | no | build: five competitors rate yes |
| `hermiq:me-external-kb` | no | build: four competitors rate yes |

Competitor cells rated yes, quoted from the matrix:

- `me-kb-upload`, Nextcloud Assistant: "files uploaded to Nextcloud are searched through Context Chat: agent tool ask_context_chat (context_agent:ex_app/lib/all_tools/context_chat.py:28-59) and the Assistant's Context Chat form with indexing notice (assistant:src/components/ContextChat/ContextChatInputForm.vue:8)."
- `me-kb-upload`, Copilot Studio: "https://learn.microsoft.com/en-us/microsoft-copilot-studio/knowledge-add-file-upload https://learn.microsoft.com/en-us/microsoft-copilot-studio/agents-experience/knowledge-sources-overview upload files as a knowledge source (up to 500 knowledge sources per agent, 512 MB per file) that the agent searches."
- `me-kb-upload`, Dify: "api/controllers/console/datasets/datasets.py:446 create a knowledge base, datasets_document.py:384 upload documents, web/app/(commonLayout)/datasets/create".
- `me-kb-upload`, n8n: "Agents module knowledge base for CSV, PDF, Markdown, TXT (packages/cli/src/modules/agents/agent-knowledge.controller.ts:48, en.json:7925)".
- `me-kb-upload`, Open WebUI: "backend/open_webui/routers/knowledge.py:318 POST /knowledge/create, file add and directories; src/routes/(app)/workspace/knowledge/[id]/+page.svelte, KnowledgeBase/AddContentMenu.svelte; attached to an agent at ModelEditor.svelte:1194".
- `me-external-kb`, Hermes agent: "optional-mcps/atlassian/manifest.yaml:1-9 Nous-approved catalog entry for Confluence pages and Jira; optional-mcps/notion, microsoft-learn, deepwiki; skills/productivity/notion bundled skill; plugin-catalog/microsoft365.yaml:1-5 SharePoint and OneDrive via a community plugin".
- `me-external-kb`, Copilot Studio: "https://learn.microsoft.com/en-us/microsoft-copilot-studio/knowledge-add-sharepoint https://learn.microsoft.com/en-us/microsoft-copilot-studio/knowledge-copilot-connectors https://learn.microsoft.com/en-us/microsoft-copilot-studio/knowledge-azure-ai-search knowledge sources include SharePoint, Dataverse, Azure AI Search and Microsoft Graph (Copilot) connectors such as ServiceNow and Confluence."
- `me-external-kb`, Dify: "api/controllers/console/datasets/external.py:153,339 connect an external knowledge API as a dataset; api/controllers/console/datasets/data_source.py:228,417 Notion import and sync in core".
- `me-external-kb`, n8n: "packages/nodes-base/nodes/Microsoft/SharePoint/v2/actions/versionDescription.ts:26 and packages/nodes-base/nodes/Notion/v2/VersionDescription.ts:26 usableAsTool, Confluence node (packages/nodes-base/nodes/Confluence)".

## What hermiq already has

- `ContextRetrievalHandler::retrieveContext()` reads the agent's `ragSearchMode`, `searchFiles`, `searchObjects` and `views` (`lib/Service/Engine/ContextRetrievalHandler.php:101-140`). Semantic and hybrid modes log that no public vector facade exists and fall back to keyword search (`:149-162`), and keyword search only returns objects, scoped by views (`:334-350`, `:388-396`). A file never comes back as a RAG source today.
- The `Context` schema bundles files, documents, object queries and view refs into a budgeted preamble prepended in full at run start (`lib/Settings/hermiq_register.json:2946-2952`; hermiq ADR-024), attached through `Agent.contextRefs` (`:2630-2640`).
- The open `vector-rag` change specifies the public OpenRegister facade hermiq will search through, `searchSemantic`, `searchHybrid`, `embedTexts` and `isAvailable`, scoped by views, and keeps embedding and indexing on OpenRegister's side.
- integriq's SharePoint Online adapter lists and fetches documents over Microsoft Graph and writes them into Nextcloud Files (integriq `lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php:153`, `:195`; `openspec/specs/document-cms-connectors/spec.md`, "the reference adapter persists fetched documents into Nextcloud's own Files storage", integriq development at 413357ec).
- There is no knowledge base object, page or upload flow in hermiq (`src/manifest.json`, `src/views/`).

## What this change builds

1. A `KnowledgeBase` schema: a name, a description, and sources, each a Files file, a Files folder, or an OpenRegister register and schema that integriq fills.
2. `Agent.knowledgeBaseRefs`, attaching knowledge bases to an agent, edited on the agent form.
3. Knowledge base pages: a list and a detail page with "Upload documents" (into the knowledge base's folder in Files), "Add from Files", "Add a folder", and each document's indexing state.
4. Retrieval: each turn searches the attached knowledge bases through the `vector-rag` facade, scoped to their files and objects, as the person in the session, and adds the passages as sources.
5. A file scope and an indexing state on the facade, specified here as additions to `vector-rag`'s dependency requirement.

## Out of scope

- Connecting SharePoint, Confluence or a wiki. integriq connects them and writes their documents into Files or OpenRegister (`nc-native-tools`: remote systems route through OpenConnector, now integriq). hermiq reads what integriq writes.
- Embedding, chunking and indexing. OpenRegister does that for files and objects (hermiq ADR-001, `vector-rag`).
- Filters on owner, file name and date, and a validated-sources-only mode. That is `memory-retrieval-scope`.
- Changing `Context`. A context is prepended in full; a knowledge base is searched. They stay separate concepts.
