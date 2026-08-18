---
kind: code
depends_on: []
---

# Proposal: vector-rag

## Why

Hermiq's RAG is semantic in name only. `ContextRetrievalHandler` accepts
`ragSearchMode: semantic | hybrid` on the Agent, but both modes **degrade to keyword
search** with a logged note — the class docblock's ground-truth adaptation records
why: the ported original constructed
`OCA\OpenRegister\Service\Vectorization\VectorEmbeddings` directly, an OR-INTERNAL
class, not a published cross-app facade, and reproducing that reach-in is exactly the
wide, undocumented internal dependency gate-27 exists to catch. Verified at
OpenRegister HEAD (2026-08-18): the class still exists and is still internal — no
public vector facade has shipped. The consuming loop in `retrieveContext()` was
deliberately written against the superset shape a vector facade would return
(`similarity`, `chunk_text`, `metadata`, `entity_type: file`), so the wiring cost on
Hermiq's side is small and was planned from day one ("only `retrieveContext()`'s mode
branch needs re-pointing — see the agent-engine-port DEFERRED note").

ADR-001's delegation table places the substrate unambiguously: *"vector embeddings +
semantic/hybrid search (RAG substrate)"* = **OpenRegister**. This change therefore
does two things: (1) defines, as a hard dependency requirement, the public
vector-search facade Hermiq needs OR to publish (the `ToolRegistryFacade` precedent —
small, additive, read-only); and (2) wires Hermiq's three consumers to it — the
semantic/hybrid retrieval modes, and `ToolSearchService`'s tool-search ranking, which
today is a plain `str_contains` substring match capped at `MAX_MATCHES = 10` and
misses every paraphrase the model tries.

## What Changes

- **Dependency requirement (OpenRegister-side — specified here, built there):** a
  public facade class, consumed the way `ToolRegistryFacade` already is, offering:
  - `searchSemantic(string $query, int $limit, array $views): list<array>` — rows in
    the superset shape (`entity_id`, `entity_type` (`object`|`file`), `chunk_text`,
    `similarity`, `metadata`);
  - `searchHybrid(string $query, int $limit, array $views): list<array>` — fused
    keyword + vector ranking, same row shape;
  - `embedTexts(list<string> $texts): list<list<float>>` — embedding generation for
    caller-owned text (Hermiq uses it for tool descriptors);
  - `isAvailable(): bool` — false whenever no embedding backend is configured, so
    consumers can degrade without probing.
  Embedding **generation and storage for objects/files** stays entirely OR-side
  (its existing vectorization pipeline), configured local-first: an Ollama embedding
  model (e.g. `nomic-embed-text` on :11434) as the default backend, matching the
  fleet's local-Qwen posture. Backend/model selection is OR configuration, not
  Hermiq's.
- `lib/Service/Engine/ContextRetrievalHandler.php`: the `semantic`/`hybrid` mode
  branch stops logging "degrading to keyword" and calls the facade — resolved LAZILY
  through the container with a `class_exists()` guard (the `TalkBridge` pattern), so
  Hermiq keeps booting against an OpenRegister that predates the facade. The
  empty-views gate stays exactly as is (it is load-bearing for latency and scope —
  the documented 71s unscoped fan-out), and views are passed through to the facade.
  Facade absent, `isAvailable() === false`, or a facade error ⇒ the existing keyword
  path, logged — the current degradation behaviour is preserved verbatim as the
  fallback tier.
- `lib/Service/ToolSearchService.php`: `search()` upgrades from substring matching to
  embedding similarity — descriptor embeddings (id + name + description via
  `embedTexts()`) cached per resolved set, query embedded per call, cosine top-N with
  a similarity floor, capped at `MAX_MATCHES`. The hard invariant is untouched:
  NEVER a descriptor outside the already-resolved (grant-filtered, default-denied)
  set. No backend ⇒ the existing substring path, unchanged.
- Admin visibility: the existing settings surface reports whether vector search is
  live or degraded (reading `isAvailable()`), so "semantic" on an agent form is never
  silently keyword in production without an inspectable signal.

## Capabilities

### New Capabilities
- `vector-rag`: real semantic and hybrid retrieval through a public OpenRegister
  vector-search facade, local-first embedding backends, and honest graceful
  degradation to keyword when no vector backend is configured.

### Modified Capabilities
- `agent-tool-governance`: the `hermiq.searchTools` ranking behind progressive tool
  disclosure upgrades from substring matching to embedding similarity, with the
  resolved-set-only invariant and the substring fallback both preserved.

## Impact

- **Code (Hermiq):** `lib/Service/Engine/ContextRetrievalHandler.php` (mode branch
  re-pointing + lazy facade resolution), `lib/Service/ToolSearchService.php`
  (similarity ranking + embedding cache), settings surface read-out, unit tests with
  a facade stub under `tests/Stubs/`.
- **OpenRegister-side seam (explicit — OUT OF SCOPE for this change's code):** the
  facade itself, the embedding backend configuration (Ollama local-first), and
  object/file embedding generation live in OpenRegister per ADR-001. This change
  ships the Hermiq side and the facade *contract*; it MUST degrade cleanly until the
  OR change lands, and MUST NOT reach `OCA\OpenRegister\Service\Vectorization\*`
  internals under any circumstance.
- **Downstream:** the `knowledge-graph` change's deferred embedding-based entity
  resolution and `MemoryService`'s keyword-only `recallSessions()`/`recallEntries()`
  become natural follow-ups on the same facade — both explicitly not delivered here.
