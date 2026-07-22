# Tasks: session-context-performance

<!-- Baselines (measured, same box): context 26–62s | llm 9s (2-char answer) – 17s | wall ~65–106s.
     ONE user message spawns TWO `claude` processes (reply + title). 2116 magic tables on the
     reference instance. Measure against LIVE data — a synthetic dataset hides the defect. -->

## Implementation Tasks

### Task 1: Skip the context search when its results would all be discarded
- **spec_ref**: `openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-context-retrieval-is-skipped-when-its-results-would-all-be-discarded`
- **files**: `lib/Service/Engine/ContextRetrievalHandler.php`
- **acceptance_criteria**:
  - GIVEN `searchKeywordOnly()` is called unconditionally at line 167 while `includeFiles` (line 127) / `includeObjects` (line 128) only post-filter results (lines 178-179) WHEN both are `false` THEN no search query is issued
  - GIVEN the guard is behaviour-preserving by construction WHEN both flags are `false` THEN the produced context is byte-identical to the pre-change context — assert the OUTPUT, not only the latency
  - GIVEN a baseline of 26–62s WHEN both flags are `false` THEN retrieval completes in under 1 second
  - GIVEN either flag is `true` WHEN a turn runs THEN the search is still issued and results are unchanged
  - The skip is logged with its reason
- [x] Implement
- [x] Test

### Task 2: Scope the context search explicitly
- **spec_ref**: `openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-the-context-search-is-explicitly-scoped-never-ambient-and-never-unbounded`
- **files**: `lib/Service/Engine/ContextRetrievalHandler.php`
- **acceptance_criteria**:
  - GIVEN `Agent.enableRag` is a boolean with schema default FALSE, described as "Whether Retrieval-Augmented Generation is used to ground responses in context" and exposed in `AgentFormModal` WHEN a message is processed THEN retrieval runs ONLY if the agent opted in — absent means false, matching the schema default
  - GIVEN a conversation carries no agent (Engine passes a null agent) WHEN a message is processed THEN no retrieval runs, because there is no enableRag and no views to bound it — the worst case for an unscoped scan
  - GIVEN `Agent.views` is documented as "UUIDs of views that filter which data the agent can access" and `retrieveContext()` resolved it and discarded it ("TODO: Apply view filters here") WHEN retrieval runs THEN the resolved views are passed to `ObjectService::searchObjectsPaginated(views:)` — an access boundary, not only a performance hint
  - GIVEN the docblock documents `'_register' => null, '_schema' => null` as a DELIBERATE defence against a previous caller's ambient scope on the shared `ObjectService` WHEN scoping THEN the nulls are KEPT and scope is expressed explicitly alongside them — never deleted, never omitted
  - GIVEN `ObjectService` carries ambient `setRegister()`/`setSchema()` state WHEN the search runs THEN the resolved scope does NOT depend on which caller last used the instance
  - GIVEN scope is derived from the agent's configuration WHEN a request carries user input THEN that input cannot broaden the scope (`resolveViewFilters()` intersects, never unions)
  - GIVEN this fails SILENTLY WHEN an agent's context lies within its scope THEN the same relevant sources are returned as today — a recall assertion, not just a latency one; and an agent with NO views searches unscoped, never "scoped to nothing"
- **notes**:
  - The original criteria assumed the dominant cost was ambient/unbounded register+schema scope and required deriving concrete `_register`/`_schema` values plus a BOUNDED default. Investigation found otherwise: the cost was a search nobody asked for. 16/16 agents on the reference instance have `enableRag` false and every message still scanned 2038 magic tables. Deriving a register/schema scope would have optimised a query that should not run at all.
  - RESIDUAL (deliberate, tracked): an agent with RAG ON and no views is still unscoped — there is nothing to scope it to, and inventing a bound would silently starve it of context (fast-and-silently-empty is worse than slow-and-complete). It is now an opted-in, logged cost rather than a tax on every message. Tracked as a follow-up issue.
- [x] Implement
- [x] Test

