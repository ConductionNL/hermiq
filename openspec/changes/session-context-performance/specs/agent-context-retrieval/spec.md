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

### Requirement: The context search is explicitly scoped, never ambient and never unbounded
The system MUST pass concrete `_register` and `_schema` values to every context search. It MUST
NOT pass `null` for either, and it MUST NOT omit them — omitting them would let a previous
caller's ambient `setRegister()`/`setSchema()` state on the shared `ObjectService` instance
determine the retrieval scope. The scope MUST be derived from the agent's own configuration, MUST
NOT be derivable from user-supplied request input, and MUST be bounded by a hard cap on the number
of registers and schemas searched. When no scope can be derived for an agent, the system MUST fall
back to a bounded default scope and MUST NOT fall back to an unbounded search.

#### Scenario: A turn requests context retrieval
- GIVEN an agent with a derivable retrieval scope
- WHEN the system issues the context search
- THEN the search MUST carry concrete `_register` and `_schema` values derived from the agent's configuration
- AND the search MUST NOT scan every register and schema on the instance
- AND the retrieval MUST complete in under 5 seconds, against a baseline of 26–62 seconds on an instance with 2116 magic tables

#### Scenario: The ambient-scope trap is avoided
- GIVEN `ObjectService` is a shared instance carrying ambient register/schema state from previous callers
- WHEN the system issues the context search
- THEN the search MUST set `_register` and `_schema` explicitly
- AND the resolved scope MUST NOT depend on which caller last used the `ObjectService` instance

#### Scenario: An agent has no derivable scope
- GIVEN an agent whose configuration yields no retrieval scope
- WHEN the system issues the context search
- THEN the system MUST apply a bounded default scope
- AND the system MUST NOT issue an unbounded search across all registers and schemas

#### Scenario: The scope cannot be widened by the caller
- GIVEN a request carrying user-supplied input
- WHEN the retrieval scope is resolved
- THEN the scope MUST be derived only from the agent's configuration
- AND the system MUST NOT allow the request to broaden the scope beyond what the agent declares

#### Scenario: Retrieval recall is preserved for a scoped agent
- GIVEN an agent whose relevant context lives within its derived scope
- WHEN a turn requests context retrieval for a query matching that context
- THEN the system MUST return the same relevant sources it returns today with the unscoped search

### Requirement: The resolved retrieval scope is logged
The system MUST log the resolved register/schema scope for every context retrieval, so an
over-narrow scope is diagnosable from logs. An over-narrow scope MUST NOT be silent: it degrades
answer quality without raising an error.

#### Scenario: A retrieval runs
- GIVEN any context retrieval
- WHEN the search is issued
- THEN the system MUST log the resolved `_register` and `_schema` scope
- AND the log MUST allow an operator to distinguish a derived scope from the bounded default

#### Scenario: A retrieval is skipped
- GIVEN both `includeFiles` and `includeObjects` are `false`
- WHEN the search is skipped
- THEN the system MUST log that the retrieval was skipped and why

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
