# Design: vector-rag

## Context

Three verified ground truths shape this design:

1. `ContextRetrievalHandler`'s class docblock records the deliberate deferral: the
   ported original used `OCA\OpenRegister\Service\Vectorization\VectorEmbeddings`
   directly — internal, un-published — and Hermiq refused to reproduce that reach-in
   (gate-27). `semantic`/`hybrid` degrade to `searchKeywordOnly()` with an info log.
   Still true at OR HEAD, 2026-08-18.
2. The keyword rows are deliberately kept wide because "the consuming loop in
   retrieveContext() is written against the superset shape a future vector-search
   facade would return (`similarity`, `chunk_text`, `metadata`, `entity_type:
   file`)". The formatting/limit loop, file-vs-object source counting, and source
   descriptors need zero changes when real similarity rows arrive.
3. `ToolSearchService::search()` is a case-folded `str_contains` over
   `id + name + description`, first-`MAX_MATCHES`(10)-wins, with one hard invariant:
   never a descriptor outside the resolved, grant-filtered, default-denied set.

ADR-001 places vector embeddings + semantic/hybrid search in OpenRegister. So the
split is: **contract + consumers here; substrate there.**

## Decisions

**The facade is a dependency requirement, modeled on `ToolRegistryFacade`.** ADR-001's
amendment shows the precedent: when Hermiq needed OR's tool registry, OR grew "a
small, additive facade (read/invoke; no behavior change to OR itself)". The vector
facade is the same move for the RAG substrate: four methods
(`searchSemantic`/`searchHybrid`/`embedTexts`/`isAvailable`), read-only, additive.
This change's spec states the contract as SHALL requirements on the *dependency*;
the OR-side implementation is an explicit cross-repo out-of-scope item tracked as a
prerequisite for flipping the modes live.

**Lazy, guarded resolution — the `TalkBridge` pattern.** The facade class is resolved
through the container at call time behind a `class_exists()` probe, exactly how
`TalkBridge::isAvailable()` guards every spreed class. Hermiq must boot, run, and
answer with keyword RAG on an OpenRegister release that predates the facade; a hard
constructor dependency would couple Hermiq's installability to OR's release train.

**Row shape is the existing superset — by construction, not adaptation.** The facade
contract fixes the row shape to what `retrieveContext()`'s loop already consumes:
`entity_id`, `entity_type` (`object`|`file`), `chunk_text`, `similarity`, `metadata`
(with `uuid`/`register_id`/`schema_id` for objects, `file_id`/`file_path`/`mime_type`
for files). That is the shape the loop was written against (ground truth 2); the mode
branch is a re-pointing, and files finally appear as RAG sources (the keyword path
only ever produced `entity_type: object` rows).

**The views gate survives, and views ride through to the facade.** The empty-views
gate in `searchScoped()` exists because an unscoped search "fans out across every
register and schema on the instance — measured 2026-07-29 at 71s". A vector index
makes unscoped search *cheaper*, but the gate is also an authorization-scope
declaration: no resolved views = the agent declared no data scope = nothing
legitimate to retrieve. So semantic/hybrid inherit the identical gate, and the
resolved `$viewFilters` are a required facade parameter — OR applies them the way
`searchObjectsPaginated(views:)` already does. RBAC on returned rows is OR's job
(data authorization lives in OR, ADR-023 Rule 1): the facade must only return
chunks whose parent object/file the acting user may read.

**Hybrid fuses in OR, not in Hermiq.** A Hermiq-side merge of keyword + semantic
result lists would need score normalisation across two systems and would run two
round-trips per turn. OR owns both indexes; `searchHybrid()` is one call. Hermiq's
`hybrid` branch is therefore as thin as `semantic`'s — a different facade method,
same fallback.

