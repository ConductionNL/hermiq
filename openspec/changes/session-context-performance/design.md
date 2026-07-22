# Design: session-context-performance

## Architecture Overview

Three independent defects on one turn's critical path. Each is separately fixable, separately
measurable, and separately revertible.

```
ONE USER MESSAGE  →  wall ~65–106s
├─ context   26–62s   ContextRetrievalHandler::retrieveContext()
│                       └─ searchKeywordOnly()  ← runs ALWAYS (a), scans ALL 2116 tables (b)
├─ llm        9–17s   the reply's `claude` spawn        ← NOT this change (claude-cli-session-reuse)
└─ title     ~20s     a SECOND `claude` spawn, synchronous   ← (c)
```

### Verified against HEAD (`lib/Service/Engine/`)

| Fact | Location |
|---|---|
| `$searchMode = $agentData['ragSearchMode'] ?? 'hybrid'` — the default is `hybrid` | `ContextRetrievalHandler.php:125` |
| `$includeFiles = $ragSettings['includeFiles'] ?? ($agentData['searchFiles'] ?? true)` | `:127` |
| `$includeObjects = $ragSettings['includeObjects'] ?? ($agentData['searchObjects'] ?? true)` | `:128` |
| `semantic`/`hybrid` **log and degrade to keyword** — no OR vector facade at HEAD | `:153-166` |
| `$results = $this->searchKeywordOnly(query: $query, limit: $fetchLimit)` — **unconditional** | `:167` |
| `$skipFile`/`$skipObject` discard rows **after** the search | `:178-179` |
| `searchObjectsPaginated(['_search', '_limit', '_register' => null, '_schema' => null])` | `:333-341` |
| The docblock **documents the nulls as deliberate** | `:313-320` |
| `maybeGenerateTitle()` called synchronously in the run | `Engine.php:424`, defined `:525` |
| `generateConversationTitle()` → `generateTextViaConfiguredLlm()` → `$driver->chat->generateText()` | `ConversationManagementHandler.php:135, 148, 483` |
| A `'New conversation'` placeholder title is written at creation | `ChatStreamController.php:689` |

## (a) Skip the search when its results are all discarded

`includeFiles`/`includeObjects` are **post-filters**, not pre-conditions. With both `false`, line
167 still runs the full search and lines 178-179 discard every row. The context text is empty
either way — **the only difference is 26–62s.**

The guard goes before line 167:

> if `$includeFiles === false && $includeObjects === false` → skip the search, return empty
> context, log the skip.

**This is behaviour-preserving by construction.** The acceptance criterion is therefore *both*
"no search is issued" *and* "the resulting context is byte-identical to before". A test that only
asserts the latency proves nothing about correctness.

Note this is a narrow win: it only helps agents that have turned both flags off. It is included
because it is free, provably safe, and removes an absurdity (a minute of work whose output is
guaranteed to be discarded). **The broad win is (b).**

## (b) Scope the search — and why you must NOT just delete the nulls

This is the change's centre of gravity and its only real hazard.

The 26–62s is an unscoped full-text scan across **all registers and schemas** — 2116 magic tables
on this instance. The naive fix is to delete `'_register' => null, '_schema' => null`. **Do not.**
The docblock at lines 313-320 explains why they are there:

> *"`_register`/`_schema` are explicitly nulled so a previous caller's ambient register/schema
> context on the shared `ObjectService` instance cannot silently scope RAG retrieval down to one
> schema."*

`ObjectService` is a **shared instance carrying ambient `setRegister()`/`setSchema()` state**
(the fluent idiom used all over this codebase, e.g.
`ChatStreamController::pickFallbackAgentForUser()`). Deleting the nulls does not make the search
unscoped-but-faster; it makes it scoped **to whatever the last caller happened to set** — a
non-deterministic scope that depends on call order. That is strictly worse than today: today's
search is slow but correct; that search would be fast and silently, unpredictably wrong.

**The nulls must be replaced by an explicit, derived scope — never removed.** The contract:

1. **Derive the scope from the agent.** The agent already declares what it should see. The
   natural source is its context/RAG configuration; `retrieveContext()` already receives
   `$agentData` and `$ragSettings`, and already resolves `$viewFilters` from `$agentData['views']`
   (lines 135-143 — computed but, per the ported note, **not yet applied to the search**; the
   original left a "TODO: Apply view filters here"). Deriving the search scope is the natural
   place to finally use that resolution, or a sibling of it.
2. **Pass it explicitly.** `searchKeywordOnly()` takes the scope as a parameter. `_register` and
   `_schema` are **always** set to a concrete value — never left to ambient state, never null.
