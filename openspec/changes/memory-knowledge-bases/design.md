# Design: memory-knowledge-bases

Kind: code. Size L. A new schema, an agent property, a retrieval branch in `ContextRetrievalHandler`, an upload route, two manifest pages and additions to the `vector-rag` facade contract.

The change adds a schema and code together. Hydra ADR-032 asks for a chain when a change migrates imperative behaviour to declarative; this is a new capability with no imperative predecessor, and a config spec on its own would declare a schema nothing reads. It stays one `kind: code` change.

## Context at development db6b74dc

- `lib/Service/Engine/ContextRetrievalHandler.php:101-140` settings and view resolution; `:149-162` semantic and hybrid degrade to keyword; `:164-200` the source loop that already handles `entity_type: file` rows with `chunk_text` and `similarity`; `:297` `resolveViewFilters()`; `:334-350` `searchScoped()` and its empty-views gate; `:388-396` `searchKeywordOnly()`.
- `lib/Settings/hermiq_register.json:2946` `Context` (files, documents, objectQueries, viewRefs, charBudget); `:2630-2640` `Agent.contextRefs`; `:1426` `Memory`.
- hermiq ADR-024 (three concepts, one assembly seam) and ADR-003 (memory and skills as OpenRegister objects, recall on OpenRegister's search).
- `openspec/changes/vector-rag/design.md`, "The facade is a dependency requirement, modeled on `ToolRegistryFacade`", "Lazy, guarded resolution", and the RBAC rule that the facade returns only chunks the acting user may read.
- integriq at 413357ec (read only): `lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php:153` `listDocuments()`, `:195` `fetchDocument()`; `openspec/specs/document-cms-connectors/spec.md` purpose.
- `src/manifest.json` menu: 19 items, including `AgentMemory` at `/memory`.

## D1. A knowledge base is its own object, searched, not prepended

`KnowledgeBase`, slug `agentknowledgebase` (the register prefixes slugs that other apps also use, as it does for `agentskill` and `agentbudget`):

- `name`, `description`;
- `folderPath`: the folder in the owner's Files where uploads land, default `Hermiq/Knowledge bases/<name>`;
- `sources`: an array of `{ type, fileId, path, register, schema, label }`, where `type` is `file`, `folder` or `objects`;
- `authorization`: owner writes, readers are the agent's users (D4).

`Agent` gains `knowledgeBaseRefs`, an array of knowledge base uuids.

Rejected: a fourth source kind on `Context`. ADR-024 defines a context as material prepended to every run within a character budget. Two hundred PDFs cannot be prepended; they have to be searched per question. Putting a searched store inside a prepended bundle would make one object mean two things, the confusion ADR-024's concept table exists to prevent.

## D2. Retrieval goes through the vector facade, scoped to the knowledge base

When an agent has `knowledgeBaseRefs`, `retrieveContext()` resolves the sources of each attached knowledge base into a file id set (files, plus every file under each folder) and a view set (for `objects` sources), and calls the facade's `searchHybrid()` with that scope. The rows enter the existing source loop, which already knows file rows. The knowledge base results are counted within `ragNumSources`, file sources first.

Two additions to the `vector-rag` dependency requirement, stated in this change's spec and to be carried into that change:

- a scope by file ids: `searchSemantic` and `searchHybrid` accept `fileIds`, and return only chunks of those files;
- an indexing state: `indexStatus(list<int> $fileIds)` returns `indexed`, `pending`, `failed` or `unsupported` per file, so the knowledge base page can say what the agent can find.

When the facade is absent or `isAvailable()` is false, knowledge bases are not searched, the page shows "Search is not available on this server. Ask your administrator to set up vector search in OpenRegister.", and the answer carries no knowledge base sources. There is no keyword fallback for files, because hermiq's keyword path never returns files (`ContextRetrievalHandler.php:388-396`).

## D3. Uploads go into Files

"Upload documents" posts to `POST /api/knowledge-bases/{id}/documents`, which writes each file into the knowledge base's `folderPath` in the owner's Files and adds it as a `file` source. "Add from Files" opens the Nextcloud file picker; "Add a folder" adds a `folder` source. Removing a source takes it out of the knowledge base; it does not delete the file.

A knowledge base does not copy documents. Deleting a file in Files removes it from the knowledge base on the next retrieval, and OpenRegister drops its chunks from the index.

## D4. Each person reads only what they may read

Retrieval runs as the person in the session, never as the agent's acting user or the knowledge base's owner (hydra ADR-034 Decision 7). The facade returns only chunks of files that person may read, so a document in the owner's private folder gives no answer to anyone else.

The detail page says this plainly for each source that is not shared: "Only you can read this folder. Other people who use the agent get no answers from it. Share it with them, or use a team folder." The check uses the folder's shares in Files, with no search run.

## D5. Outside sources arrive through integriq

A SharePoint library or a wiki is connected in integriq, which writes documents into Files (the SharePoint adapter does this today) or pages into OpenRegister objects. The owner adds that folder, or that register and schema, as a source. hermiq never calls SharePoint, Confluence or any outside API (`nc-native-tools`, "remote systems route through OpenConnector"; hermiq ADR-001, "Connectors to outside systems are OpenConnector's").

The source row shows where the folder comes from when integriq marks it, for example "Synced by integriq from SharePoint: Beleidsdocumenten".

## D6. Pages without a new menu item

The manifest gets `KnowledgeBases` (`/knowledge-bases`, index) and `KnowledgeBaseDetail` (`/knowledge-bases/:id`, detail). They are reached from the Memory page and from the agent form's knowledge base picker, not from a new top-level menu item; the menu already has 19 entries.

## Declarative versus imperative

`KnowledgeBase` and `Agent.knowledgeBaseRefs` are declared in `lib/Settings/hermiq_register.json` with a register version bump, and the relation from agent to knowledge base is a declared `$ref`. Resolving folders to files, calling the facade and checking shares are request-time work, which hydra ADR-031 leaves imperative. The indexing itself is OpenRegister's.

## Seed data

- A `KnowledgeBase` for Gemeente Tilburg: `name` "Inkoopbeleid", `description` "Het inkoop- en aanbestedingsbeleid, de inkoopvoorwaarden en de modelcontracten.", `folderPath` "Team Inkoop/Kennisbank", `sources`: a `folder` source `Team Inkoop/Kennisbank` and a `folder` source `Integraties/SharePoint/Beleidsdocumenten` labelled "Synced by integriq from SharePoint".
- The demo agent "Inkoopassistent" gets `knowledgeBaseRefs` with that knowledge base.
- Both are demo data, written by a repair step that runs only with the dev-mode flag on (hydra ADR-069, decision 5).

## Risks

- The facade lands without a file scope. Mitigation: the scope is written into this change's spec as a dependency requirement, and the lane carries it into `vector-rag`; until then the knowledge base page reports search as not available rather than searching everything.
- A large folder resolves to thousands of file ids per turn. Mitigation: the resolution is cached per knowledge base for five minutes, and the facade takes a folder id as scope when OpenRegister supports it.
- Owners expect a private folder to answer colleagues. Mitigation: D4's notice on each unshared source.
