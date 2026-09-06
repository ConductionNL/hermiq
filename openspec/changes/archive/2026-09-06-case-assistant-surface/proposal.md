---
kind: code
---

# Proposal: case-assistant-surface

## Why

Every major competitor in the case-management space (Decos Joni, PinkRoccade
AiConnect, Centric Mynte) ships an in-product conversational AI assistant.
Fleet rule: AI functionality lives in Hermiq — leaf apps (procest, etc.) must
not grow their own conversational/LLM logic. Hermiq already has a full
Agent-based chat surface (`chat#sendMessage`, agent-engine-port) but it is
deliberately heavyweight: it requires a persisted `Agent` with tool/RAG
configuration, and it can execute tools end-to-end. A caller that wants a
narrow, auditable "answer questions grounded ONLY in the context I hand you,
never touch a tool" surface — the shape a leaf app's case-detail page needs —
has no safe way to get that from `chat#sendMessage`: an `Agent.tools: []`
(the natural default) resolves to **every discovered tool being allowed**
(`ToolLoop::listAgentFunctions()`/`resolveFunctions()` — documented behaviour,
not a bug, but the wrong default for an unattended, narrowly-scoped surface).
Passing `selectedTools: []` from the caller does not change this — an empty
selection is a no-op narrowing, not a deny-all.

This change adds a minimal, purpose-built conversational endpoint that never
wires the tool-calling machinery at all — no execution capability exists to
misconfigure away — while reusing every other piece of the existing chat
stack (LlmSettings-backed `ProviderFactory`, `ResponseGenerationHandler`,
`MessageHistoryHandler`, `GuardrailPolicyService`, the `Conversation`/`Message`
OpenRegister schemas and their automatic OR audit trail, tenant-model-policy
enforcement).

## What Changes

- Add `POST /api/assistant/converse`: `{sessionId?, message, context: {app,
  objectType?, objectRef?, contextData?}}` → `{sessionId, reply, usage}`.
- Add `lib/Service/Assistant/AssistantService.php`: orchestrates one
  conversational turn — resolves/creates a per-calling-app `Conversation`
  (auto-provisioning a dedicated, tool-locked `Agent` per app on first use),
  applies the SAME input/output `GuardrailPolicyService` filtering
  `Engine::processMessage()` applies, stores the turn via the existing
  `MessageHistoryHandler`, and calls `ResponseGenerationHandler::generateResponse()`
  directly — **skipping** `Engine`'s `ContextRetrievalHandler` RAG search and
  `ContextAssembler` preamble (this surface's "context" is the caller-supplied
  `context.contextData`, not an OR-wide RAG query) and passing `selectedTools:
  []` against an `Agent.tools: ['__none__']` sentinel so `ToolLoop` resolves
  zero functions by construction (verified: a non-empty, non-wildcard
  whitelist is passed straight to the tool facade — an unmatched id
  deterministically resolves to `[]`; see design.md).
- Add `lib/Controller/AssistantController.php` (`@NoAdminRequired`,
  `@NoCSRFRequired`): auth guard, message/context length caps → 400, error
  mapping mirroring `ChatController::sendMessage()` (400/401/403/404/422/503).
- Register the route in `appinfo/routes.php`.
- Unit tests for `AssistantService` (turn orchestration, guardrail block,
  zero-tool guarantee) and `AssistantController` (validation, auth, error
  mapping).

## Impact

- Affected specs: new `case-assistant-surface` capability.
- Affected code: `lib/Service/Assistant/AssistantService.php`,
  `lib/Controller/AssistantController.php`, `appinfo/routes.php`,
  `tests/Unit/Service/Assistant/AssistantServiceTest.php`,
  `tests/Unit/Controller/AssistantControllerTest.php`.
- NOT in scope: tool execution on this surface (documented as future work —
  a caller that needs tool-calling should use `chat#sendMessage` with a
  properly-scoped `Agent` instead).
