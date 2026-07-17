# agent-context-retrieval Specification

**Status**: in-progress
**Scope**: hermiq
**OpenSpec changes**:
- `session-context-performance` — the RAG keyword search is skipped when its results would all be
  discarded, and is explicitly scoped instead of scanning all registers and schemas (kind: code)

## Purpose

Defines the RAG keyword-retrieval path (`lib/Service/Engine/ContextRetrievalHandler.php`): when it
runs, what it is scoped to, and its latency and recall contract. No existing spec covers this
handler. This is **not** ADR-024's path — ADR-024 owns `ContextAssembler` and its already-scoped
`objectQueries`. This spec owns the separate, currently unscoped `searchKeywordOnly()` seam that
every agent run takes, because `semantic` and `hybrid` modes both degrade to it (no OpenRegister
vector-search facade exists at HEAD) and `hybrid` is the default.

## ADDED Requirements

### Requirement: Context retrieval is skipped when its results would all be discarded
The system MUST NOT execute the context search when both `includeFiles` and `includeObjects` are
`false`. In that configuration every result is discarded by the post-search type filters, so the
search is wasted work. The resulting context MUST be identical to the context produced when the
search runs and all its results are discarded.

#### Scenario: An agent has both file and object retrieval disabled
- GIVEN an agent whose resolved `includeFiles` is `false` and `includeObjects` is `false`
- WHEN a turn requests context retrieval
- THEN the system MUST NOT issue a search query
- AND the system MUST return the same empty context it returns today
- AND the retrieval MUST complete in under 1 second, against a baseline of 26–62 seconds

#### Scenario: An agent has file retrieval enabled
- GIVEN an agent whose resolved `includeFiles` is `true` and `includeObjects` is `false`
- WHEN a turn requests context retrieval
- THEN the system MUST issue the search
- AND file results MUST be returned as they are today

#### Scenario: An agent has object retrieval enabled
- GIVEN an agent whose resolved `includeObjects` is `true` and `includeFiles` is `false`
- WHEN a turn requests context retrieval
- THEN the system MUST issue the search
- AND object results MUST be returned as they are today

### Requirement: Context retrieval is scoped to what the agent may actually read
The system MUST treat context retrieval as opt-in per agent and MUST scope it to the data the agent
declares it may access. Retrieval MUST run only when `Agent.enableRag` is true; an absent value MUST
be treated as false, matching the schema default. When a conversation carries no agent, the system
MUST NOT retrieve. When retrieval does run, the system MUST pass the agent's resolved `Agent.views`
to the search as its scope, because `views` is documented as "UUIDs of views that filter which data
the agent can access" and is therefore an access boundary and not merely a performance hint. The
system MUST continue to pass explicit `null` values for `_register` and `_schema` and MUST NOT omit
them, so that a previous caller's ambient `setRegister()`/`setSchema()` state on the shared
`ObjectService` instance can never determine the retrieval scope. The scope MUST be derived from the
agent's own configuration and MUST NOT be broadened by user-supplied request input. An agent with no
views MUST search unscoped rather than "scoped to nothing", because a silently empty result is worse
than a slow complete one.

#### Scenario: An agent has not enabled RAG
- GIVEN an agent whose `enableRag` is false or absent
- WHEN a turn would request context retrieval
- THEN the system MUST NOT issue any context search
- AND the system MUST return the documented empty-context shape
- AND the reason MUST be logged

#### Scenario: A conversation has no agent
- GIVEN a conversation carrying no `agentId`, so the engine resolves a null agent
- WHEN a turn would request context retrieval
- THEN the system MUST NOT issue any context search
- AND the system MUST NOT fall back to an instance-wide scan on behalf of no configuration

#### Scenario: An opted-in agent declares views
- GIVEN an agent with `enableRag` true and one or more `views`
- WHEN the system issues the context search
- THEN the search MUST carry those view UUIDs as its scope
- AND the search MUST NOT scan data outside those views

#### Scenario: The ambient-scope trap is avoided
- GIVEN `ObjectService` is a shared instance carrying ambient register/schema state from previous callers
- WHEN the system issues the context search
- THEN the search MUST pass `_register` and `_schema` as explicit nulls
- AND the resolved scope MUST NOT depend on which caller last used the `ObjectService` instance