3. **Cap it.** Whatever the derivation yields, a hard cap on the number of registers/schemas
   bounds the worst case. An agent with no usable scope declaration must land on a **bounded
   default**, not on "all 2116".
4. **Log it.** Every retrieval logs its resolved scope. A silently-narrow scope is the failure
   mode here; it must be diagnosable from a log, not from a bisect.

### The trade this makes, stated plainly

**This trades recall for latency, and the failure is silent.** An over-narrow scope does not
error — the agent answers, just with worse context, and nobody notices for weeks. This is why
every scoping requirement in the spec carries a **recall** acceptance criterion alongside the
latency one, and why the resolved scope is logged. If the derivation cannot be made
confidently for a given agent, the correct fallback is a **bounded** scope, not an unbounded one:
slow-and-complete was the old bug; fast-and-silently-empty would be a worse one.

### What the scope should be derived from — open

`$agentData['views']` is resolved but unused; agent RAG settings are the other candidate; a
register-level cap is the floor. **The precise derivation is the change's main open question** —
recorded in DEFERRED_QUESTIONS rather than guessed at here, because getting it wrong is exactly
the silent-recall-loss failure above.

## (c) Take title generation off the reply path

`Engine::maybeGenerateTitle()` (line 525) runs synchronously at line 424, inside the turn. It
spawns a **second `claude` process** — the second of the two per message — for a sidebar label.
~20s of the user's wait.

The reply does not depend on the title. `ChatStreamController::resolveConversation()` already
writes `'title' => 'New conversation'` (line 689) at creation, so **there is always a placeholder
and an untitled conversation is never a failure state.**

Options:

| Option | Verdict |
|---|---|
| Drop titles | **Rejected.** Real product value; the sidebar needs a label. |
| Generate the title from the first message without an LLM (truncate) | **Rejected as the primary fix** — `generateFallbackTitle()` already exists for the error path, but a truncated title is visibly worse. Keep it as the fallback it is. |
| Generate it **after** the reply is delivered (background job / post-stream) | **Chosen direction.** The user waits ~20s less; the title arrives moments later. |

The mechanism (NC background job vs post-stream dispatch) is left to apply — both remove the
block. Two hard constraints on whichever is chosen:

1. **`saveObject()` is PUT-semantic.** Omitted schema properties are **silently nulled**. A
   title-only write MUST carry every `Conversation` field forward (`title`, `userId`, `agentId`,
   `metadata`) or it will null `userId`/`agentId` and orphan the conversation. Test that a
   non-changed field survives the title write — this is a known, repeated failure mode in this
   codebase.
2. **The tenant-model-policy read must survive.** `generateConversationTitle()` takes
   `?string $organisation` and `Engine.php:282` notes `maybeGenerateTitle()`'s policy read
   explicitly. Deferring the call MUST NOT drop the organisation argument — a background job with
   `$organisation = null` silently **skips policy enforcement** (the parameter's documented
   backward-compatible default). That would turn a latency fix into a governance hole.

## API Design

No endpoint, route, verb, auth posture or response envelope changes. The chat stream's phase
timings change; its contract does not.

## Database Changes

**None.** No tables, columns, OpenRegister schema definitions, or data transformations. No
migration class. All three edits are behavioural: a guard, a parameter, and a call-site move.

## Nextcloud Integration

- Controllers: none modified (`ChatStreamController:689` is read as context only)
- Services: `ContextRetrievalHandler` (a, b), `Engine` (c), `ConversationManagementHandler` (c —
  called differently, not modified)
- Mappers/Entities: none — persistence via OpenRegister `ObjectService` (ADR-022)
- Events/Hooks: a background job (`OCP\BackgroundJob`) if that mechanism is chosen for (c)

## Security Considerations

Not a no-op — two of the three edits touch a boundary:

- **(b) is an information-boundary change.** The search's scope determines what an agent can pull
  into its context. Today it is unscoped across all registers/schemas, so the *only* thing
  preventing cross-tenant retrieval is whatever `searchObjectsPaginated` enforces internally. This
  change **narrows** that scope, which is directionally safer — but the derivation MUST NOT become
  a *widening* for any agent, and MUST NOT be derivable from user-supplied input in a way that
  lets a caller request a broader scope than their agent declares. Scope is derived from the
  agent's configuration, never from the request.
- **Ambient scope is the real trap.** Deleting the nulls (see (b)) would make retrieval scope
  depend on the last caller's `setRegister()`/`setSchema()` — a non-deterministic,
  cross-request-shaped scope on a shared service instance. Explicit values, always.
- **(c) must not drop the organisation.** Deferring the title call with `$organisation = null`
  silently disables tenant-model-policy enforcement for that call. A latency fix must not become
  a policy bypass.
