---
kind: code
depends_on: []
---

# Proposal: session-context-performance

## Summary

A single chat turn takes **65–106s wall**. The two dominant costs are measurable and both are
waste: `context` retrieval takes **26–62s**, and conversation-title generation spawns a **second
`claude` CLI process** that blocks the reply. This change attacks three specific defects in
`ContextRetrievalHandler` and the title path: (a) the context search runs **unconditionally**,
even when its results are all discarded; (b) it scans **all registers and schemas** — 2116 magic
tables on this instance; (c) title generation blocks the user's reply for no reason.

## Motivation

Measured on this box:

| Phase | Measured |
|---|---|
| `context` | **26s – 62s** |
| `llm` | 9s (floor — a 2-character answer) to 17s |
| **wall** | **~65s – ~106s** |

**One user message spawns TWO `claude` CLI processes** — one for the reply, one for the
conversation title.

Three root causes, all verified against HEAD:

1. **The search is wasted work when both include flags are false.**
   `ContextRetrievalHandler::retrieveContext()` calls `searchKeywordOnly()` (line 167)
   **unconditionally**. `includeFiles` (line 127) and `includeObjects` (line 128) only filter the
   **results afterwards** (lines 178-179: `$skipFile`/`$skipObject`). With both false, the search
   runs in full and every row is discarded. **26–62s of work, thrown away.**
2. **The search is unscoped, and — the real finding — nobody asked for it.**
   `searchKeywordOnly()` (line 333) calls `searchObjectsPaginated(['_search' => …, '_limit' => …,
   '_register' => null, '_schema' => null])` — an unscoped scan across **all registers and
   schemas**, i.e. 2038 magic tables on this box. That is the 26–62s.

   **CORRECTED after investigation (2026-07-17).** This proposal originally treated the fix as
   "derive a register/schema scope". That would have optimised a query that should never run:

   - `Agent.enableRag` is a boolean, schema **default false**, described as *"Whether
     Retrieval-Augmented Generation is used to ground responses in context"*, and
     `AgentFormModal` exposes it. **No engine code reads it.** **16 of 16 agents** on this box
     have it false — and every message still paid the full scan.
   - `Agent.views` — *"UUIDs of views that filter which data the agent can access"* — is resolved
     by `retrieveContext()` and then **thrown away** (`TODO: Apply view filters here when view
     filtering is implemented`), even though `searchObjectsPaginated` has taken a `views`
     parameter all along. That is an **access boundary**, not just a perf hint: an agent
     restricted to a view could retrieve from everything. No agent sets views, so there is no
     live exposure.

   So the dominant cost was not an unscoped query but an **unread opt-in flag**, and the scoping
   mechanism the product already intends (views) was an orphaned capability. Both are now wired.
   Residual, deliberate: an agent with RAG **on** and no views is still unscoped — there is
   nothing to scope it to, and inventing a bound would silently starve it of context. It is now
   an opted-in, logged cost rather than a tax on every message.
3. **Title generation blocks the reply.** `Engine::maybeGenerateTitle()` (line 525, called at
   line 424) runs synchronously inside the turn, delegating to
   `ConversationManagementHandler::generateConversationTitle()` (line 135) →
   `generateTextViaConfiguredLlm()` (line 148) → a second `claude` spawn. **~20s of the user's
   wait, for a sidebar label the user has not asked for.**

Note also that `semantic` and `hybrid` modes both **degrade to this same keyword path** (lines
153-166; no OR vector-search facade exists at HEAD), so this is not an edge case — it is the
default path (`ragSearchMode` defaults to `hybrid`, line 125).

## Affected Projects

- [ ] Project: `hermiq` — `lib/Service/Engine/ContextRetrievalHandler.php` (skip + scope the
  search); `lib/Service/Engine/Engine.php` (unblock title generation).

## Scope

### In Scope

- **(a) Skip the search entirely** when `includeFiles === false && includeObjects === false` —
  its results would all be discarded.
- **(b) Scope the search** — stop passing `_register: null, _schema: null`; derive an explicit
  scope from the agent (and/or cap it) so it stops scanning all 2116 magic tables.
- **(c) Unblock the reply** — conversation-title generation must not sit on the user's turn.

### Out of Scope

