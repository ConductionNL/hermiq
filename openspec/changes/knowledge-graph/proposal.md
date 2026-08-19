---
kind: code
depends_on: []
---

# Proposal: knowledge-graph

## Why

Hermiq's knowledge system today has three concepts converging in one assembly seam
(ADR-024 Rule 1): **Context** (files, objectQueries, deferred viewRefs, inline
`documents`), **Memory** (`Memory`/`UserProfile`/`Session`/`SessionTurn`, self-editing
via memory tools), and **Skills**. All of them answer "what does the agent know" as
*flat text*: `ContextAssembler` concatenates budgeted preambles, and
`ContextRetrievalHandler` retrieves keyword-matched excerpts. None of them can answer
a *relational* question — "which cases involve this supplier", "who has touched this
object", "what connects this mail thread to that register object" — without the model
re-deriving relations from raw excerpts on every turn.

A knowledge graph layer adds that missing shape: typed entities and relations
**extracted from and linked to** the records Hermiq already reaches (OpenRegister
objects, Nextcloud files, mail read via the shipped mail read tools, and Hermiq's own
conversation history), traversable both at context-assembly time and as agent tools.
The graph stores *references*, never copies — the underlying record stays the single
source of truth, and its RBAC stays the authorization of record (ADR-001: data lives
in OpenRegister; ADR-023: the acting user governs every read).

## What Changes

- `lib/Settings/hermiq_register.json` (register `info.version` 0.26.0 → 0.27.0):
  - ADD **`GraphEntity`** schema (slug `graphentity`): `label` (required), `entityType`
    (required — e.g. `person`, `organisation`, `case`, `document`, `topic`),
    `sourceType` (enum: `object` | `file` | `mail` | `conversation`), `sourceRef`
    (object — the typed pointer to the underlying record, see design.md; NEVER the
    record's content), `aliases` (array of string, default `[]`), `confidence`
    (number 0–1), `extractedBy` (string — extractor identifier + version). Flat, no
    `if`/`then`.
  - ADD **`GraphRelation`** schema (slug `graphrelation`): `subject` (required, uuid of
    a GraphEntity), `predicate` (required — typed relation label), `object` (required,
    uuid of a GraphEntity), `sourceRef` (provenance pointer to the record the relation
    was extracted from), `confidence` (number 0–1), `extractedBy`.
  - ADD **`Agent.graphEnabled`** (bool, default false) — opt-in per agent, mirroring
    how `talkEnabled` gates the Talk bridge.
- `lib/Service/Graph/GraphService.php` (new): the single graph surface — upsert
  (entity resolution by normalised `label`+`entityType`), `neighbors()`, `path()`,
  and RBAC-filtered traversal. All persistence via `ObjectService` (single write-path,
  so every graph write lands in OpenRegister's AuditTrail per ADR-004). Every read
  result is filtered by re-checking the *underlying record* as the acting user: a node
  whose `sourceRef` the acting user cannot read is dropped; an edge is returned only
  when BOTH endpoints survive that check.
- `lib/Cron/GraphExtractionJob.php` (new, `QueuedJob`): extracts entities/relations
  from one source batch, running as the acting user who enqueued it (the same
  impersonate-and-restore pattern the Talk turn path already specifies), reading
  objects via `ObjectService`, files via `IRootFolder`, mail via the shipped mail read
  tools' service layer, and conversation history via Hermiq's own `Conversation`/
  `Message` objects. Extraction uses the existing LLM provider layer
  (`lib/Service/Llm/ProviderFactory`) for entity/relation proposal; the write goes
  through `GraphService` only.
- `lib/Service/Engine/ContextRetrievalHandler.php`: `retrieveContext()` gains a
  **`graph`** search mode alongside the existing `keyword` (and the degrading
  `semantic`/`hybrid`) modes: query terms are matched against `GraphEntity` labels/
  aliases, the seed entities' bounded neighborhood is traversed via `GraphService`,
  and the *underlying records* are hydrated into the existing superset result shape
  (`entity_type`, `text`, `similarity`, `metadata`) — the consuming loop needs no
  change, and the never-throws contract is preserved (graph failure or an empty graph
  degrades to the keyword path).
- Agent tools **`hermiq.graphNeighbors`** and **`hermiq.graphPath`** (new): read-only,
  grant-governed like every other tool (they flow through the resolved,
  default-denied set `ToolSearchService` guards), returning only RBAC-surviving nodes
  and edges plus the `sourceRef` pointers so the model can follow up with existing
  record tools.

### MCP coverage

`hermiq.graphNeighbors` / `hermiq.graphPath` are exposed through the same tool
registration path as the existing `hermiq.*` domain tools; no new MCP transport
surface.

## Capabilities

### New Capabilities
- `knowledge-graph`: typed entities and relations extracted from and linked to
  OpenRegister objects, files, mail, and conversation history; reference-only nodes;
  both-endpoints RBAC on edges; acting-user extraction jobs; graph traversal as a
  context-retrieval mode and as agent tools.

### Modified Capabilities
<!-- none — the graph mode is additive inside `retrieveContext()`; existing keyword
behaviour and the semantic/hybrid degradation notes are untouched by this change -->

## Impact

- **Code:** `lib/Settings/hermiq_register.json`, new `lib/Service/Graph/GraphService.php`,
  new `lib/Cron/GraphExtractionJob.php`, `lib/Service/Engine/ContextRetrievalHandler.php`
  (graph mode branch), tool registration for the two graph tools, plus unit tests.
- **OpenRegister-side seams (explicit, out of scope here — see design.md):** graph
  storage stays ordinary OR objects (no OR change needed); a *native* graph/traversal
  query API in OR, and embedding-based entity resolution (needs the public
  vector-search facade the `vector-rag` change defines), are deferred OR-side seams.
- **Other Conduction apps:** none; couples only to OpenRegister's public
  `ObjectService` surface and Nextcloud core OCP.
