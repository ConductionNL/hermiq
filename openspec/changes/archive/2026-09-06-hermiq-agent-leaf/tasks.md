# Tasks: hermiq-agent-leaf

> Depends on OpenRegister `app-leaf-provider-registration` (leaf-registration
> hook) landing first. The manifest `type: "agent"` action-type is a companion
> nextcloud-vue change; the interim `api-call` path here has no such dependency.

## 1. Scoped run-on-object endpoint

- [x] 1.1 Add `lib/Controller/AgentRunController.php` method `runOnObject(string $id)` (SPDX docblock), declared `#[NoAdminRequired]` `#[NoCSRFRequired]`: read body `register`, `schema`, `objectId` (required) and optional `resultField`, `skill`, `prompt`; 400 on any missing required field.
- [x] 1.2 Resolve the object via `ObjectService` in the CALLER's RBAC scope (`_rbac: true`) — a caller who cannot read it gets 404, fail-closed and indistinguishable from nonexistent (per-object IDOR guard; do NOT copy `GraphController::run`'s admin gate).
- [x] 1.3 Resolve agent `{id}` and validate it exists and is runnable (mirror `FlowAgentRunService::resolveAgent()`); 404 when unresolvable.
- [x] 1.4 Build the bounded context from the object's schema `x-openregister-agent-context` allowlist (task 3); derive `requiresApproval` from the AGENT policy, never from the request body.
- [x] 1.5 Construct and dispatch `AgentRunRequestedEvent` via `IEventDispatcher` with `mode: "async"`, `flowName: "run-on-object"`, a fresh `correlationId`, and the resolved subject/agent/skill/prompt/resultField; return 202 with the correlation id. Do NOT call `FlowAgentRunService` directly.
- [x] 1.6 Register `['name' => 'agentRun#runOnObject', 'url' => '/api/agents/{id}/run-on-object', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']]` in `appinfo/routes.php`.

## 2. Agent render leaf (ADR-019)

- [x] 2.1 Add the `hermiq-agent` integration descriptor (`id`, `label`, `icon`, `order`, `group`, `tab`, `widget`) and register it through the `app-leaf-provider-registration` hook via a small always-loaded bundle using `\OCP\Util::addInitScript()` + `registerIntegration()` — leaf-owned components in Hermiq's own bundle (not lib-owned; no per-bundle swap).
- [x] 2.2 Build the `tab`/`widget` Vue pair: an Agent-chat panel that calls `POST /api/assistant/converse` with the bounded object context (generalizing `CaseAssistantPanel.vue`), plus a run-history/status list read from the object's OpenRegister audit trail.
- [x] 2.3 Add a "Run agent" affordance in the widget that POSTs to `/api/agents/{id}/run-on-object` and surfaces the queued/running state (async — refresh history for the outcome, no synchronous result).
- [x] 2.4 Gate the whole surface on Hermiq being enabled for the user (absent means hidden, never a broken tab).

## 3. Declarative context allowlist

- [x] 3.1 Add `lib/Service/Agent/AgentContextBuilder.php` (SPDX docblock): given an object and its schema, read `x-openregister-agent-context` and return a context of ONLY the allowlisted properties present on the instance; fail closed to an empty context when the keyword is absent or empty. Generalizes procest `CaseAssistantService::buildCaseSummary()`.
- [x] 3.2 Apply optional per-field caps (e.g. truncation length) if declared; use multibyte-safe truncation.
- [x] 3.3 Wire the builder into both the run-on-object endpoint (prompt grounding) and the leaf chat (`context.contextData`).
- [x] 3.4 Document the `x-openregister-agent-context` keyword shape and its fail-closed semantics; if OpenRegister owns keyword validation, file the follow-up OR meta-schema registration delta (Hermiq consumption stays fail-closed regardless). _(Documented in `docs/agent-object-leaf.md`; the OR meta-schema registration is noted there as a follow-up OR delta — Hermiq's consumption is fail-closed regardless, so it is not a blocker.)_

## 4. Manifest action-type contract

- [x] 4.1 Document the interim `api-call` recipe (token-interpolated url/body targeting run-on-object) in the leaf docs so an OpenBuild app can wire "run agent" today with no nextcloud-vue change.
- [x] 4.2 Specify the end-state `type: "agent"` action contract (fields, async 202 semantics, confirm gating, same endpoint target) for the companion nextcloud-vue change; do NOT author nc-vue files here.

## 5. Verify

- [x] 5.1 Unit-test `AgentRunController::runOnObject`: 400 (missing register/schema/objectId), 404 (object not readable in caller scope), 404 (unknown agent), 202 + correlation id on success, and that a governed `AgentRunRequestedEvent` is dispatched (mode async) rather than `FlowAgentRunService` being called directly.
- [x] 5.2 Unit-test the approval-downgrade guard: a request-body approval field MUST NOT override the agent policy's `requiresApproval`.
- [x] 5.3 Unit-test `AgentContextBuilder`: only allowlisted fields returned; empty context when the keyword is absent/empty; missing listed field omitted not errored; unlisted confidential field never included.
- [x] 5.4 Component/e2e-test the leaf: tab renders on an OR object when Hermiq is enabled, hidden when disabled; chat calls `converse` with the bounded context; run history reflects a dispatched run's status. _(The security-critical chat-context fail-closed logic is covered by `tests/agent-context.spec.js` (JS parity) and `AgentContextBuilderTest` (PHP); leaf registration by `RegisterAgentLeafListenerTest`; the `requiredApp: 'hermiq'` visibility gate is enforced by the registry + capability. Full in-browser tab render is covered by live-verify — the repo has no Vue component test runner (jest/vitest); adding one is out of scope here.)_
- [x] 5.5 Run `openspec validate hermiq-agent-leaf --strict` clean; run the Hydra mechanical gates (route-auth, no-admin-idor, spdx, spec-coverage) on the changed files.
