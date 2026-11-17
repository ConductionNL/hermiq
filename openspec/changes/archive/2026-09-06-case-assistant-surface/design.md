---
kind: code
---

# Design: case-assistant-surface

## Context

`chat#sendMessage` (agent-engine-port) is the fleet's existing conversational
endpoint. It is Agent-centric: an `Agent` object carries `tools`, `views`,
`ragIncludeObjects`/`ragIncludeFiles`, and a system `prompt`. A caller can
narrow the tool set per-turn via `selectedTools`, but cannot *deny* tools —
`ToolLoop::listAgentFunctions()` treats an empty `Agent.tools` (the schema
default) as "every discovered tool is allowed" (documented in
`hermiq_register.json`'s `Agent.tools` description), and an empty
`selectedTools` from the caller is a no-op narrowing (the intersection branch
in `listAgentFunctions()` is skipped entirely when `$selected` is empty — the
whitelist falls through unchanged). A leaf app that wants a guaranteed
tool-free conversational surface therefore cannot get one from `chat#sendMessage`
by passing empty arrays; it would need to configure the `Agent.tools` field
itself, which is exactly the kind of "authz/validation defaults open"
footgun hermiq#57 flagged (a classifier/whitelist that treats "nothing
specified" as "everything allowed" instead of "nothing allowed").

## Goals / Non-Goals

- Goal: a conversational endpoint where **zero tool execution is possible by
  construction**, not by careful configuration.
- Goal: reuse every other piece of the existing chat stack — LLM client
  (`ProviderFactory`, LlmSettings-backed), guardrail input/output filtering,
  message persistence + OR's automatic audit trail, tenant-model-policy
  enforcement.
- Non-goal: RAG search across OR objects/files (`ContextRetrievalHandler`) —
  this surface's grounding is the caller-supplied `context.contextData`, a
  bounded text blob the CALLER already authorized (see procest's
  `case-assistant-via-hermiq` change for the fail-closed enrichment step).
- Non-goal: tool/function calling of any kind. Documented as future work —
  a caller that needs it should use `chat#sendMessage` with a properly
  tool-scoped `Agent`.

## Decisions

### Decision 1: guarantee zero tools via a non-empty, non-matching whitelist sentinel — not via an empty whitelist

`AssistantService` auto-provisions (find-or-create, idempotent, keyed by
`name = "Case Assistant ({app})"` within the caller's organisation) a
dedicated `Agent` per calling app with `tools: ['__none__']`. Traced through
`ToolLoop`:

- `listAgentFunctions()`: `$whitelist = ['__none__']` is non-empty, so the
  "per-request narrowing" branch (`empty($selected) === false`) is irrelevant
  either way — we also always pass `selectedTools: []`.
- `resolveFunctions(['__none__'])`: `$needsCatalog = ($whitelist === [] ||
  hasWildcardGrant(...))` — `['__none__']` is neither empty nor a
  `{app}.{schema}.*` wildcard, so `$needsCatalog === false` and the whitelist
  is passed straight to `$toolRegistryFacade->listTools(toolWhitelist:
  ['__none__'])`, which looks up that one id. `__none__` never matches a real
  tool id (`{app}.{toolName}` / `{app}.{schema}.{verb}` — always contains a
  dot), so the facade deterministically returns `[]`.

This is the OPPOSITE of the `Agent.tools = []` default — verified by a unit
test asserting `AssistantService`'s provisioned agent always resolves to zero
`ToolLoop` functions, so a future refactor of `ToolLoop`'s empty-whitelist
semantics cannot silently re-open this surface without breaking the test.

### Decision 2: call `ResponseGenerationHandler::generateResponse()` directly, skip `Engine::processMessage()`

`Engine::processMessage()` unconditionally calls `ContextRetrievalHandler::
retrieveContext()` (OR-wide RAG search) and `ContextAssembler::
assembleForAgent()` (resolves `Agent.contextRefs`). Neither applies here —
this surface has no RAG search and the provisioned Agent has no `contextRefs`.
`AssistantService` therefore composes the same building blocks
`Engine::processMessage()` does (`GuardrailPolicyService`,
`MessageHistoryHandler`, `ResponseGenerationHandler`) directly, passing:

- `context: ['text' => <rendered contextData>, 'sources' => []]` — reuses
  `ResponseGenerationHandler`'s existing "Use the following context to answer
  the user's question" injection block verbatim; when `contextData` is empty
  the block is skipped exactly like an agent with RAG disabled today.
- `cnAiContext: ['app' => ..., 'objectType' => ..., 'objectRef' => ...]` —
  reuses the existing "CURRENT APP CONTEXT" system-prompt block.
- `selectedTools: []`, `channel: null` (blocking), `contextPreamble: ''`,
  `dryRun: false`.

This keeps the two surfaces independently reviewable (agent-engine-port's
full RAG+tool orchestration vs. this surface's deliberately narrow scope)
without duplicating the LLM-calling code itself.

### Decision 3: reuse `Conversation`/`Message` schemas — no new persistence

Same schemas `chat#sendMessage` uses. `Conversation.metadata.surface =
'assistant-converse'` tags conversations created by this endpoint (does not
gate anything — `chat#sendMessage` and this endpoint share ownership
semantics — it is purely so a future maintainer scanning Conversation objects
can tell which surface created them). Ownership (`conversation.userId ===
caller`) is the ONLY access guard on `sessionId` reuse — identical to
`ChatController`'s existing pattern (gate-7 no-admin-idor convention).

### Decision 4: input/output guardrails and length caps

- `message`: required, non-empty, ≤ 8000 characters → 400.
- `context.contextData` (JSON-encoded): ≤ 20000 characters → 400 (rejected,
  not silently truncated — a caller assembling case context server-side
  should already be bounding it; a 400 surfaces a caller bug immediately
  instead of quietly dropping context the LLM never sees).
- Input/output filtered through `GuardrailPolicyService::filterInput()`/
  `filterOutput()` exactly like `Engine::processMessage()` — same
  fail-open-when-service-unavailable fallback (Decision 1 of
  `agent-guardrails`, pre-existing, unchanged here) for parity with the rest
  of the fleet's guardrail surface.

## Risks / Trade-offs

- The `__none__` sentinel depends on `ToolLoop`'s current whitelist semantics.
  Mitigated by a unit test pinned directly against `ToolLoop::
  listAgentFunctions()` (not just `AssistantService`'s happy path) so a
  future change to that resolution logic fails loudly here.
- Auto-provisioning an `Agent` per calling app means Hermiq's Agent list now
  contains machine-provisioned entries. Named distinctly (`Case Assistant
  ({app})`) and `isPrivate: true` so they do not appear as shareable/private
  agents to end users browsing the Agent gallery.

## Migration Plan

None — additive only (`Conversation`/`Message` schemas unchanged; a new
route + two new classes).
