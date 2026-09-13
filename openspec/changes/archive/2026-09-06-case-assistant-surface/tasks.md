# Tasks: case-assistant-surface

## 1. AssistantService

- [x] 1.1 Create `lib/Service/Assistant/AssistantService.php` (SPDX docblock): `converse(userId, ?sessionId, message, context)` — validates `message` (non-empty, ≤ 8000 chars) and `context.contextData` (JSON-encoded ≤ 20000 chars), throwing coded exceptions (400) on violation.
- [x] 1.2 `resolveConversation()`: given `sessionId`, load + verify ownership (403 on mismatch, 404 on missing); else auto-provision (find-or-create by `name = "Case Assistant ({app})"`, org-scoped) a dedicated `Agent` with `tools: ['__none__']`, `isPrivate: true`, and create a new `Conversation` tagged `metadata.surface = 'assistant-converse'`.
- [x] 1.3 Apply `GuardrailPolicyService::filterInput()` before persisting the user turn (mirrors `Engine::processMessage()`); throw `GuardrailBlockedException` on a block match.
- [x] 1.4 Persist the user turn via the existing `MessageHistoryHandler::storeMessage()`; build history via `buildMessageHistory()`.
- [x] 1.5 Call `ResponseGenerationHandler::generateResponse()` directly (agent-engine-port's `Engine::processMessage()` is NOT called — see design.md Decision 2) with `context: {text: <rendered contextData>, sources: []}`, `cnAiContext: {app, objectType, objectRef}`, `selectedTools: []`, `channel: null`.
- [x] 1.6 Apply `GuardrailPolicyService::filterOutput()`; persist the assistant turn; return `{sessionId, reply, usage}` (`usage` = `ResponseGenerationHandler::$lastUsage`).

## 2. Controller + routes

- [x] 2.1 Create `lib/Controller/AssistantController.php` (`@NoAdminRequired`, `@NoCSRFRequired`): auth guard (401), delegate to `AssistantService::converse()`, map exceptions to 400/401/403/404/422 (`GuardrailBlockedException` → `errorCode: guardrail_blocked`, mirrors `ChatController`)/503/500 — same pattern as `ChatController::sendMessage()`.
- [x] 2.2 Register `['name' => 'assistant#converse', 'url' => '/api/assistant/converse', 'verb' => 'POST']` in `appinfo/routes.php`.

## 3. Verify

- [x] 3.1 Unit-test `AssistantService`: happy path (new session, existing session), 400s (empty/oversized message, oversized contextData), 403 (foreign session), 404 (unknown session), guardrail block, and — pinned directly against `ToolLoop::listAgentFunctions()` — that the provisioned agent's `tools: ['__none__']` config resolves to zero functions regardless of `selectedTools`.
- [x] 3.2 Unit-test `AssistantController`: 401 unauthenticated, 400 validation, success envelope shape, error-code mapping.
- [x] 3.3 `composer check:strict` (lint, phpcs, phpmd, psalm, phpstan, phpunit) green — CI way (php:8.3-cli container).

## Acceptance criteria

- `POST /api/assistant/converse` accepts `{sessionId?, message, context}`, returns `{sessionId, reply, usage}` within one request (no SSE required).
- No code path in this surface can invoke `ToolLoop::buildFunctionInfos()`/tool execution — verified by a pinned unit test, not just documented.
- Every turn is persisted as `Conversation`/`Message` OR objects — audited by OR's existing automatic audit trail, visible in the existing `chat#getHistory`/`chat#getChatStats` endpoints.
- Guardrail input/output filtering and tenant-model-policy enforcement apply identically to `chat#sendMessage`.

## Quality reminders

- SPDX tags in every new PHP file's docblock; `@spec` tags referencing this change.
- No sed/awk/scripts on code — Edit/Write tool only.
- Reuse `ProviderFactory`/`ResponseGenerationHandler`/`MessageHistoryHandler`/`GuardrailPolicyService` — no new LLM plumbing.