- **Pre-warming the CLI.** Evaluated and **rejected** — see design.md. `claude -p` is one-shot
  (spawn → answer → exit), so no warm process is reusable, and context cannot be precomputed
  because it depends on the unsent message. Do not spec it.
- **Reusing a CLI session across turns.** That is the separate change
  `claude-cli-session-reuse`, which attacks the `llm` phase. This change attacks `context` and
  the second spawn. They are complementary and independent.
- **Implementing vector/semantic search.** No OR vector-search facade exists at HEAD.
  `semantic`/`hybrid` continue to degrade to keyword; this change makes that path fast, it does
  not make it semantic.
- **`ContextAssembler` / ADR-024.** A different context path — see design.md.
- **Removing the `_register: null` idiom without replacing it.** The nulls are load-bearing
  against a real bug; they must be replaced with an *explicit* scope, never simply deleted.

## Approach

Three independent edits, each measurable on its own:

1. A guard before line 167: if neither files nor objects are wanted, skip the search and return
   an empty context. Cost falls from 26–62s to ~0.
2. Replace `_register: null, _schema: null` with an **explicit, derived** scope. Not by deleting
   the nulls — that would reintroduce the ambient-context bug they defend against (see design.md)
   — but by passing a concrete register/schema list derived from the agent, with a hard cap.
3. Move title generation off the reply path.

## New Dependencies

None.

## Impact

- `lib/Service/Engine/ContextRetrievalHandler.php` — a guard at ~line 167; `searchKeywordOnly()`
  (line 333) gains an explicit scope parameter; its docblock (lines 313-320) must be corrected,
  as it currently documents the nulls as intentional.
- `lib/Service/Engine/Engine.php` — `maybeGenerateTitle()` (line 525) call at line 424.
- Every agent run's `context` phase; every first turn of a conversation.

## Cross-Project Dependencies

None at the code level. `openregister` is consumed via the existing `ObjectService`
(`searchObjectsPaginated`) seam. The 2116-magic-table count is an instance property, not a
dependency.

## Risks

### Risk 1: Scoping the search silently narrows retrieval and agents get worse context
**Severity:** High — **Mitigation:** this is the change's real hazard — it trades recall for
speed, and the failure is *silent*: the agent answers, just less well. The `_register: null`
idiom exists precisely so a previous caller's ambient scope cannot narrow RAG down (see the
docblock at lines 313-320). The replacement MUST be an explicit, derived scope — never a
deletion of the nulls — and the derivation MUST be logged so a narrow scope is diagnosable. Every
scoping requirement carries an explicit recall acceptance criterion, not only a latency one.

### Risk 2: The skip guard changes behaviour for agents relying on the discarded search
**Severity:** Low — **Mitigation:** by construction it cannot. With `includeFiles === false &&
includeObjects === false`, every result is already discarded by the `$skipFile`/`$skipObject`
filters (lines 178-179), so the context text is empty either way. The guard removes the work, not
the output. Assert exactly that: identical context, no search issued.

### Risk 3: Unblocking title generation leaves conversations untitled or races the reply
**Severity:** Medium — **Mitigation:** the title must still arrive, and must not corrupt the
conversation. `resolveConversation()` already writes `'title' => 'New conversation'`
(`ChatStreamController.php:689`), so there is always a placeholder — an untitled conversation is
not a failure state. Beware: `ObjectService::saveObject()` is PUT-semantic and omitted schema
properties are silently nulled, so a title-only write MUST carry all `Conversation` fields
forward or it will null `userId`/`agentId`.

## Rollback Strategy

Revert the commit. All three edits are behavioural, not structural: no schema, no migration, no
new persisted state. Reverting restores the unconditional unscoped search and the blocking title
generation — i.e. today's 65–106s wall. Each edit is independently revertible; if only the
scoping proves harmful to recall, it can be reverted alone while keeping the skip guard and the
unblocked title.

## Capabilities

### New Capabilities

- `agent-context-retrieval`: the RAG keyword-retrieval path — when it runs, what it is scoped to,
  and its latency and recall contract. No existing spec covers `ContextRetrievalHandler`.

### Modified Capabilities

- `agent-engine-port`: conversation-title generation moves off the synchronous reply path.