**Degradation is a tier, not an error — and it is honest.** The resolution order for
`semantic`/`hybrid` is: facade class exists AND `isAvailable()` → facade call;
facade call throws → log `warning`, fall through; otherwise → keyword path with the
existing info log (message updated to distinguish "facade not present" from "facade
present, no backend configured"). Behaviour under "no vector backend configured" is
specified as first-class scenarios, not an afterthought: the answer still arrives,
retrieval still works at keyword quality, and the settings surface says which tier is
live — because a check that did not run must not look like one that passed.

**Tool search: embed the descriptors, keep the invariant, keep the fallback.**
`ToolSearchService` iterates ONLY `$this->resolved` — that loop structure is
preserved; similarity replaces `str_contains` as the match test, so the
never-outside-the-resolved-set invariant holds by construction. Descriptor embeddings
are computed via `embedTexts()` once per resolved set and cached keyed on a hash of
the descriptor ids + the embedding model id (a model change invalidates the cache);
the query is embedded per call; ranking is cosine similarity with a configurable
floor (`IAppConfig('hermiq', 'tools.searchSimilarityFloor')`, safe default), top
`MAX_MATCHES`. Rationale: substring search misses paraphrases ("send a chat
message" never matches `hermiq.talkPost` unless the words collide), which under
progressive disclosure means the model simply cannot find granted tools. When
`embedTexts` is unavailable, the substring path runs unchanged — same method, same
cap, same invariant.

**Embedding backends are OR configuration, local-first.** Which model produces
embeddings (Ollama `nomic-embed-text`/`bge-m3` on :11434 first; any remote backend as
an explicit opt-in) is decided where the vectors live — OpenRegister — mirroring how
the fleet's chat models are configured. Hermiq does not add a second embedding
configuration surface; it *reads* availability via `isAvailable()` and reports it.
One constraint is specified because it bites consumers: **all texts compared must be
embedded by the same model** — the facade owns model identity, and Hermiq's tool-
descriptor cache keys on it.

## What lives where (ADR-001 seam map)

| Concern | Owner | Status in this change |
|---|---|---|
| Vector facade contract (4 methods, row shape, views param, RBAC on rows) | Specified **here**, implemented in **OpenRegister** | Dependency requirement; explicit out-of-scope for code |
| Object/file embedding generation + storage, backend/model config (Ollama-first) | **OpenRegister** | Out of scope (existing OR vectorization pipeline, made configurable + public) |
| `semantic`/`hybrid` mode wiring, fallback tiers, admin read-out | **Hermiq** | Delivered here |
| Tool-search similarity ranking + descriptor embedding cache | **Hermiq** | Delivered here |
| Memory recall (`recallSessions`/`recallEntries`) on vectors; graph entity resolution | **Hermiq**, on the same facade | Explicitly deferred follow-ups |

## Risks / Trade-offs

- **Cross-repo sequencing.** Hermiq's code merges green and stays keyword-only until
  OR ships the facade; the guarded resolution makes that state legitimate, tested,
  and visible in settings rather than a latent break. The live-verify task runs only
  once a facade build exists.
- **Per-call query embedding adds latency to tool search.** One small-model embed
  (<100ms local) against a saved model-miss; the descriptor side is cached. The floor
  + cap keep result size identical to today.
- **Scores from two modes are not comparable.** `similarity` from the facade vs the
  keyword path's constant `score: 1.0` — already true today for the superset shape;
  the formatting loop treats it as display metadata, not a threshold, and this change
  keeps it that way.
- **Stale descriptor-embedding cache.** Keyed on descriptor-id hash + model id;
  a resolved-set change or model change recomputes. Worst case is one extra
  `embedTexts` round per turn-shape change, never a wrong result set.

## Open Questions

- **Resolved — should Hermiq call Ollama for embeddings directly as an interim?**
  No: it would duplicate OR's substrate, create a second embedding-model
  configuration, and produce vectors OR's index cannot use — the exact fragmentation
  ADR-001 forbids. Keyword fallback is the interim.
- **Open — chunking granularity for file rows.** The facade returns `chunk_text`;
  chunk size/overlap policy is OR-side and does not change Hermiq's contract, but the
  live-verify should record what shipped so `ragNumSources` defaults can be tuned.
