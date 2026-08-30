# Tasks: woo-llm-anonymisation (hermiq)

## 1. AssistantService::detectPii()

- [x] 1.1 Add constants: `MAX_DETECT_TEXT_LENGTH` (12000 chars), detector agent name template.
- [x] 1.2 `detectPii(userId, text, context)`: validate `text` (non-empty, ≤ `MAX_DETECT_TEXT_LENGTH`) and `context.app` (reuse `validateContext()`), throwing coded exceptions (400) on violation.
- [x] 1.3 Resolve effective `GuardrailPolicy` for the app (no organisation available for a one-shot call — same fully-open fallback `resolveGuardrailPolicy('')` already uses); derive a copy with `inputFilters.piiAction` forced `'off'`; call `filterInput()`; throw `GuardrailBlockedException` on a prompt-injection block.
- [x] 1.4 `findOrCreateDetectorAgent(app)`: find-or-create a dedicated `tools: ['__none__']`, `isPrivate: true` Agent named `PII Span Detector ({app})` with a strict-JSON-output system prompt covering persons/BSN/addresses/contact data/signatures/medical/financial mentions.
- [x] 1.5 Call `ResponseGenerationHandler::generateResponse()` with `messageHistory: []`, `selectedTools: []`, `channel: null`, `trace: null`, `dryRun: false` — NO conversation/message persistence.
- [x] 1.6 Parse the reply as JSON (strip a markdown code fence if present); validate shape `{"spans": [{"start": int, "end": int, "category": string, "confidence"?: string}]}`; throw `Exception(..., 502)` on any parse/shape failure.
- [x] 1.7 Return `{spans, usage}` (`usage` = `ResponseGenerationHandler::$lastUsage`).

## 2. Controller + routes

- [x] 2.1 Add `AssistantController::detectPii()`: auth guard (401), delegate to `AssistantService::detectPii()`, reuse the SAME error-mapping switch `converse()` uses (400/401/403/422/502/503/500 — 404 not applicable, no session).
- [x] 2.2 Register `['name' => 'assistant#detectPii', 'url' => '/api/assistant/detect-pii', 'verb' => 'POST']` in `appinfo/routes.php`.

## 3. Verify

- [x] 3.1 Unit-test `AssistantService::detectPii()`: 400s (empty/oversized text, missing context.app), guardrail prompt-injection block (piiAction bypass verified via a policy fixture whose input piiAction is `redact` — asserting the text passed to `generateResponse()` is NOT redacted), tool-free agent reuse (dedicated detector agent, `tools: ['__none__']`), JSON parse failure → 502, happy path → spans array returned, `messageHistory` passed as `[]` and `MessageHistoryHandler::storeMessage()` never called.
- [x] 3.2 Unit-test `AssistantController::detectPii()`: 401 unauthenticated, 400 validation, success envelope shape, error-code mapping (guardrail 422, parse-failure 502).
- [x] 3.3 `composer check:strict` (lint, phpcs, phpmd, psalm, phpstan, phpunit) green — CI way (php:8.3-cli container), diffed against pristine `origin/development` baseline.

## Acceptance criteria

- `POST /api/assistant/detect-pii` accepts `{text, context}`, returns
  `{spans, usage}` within one request.
- No `Conversation`/`Message` OR object is created by this endpoint —
  verified by a pinned unit test asserting `MessageHistoryHandler` is never
  touched.
- Prompt-injection blocking still applies; PII input-redaction is bypassed
  by construction — both verified by pinned unit tests.
- Reuses `ResponseGenerationHandler`/`ProviderFactory`/`GuardrailPolicyService`
  — zero new outbound-HTTP/LLM-calling code added anywhere in this change.

## Quality reminders

- SPDX tags in every changed PHP file's docblock; `@spec` tags referencing this change.
- No sed/awk/scripts on code — Edit/Write tool only.
- No new composer dependencies.
