# Design: knowledge-graph

## Context

ADR-024 fixed the concept table — Skill = capability, Context = situational reference
material, Memory = learned state — and its Rule 1 keeps ONE assembly seam
(`ContextAssembler`) and one retrieval path (`ContextRetrievalHandler`). The graph is a
**fourth knowledge concept**: *relational structure over records the other three
already point at*. It deliberately does not become a second assembly path: at run time
the graph is reached either through `retrieveContext()` (a new mode inside the existing
handler) or through tools inside the tool loop — both existing seams.

ADR-001 sets the fleet boundary this change must respect: **data + vectors + tool
registry live in OpenRegister; Hermiq owns the agent core**. Graph nodes/edges are
data, so they are OpenRegister objects; extraction, traversal-as-context, and the
tools are agent-core behaviour, so they are Hermiq code.

## Decisions

**Nodes and edges are OR objects in the `hermiq` register — no new storage engine.**
`GraphEntity`/`GraphRelation` are ordinary schemas persisted through `ObjectService`,
the same single write-path every other Hermiq object uses. That buys, for free and per
ADR-001/ADR-004: RBAC primitives, multi-tenancy (`organisation`), soft-delete, and the
tamper-evident AuditTrail on every graph mutation. The cost is traversal expressed as
repeated object queries rather than a native graph query — acceptable at the bounded
depths this change allows (see below), and the seam where a future OR-native traversal
API would slot in is exactly one class (`GraphService`).

**A node is a reference, never a copy.** `sourceRef` is a typed pointer:

| `sourceType` | `sourceRef` shape |
|---|---|
| `object` | `{register, schema, uuid}` |
| `file` | `{fileId, path}` (path informational; `fileId` authoritative) |
| `mail` | `{accountId, mailboxId, messageId}` (the shipped mail read tools' ids) |
| `conversation` | `{conversationUuid, messageId?}` |

The graph stores `label`, `entityType`, `aliases`, provenance — structure, not
content. When content is needed (the graph context mode hydrating excerpts, or the
model following a `sourceRef`), it is read **live, as the acting user**, from the
underlying record. Consequences that fall out for free: an edited record never leaves
a stale copy in the graph; deleting/de-permissioning a record instantly removes its
node from every reader's view (the RBAC re-check fails); and the graph itself can be
stored without duplicating anything a DSAR/vergetelheid flow would have to chase —
only labels and pointers.

**RBAC: visibility is derived from the underlying record, both-endpoints for edges.**
OR's object RBAC on the `GraphEntity`/`GraphRelation` objects themselves is necessary
but NOT sufficient — a node's label can leak what a record is about even when the
record itself is protected (the anonymous-name-disclosure class of bug). So
`GraphService` enforces, on every read path (traversal mode, both tools):

1. a node is visible only if the acting user can read its `sourceRef` record *now*
   (`object` → `ObjectService` find as acting user; `file` → `IRootFolder` of the
   acting user; `mail` → the mail service's own account scoping; `conversation` →
   the owner-or-listed-participant rule from the `talk-shared-sessions` spec);
2. an edge is visible only if **both** endpoints survived check 1;
3. a path exists only if **every** edge on it is visible — a path is never returned
   "with holes".

Fail closed: a `sourceRef` that cannot be resolved (record gone, service unavailable)
counts as not-visible, logged at debug, never an exception to the caller.

**Extraction runs as the acting user, in background jobs, audited.** Extraction is
enqueued (a `QueuedJob`, matching `TalkTurnJob`'s wrapper-only pattern) with the
enqueueing user recorded in the job argument; the job impersonates that user for the
duration and restores the prior identity afterwards — the identical
impersonate-and-restore contract the talk-chat-bridge spec's "turn runs as the
speaker" scenario already pins down. The job therefore *cannot* extract from records
its user cannot read, and every `GraphEntity`/`GraphRelation` write goes through
`ObjectService`, landing in the AuditTrail with `extractedBy` recording extractor
identity + version so a reviewed graph is reproducible/attributable (ADR-004, AI Act
record-keeping posture inherited per ADR-001).

**Entity resolution is deterministic in this change.** Upsert merges on normalised
(`trim` + case-fold) `label` + `entityType` within the same organisation; near-miss
labels become `aliases` only when an extractor proposes them explicitly.
Embedding-similarity resolution ("Jan Jansen" ≈ "J. Jansen") is *deferred* to the
public vector facade the `vector-rag` change requires from OpenRegister — building a
private similarity path here would recreate exactly the internal-reach-in
`ContextRetrievalHandler`'s docblock documents refusing (gate-27).

**Graph context mode reuses the superset result shape.** `searchKeywordOnly()`'s
return is documented as deliberately wide — "the consuming loop in retrieveContext()
is written against the superset shape a future vector-search facade would return".
The graph mode exploits the same property: seed entities are matched from the query
(label/alias keyword match), a bounded traversal (default depth 2, node/edge caps
from `IAppConfig` with safe defaults) collects the neighborhood, underlying records
are hydrated into `{entity_id, entity_type, text, score, metadata}` rows, and the
existing formatting/limit loop consumes them unchanged. Relations themselves are
rendered as one compact `Relations:` text block (subject —predicate→ object lines) so
the model sees the structure, not only the endpoints. The mode honours the existing
posture of `retrieveContext()`: never throws; an empty/failed graph degrades to the
keyword path with a `LoggerInterface::info()` note, mirroring how `semantic`/`hybrid`
degrade today. The empty-views gate is *not* bypassed for the hydration step: record
hydration goes through the same acting-user reads, so the 71s unscoped-fan-out
failure mode documented on `searchKeywordOnly()` cannot reappear via the graph
(traversal starts from named seeds, never from a full scan).

**Tools are read-only, grant-governed, reference-returning.** `hermiq.graphNeighbors`
(`{entity, depth?, predicates?}`) and `hermiq.graphPath` (`{from, to, maxHops?}`)
register exactly like the existing `hermiq.*` domain tools, flow through the
grant-filtered, default-denied resolved set (`ToolSearchService`'s invariant — never
a descriptor outside the resolved set — applies unchanged), and return labels,
predicates, and `sourceRef` pointers. They never inline record content; the model
follows a `sourceRef` with the already-governed record/file/mail tools, so tool-level
authorization is never wider than what those tools already enforce.

## What lives where (ADR-001 seam map)

| Concern | Owner | Status in this change |
|---|---|---|
| Graph node/edge storage, RBAC primitives, audit, multitenancy | **OpenRegister** (as generic object storage) | Used as-is via `ObjectService` |
| Native graph/traversal query API (single-query N-hop) | **OpenRegister** (future) | **Out of scope** — `GraphService` is the one class to re-point when it exists |
| Embedding-based entity resolution / semantic seed matching | **OpenRegister** vector facade (per `vector-rag`) | **Out of scope** — deterministic resolution only |
| Extraction (jobs, prompts, provenance), traversal-as-context, graph tools | **Hermiq** | Delivered here |

## Risks / Trade-offs

- **Traversal cost on plain object queries.** Bounded depth + caps keep it acceptable;
  a hot graph would motivate the OR-native traversal seam, not a Hermiq-side cache of
  record content (which would break the reference-only invariant).
- **Per-read RBAC re-checks are extra reads.** Accepted: correctness over latency for
  a security boundary; checks batch per traversal, and the fail-closed shape means an
  outage degrades to "smaller graph", never "wider graph".
- **LLM-proposed relations can be wrong.** `confidence` + `extractedBy` are persisted
  so low-confidence edges can be filtered at read time (config threshold) and a bad
  extractor version can be swept; extraction writes are ordinary audited object
  writes, so cleanup is a normal object operation.
- **Conversation-history extraction touches multi-speaker transcripts.** The acting
  user must be a conversation owner-or-participant (same rule as taking a turn), so
  extraction can never read a transcript its user could not open.

## Open Questions

- **Resolved — should the graph get its own register?** No: the `hermiq` register with
  two new schemas keeps one register import path and one version bump; nothing about
  the data is app-external.
- **Resolved — should edges carry content snippets for context speed?** No: snippets
  are copies; hydrate live instead (reference-only invariant).
- **Open — controlled predicate vocabulary vs. free labels.** This change ships a
  small seed vocabulary (`worksFor`, `partOf`, `relatesTo`, `mentions`, `authoredBy`,
  `about`) plus free-text predicates flagged `custom`; whether to harden into a closed
  vocabulary is a follow-on once real extractions exist to measure.