#### Scenario: An opted-in agent declares no views
- GIVEN an agent with `enableRag` true and no `views`
- WHEN the system issues the context search
- THEN the search MUST be unscoped
- AND the system MUST NOT pass an empty scope, which would return zero rows for every query
- AND the cost MUST be logged as unscoped

#### Scenario: The scope cannot be widened by the caller
- GIVEN a request carrying user-supplied view selections
- WHEN the retrieval scope is resolved
- THEN the system MUST intersect them with the agent's declared views and MUST NOT union them
- AND the system MUST NOT allow the request to broaden the scope beyond what the agent declares

#### Scenario: Retrieval recall is preserved for a scoped agent
- GIVEN an agent whose relevant context lives within its declared views
- WHEN a turn requests context retrieval for a query matching that context
- THEN the system MUST return the same relevant sources it returns today with the unscoped search

### Requirement: The resolved retrieval scope is logged
The system MUST log the resolved scope for every context retrieval, so an over-narrow scope is
diagnosable from logs. An over-narrow scope MUST NOT be silent: it degrades answer quality without
raising an error. A skipped retrieval MUST state its reason, so that "the agent has RAG off" is
never mistaken for "the search found nothing".

#### Scenario: A retrieval runs
- GIVEN any context retrieval
- WHEN the search is issued
- THEN the system MUST log the resolved scope, including the view count and view UUIDs
- AND the log MUST allow an operator to distinguish a view-scoped search from an unscoped one

#### Scenario: A retrieval is skipped
- GIVEN an agent with `enableRag` false, or with both `includeFiles` and `includeObjects` false
- WHEN the search is skipped
- THEN the system MUST log that the retrieval was skipped and which of those reasons applied

## Non-Functional Requirements

- **Performance:** with both include flags `false`, retrieval MUST complete in under 1 second
  (baseline 26–62s). With retrieval enabled, the scoped search MUST complete in under 5 seconds
  (baseline 26–62s on an instance with 2116 magic tables). These MUST be measured on the reference
  instance against live data — a small synthetic dataset makes the defect unmeasurable.
- **Accessibility:** not applicable — no frontend surface.
- **Internationalization:** not applicable — no new user-facing strings. (Dutch and English remain
  supported per ADR-005.)

## Acceptance Criteria

- With both include flags `false`: no search query is issued, and the produced context is byte-identical to the pre-change context.
- Every issued search carries concrete, non-null `_register` and `_schema` values.
- No search scans all 2116 magic tables.
- An agent with no derivable scope gets a bounded default, never an unbounded search.
- Retrieval recall for a scoped agent is unchanged for queries whose context lies within scope.
- The resolved scope is present in the logs for every retrieval.
- `context` phase measured on the reference instance drops from 26–62s to under 5s.

## Notes

- **Do not simply delete `'_register' => null, '_schema' => null`** (`searchKeywordOnly()`, line
  333). The docblock at lines 313-320 documents them as deliberate: they defend against a previous
  caller's ambient scope on the shared `ObjectService` silently narrowing RAG. Deleting them makes
  the scope depend on call order — fast and non-deterministically wrong, strictly worse than
  today's slow-but-correct search. Replace them with explicit derived values.
- **This trades recall for latency and the failure is silent.** An over-narrow scope produces an
  answer, just a worse one. Hence the recall scenario and the scope logging requirement — neither
  is optional.
- `$agentData['views']` is already resolved into `$viewFilters` (lines 135-143) but, per the
  ported note, **not applied to the search** ("TODO: Apply view filters here"). It is a natural
  source for the derivation. The precise derivation is this change's main open question — see
  DEFERRED_QUESTIONS.
- `semantic` and `hybrid` both degrade to this keyword path (lines 153-166); `hybrid` is the
  default (line 125). This is the path every agent takes, not an edge case.
- Related: `session-context-performance` also moves conversation-title generation off the reply
  path — specified in that change's `agent-engine-port` delta.