- (a) has no security impact — it removes work whose output was already discarded.

## NL Design System

Not applicable — no frontend change. Latency improvements are felt in the existing Chat page; no
component, token, or markup changes.

## File Structure

```
lib/
  Service/
    Engine/
      ContextRetrievalHandler.php   # MODIFIED: skip guard before :167; explicit scope on searchKeywordOnly() :333; docblock :313-320 corrected
      Engine.php                    # MODIFIED: maybeGenerateTitle() :424 moved off the reply path
```

No file created; none deleted.

## Seed Data

**Not applicable — this change introduces no new schemas and no new entities.**

ADR-001/ADR-016 require seed data for every schema a change introduces or modifies. This change
introduces none and modifies none — it is three behavioural edits to two service classes. There
is no register re-import, no magic table, and no new object shape to seed.

The change is measured against **existing live data on the reference instance**, which is the
opposite of a seed-data need: the 26–62s `context` figure is precisely a symptom of there being
**2116 magic tables** of real data to scan. A synthetic seed would make the defect
*unmeasurable* — on a small dataset the unscoped scan looks fine. The acceptance criteria are
therefore written against the live instance's table count and the measured 26–62s / 9–17s / 65–106s
baselines, not against fixtures.

**Net seed-data delta: none.**

## Declarative-vs-imperative decision

**Not applicable in the ADR-031 sense — and here is the one line, plus why the question arises.**

This change touches no lifecycle/status workflow, no aggregations, no derived fields, no
notifications, no relations, and no widgets. It adds a conditional, threads a parameter, and
moves a call site. No register JSON is edited; no declarative dialect is added or modified. It is
`kind: code` end to end.

The question is worth answering rather than skipping because (b) *sounds* declarative — "derive
the search scope from the agent's configuration" reads like a dialect. It is not: the scope is
**read** from existing agent data (`$agentData['views']`, RAG settings) and passed as a runtime
parameter to a search call. No new declarative surface is introduced, and none should be. If a
future change wants agents to *declare* a retrieval scope in the register, that is a `kind: config`
schema change with its own migration story — explicitly not this change.

## Trade-offs

### Rejected: pre-warm the `claude` CLI when the chat page opens

**Evaluated and rejected on the mechanics, not on taste.**

1. **`claude -p` is one-shot.** It spawns, answers, and exits. There is no resident process to
   warm — a pre-warmed CLI has already exited by the time the user sends a message. Nothing is
   reusable.
2. **Context cannot be precomputed.** The context search is keyed on the user's message
   (`searchKeywordOnly(query: $query, …)`), and that message does not exist when the page opens.
   Pre-warming would have to guess the query.

Measurements that bound the ceiling anyway: `llm` floors at **9s for a two-character answer** —
that is spawn + round-trip with essentially no generation. Even a hypothetical free warm-up
leaves the 9s floor and does nothing about the **26–62s** `context` phase, which is the larger
cost and is what this change actually attacks.

**Do not spec pre-warming.** The real mechanism for the `llm` phase is session reuse — the
separate `claude-cli-session-reuse` change. This change and that one are complementary: this one
removes the second spawn and the unscoped scan; that one makes the remaining spawn cheaper.

### Rejected: make `semantic`/`hybrid` actually semantic

Tempting — `hybrid` is the **default** (`ragSearchMode`, line 125) and both modes currently log a
degradation notice and fall through to keyword (lines 153-166). But **no public OpenRegister
vector-search facade exists at HEAD**, so implementing it means building that facade. That is a
different change with a different blast radius. This change makes the keyword path — the path
every agent actually takes — fast and correctly scoped. The degradation notice stays honest.

### Rejected: raise the `_limit` / lower `$fetchLimit` to speed the scan

`$fetchLimit = $totalSources * 2` (line 152) is already small (default `ragNumSources` 5 → 10).
The 26–62s is not the result count; it is the **2116-table scan** that produces it. Tuning the
limit is fiddling with the wrong number.

### Not conflated: ADR-024 / `ContextAssembler`

**ADR-024 (`Status: accepted`) is a different code path and is untouched by this change.** It owns
`ContextAssembler` (`lib/Service/Engine/ContextAssembler.php`) — the budgeted preamble built from
a `Context` object's `files` / `objectQueries` / `viewRefs` / `documents`, where `objectQueries`
are **already scoped** (`{register, schema, …}` per query). This change touches
`ContextRetrievalHandler::searchKeywordOnly()` — an entirely separate, **unscoped** RAG seam.

Same word, different code, different scoping posture. **Do not "align" them, and do not cite
ADR-024's scoped `objectQueries` as evidence that this path is already scoped — it is not.**
Neither constrains the other.