### Task 3: Log the resolved retrieval scope
- **spec_ref**: `openspec/changes/session-context-performance/specs/agent-context-retrieval/spec.md#requirement-the-resolved-retrieval-scope-is-logged`
- **files**: `lib/Service/Engine/ContextRetrievalHandler.php`
- **acceptance_criteria**:
  - GIVEN an over-narrow scope degrades answers without raising an error WHEN any retrieval runs THEN the resolved scope (`viewCount`, `viewIds`, `isUnscoped`) is logged
  - GIVEN an operator reads the log WHEN a retrieval ran THEN they can distinguish a view-scoped search from an unscoped one, and a skip from a search that found nothing
  - GIVEN retrieval was skipped WHEN the operator reads the log THEN the REASON is stated — RAG disabled, or both source types excluded
  - GIVEN the docblock documents the nulls as intentional WHEN the change lands THEN it is corrected to explain that the nulls remain as the anti-ambient defence while scope is expressed explicitly via views
- [x] Implement
- [x] Test

### Task 4: Take conversation-title generation off the reply path
- **spec_ref**: `openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply`
- **files**: `lib/Service/Engine/Engine.php`
- **acceptance_criteria**:
  - GIVEN `maybeGenerateTitle()` (line 525) runs synchronously at line 424 and spawns the SECOND `claude` process WHEN the change lands THEN the reply is delivered without waiting for it
  - GIVEN one user message currently spawns TWO `claude` processes WHEN a turn runs THEN exactly ONE is spawned on the reply's critical path
  - GIVEN a `New conversation` placeholder is written at creation (`ChatStreamController.php:689`) WHEN the title has not yet generated THEN this is not a failure state
  - GIVEN a ~20s title round-trip within a 65–106s wall WHEN the change lands THEN first-turn wall time drops by approximately that round-trip — MEASURED on the reference instance, not asserted from theory
  - GIVEN title generation fails WHEN the turn completes THEN the reply was still delivered and the conversation retains a usable title
- [x] Implement
- [x] Test

### Task 5: Keep the deferred title write safe
- **spec_ref**: `openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object`
- **files**: `lib/Service/Engine/Engine.php`
- **acceptance_criteria**:
  - GIVEN `ObjectService::saveObject()` is PUT-semantic and SILENTLY nulls omitted schema properties WHEN a generated title is written THEN `title`, `userId`, `agentId` and `metadata` are all carried forward
  - GIVEN this is a known repeated failure mode WHEN testing THEN a test asserts that a NON-CHANGED field (`userId`) survives the title write
  - GIVEN `generateConversationTitle(string $firstMessage, ?string $organisation = null)` treats null as "skip policy enforcement" (a backward-compatible default) WHEN the call is deferred THEN the conversation's organisation is still passed
  - GIVEN an org model policy would reject the background model WHEN the deferred title generates THEN the policy is enforced exactly as on today's synchronous path — a latency fix must not become a governance hole
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit unit tests for the skip guard (both flags false → no search AND identical context), the explicit scope (never null, never ambient, bounded default), and the recall assertion
- PHPUnit test that a non-changed `Conversation` field survives the deferred title write (PUT-semantic guard)
- PHPUnit test that the deferred title call still carries `$organisation` — assert policy enforcement, not just that a title appears
- Latency measured on the reference instance (Postgres, live data, 2116 magic tables) BEFORE and AFTER: `context`, `llm`, wall. A synthetic dataset makes the defect unmeasurable
- Process count verified: ONE `claude` process on the reply path, down from two — observed live, not inferred
- Use NC's `executed N queries` counter for query-count assertions, not raw pg logs
- `semantic`/`hybrid` still log their honest degradation notice (lines 153-166); this change does NOT make them semantic
- Do NOT spec or implement CLI pre-warming — `claude -p` is one-shot; rejected in design.md. The `llm` phase belongs to `claude-cli-session-reuse`
- Do NOT touch `ContextAssembler` (ADR-024) — a different, already-scoped path
- All tests pass (`composer test`, `composer check:strict`)
- Feature documentation: N/A — no user-facing surface changes (ADR-010)
- i18n: N/A — no new user-facing strings (ADR-007)
- `openspec validate session-context-performance --strict` passes
