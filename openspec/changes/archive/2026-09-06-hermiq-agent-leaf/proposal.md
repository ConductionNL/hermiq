---
kind: code
---

# Proposal: hermiq-agent-leaf

## Why

Any OpenBuild manifest app that stores objects in OpenRegister should be able
to surface — and trigger — Hermiq agents on those objects without hand-rolling
its own AI plumbing. procest proved the shape: to put a case-assistant on its
CaseDetail page it hand-wrote ~6 files (`HermiqAssistantClient`,
`HermiqAssistantException`, `CaseAssistantService` with a bespoke
`buildCaseSummary()` allowlist, `CaseAssistantPanel.vue`, a route, a
per-(user,case) session store). Every other leaf app that wants the same thing
copies those six files and re-derives the same context-safety decisions by
hand. That does not scale, and each hand-rolled context builder is a fresh
place to accidentally leak PII.

Two governed Hermiq paths already exist and are sanctioned:

- The **agent-run command** path: an OpenRegister `x-openregister-flows` action
  of `type: "agent"` dispatches `AgentRunRequestedEvent`; Hermiq's
  `AgentRunRequestedListener` enqueues `AgentRunRequestedJob`, which runs
  `FlowAgentRunService` through the existing oversight rails (kill-switch,
  budget hard cap, human-approval gate, redacted per-run audit) and writes the
  result back to the object. This is wired today (verified in
  `lib/AppInfo/Application.php`, `lib/Listener/AgentRunRequestedListener.php`,
  `lib/Service/FlowAgentRunService.php`).
- The **tool-free chat** path: `AssistantController::converse`
  (case-assistant-surface) — the narrow, no-tool-execution conversational
  surface procest already consumes.

What is missing is a **reusable render surface** that exposes both on any OR
object in any app, plus a **user-initiated, object-scoped** way to fire the
governed run (today only a declarative flow, an admin-only
`GraphController::run`, a schedule, or a webhook can start one — none of them
is a per-object "run this agent now" affordance a user can click from a detail
page). This change adds that surface as an OpenRegister integration leaf
(ADR-019 render leaf + ADR-022 apps-consume-OR-abstractions), so leaf apps stop
copying procest's six files and get an Agent tab/widget for free.

## What Changes

- **Agent render leaf (ADR-019).** Hermiq ships an OpenRegister integration
  provider `hermiq-agent` (a small always-loaded bundle calling
  `registerIntegration()` on `window.OCA.OpenRegister.integrations`) contributing
  a `tab` + `widget` pair. The pair renders (a) object-scoped agent chat that
  reuses `AssistantController::converse` — the tool-free path — and (b) the
  object's agent run-history/status. Render-only: the leaf performs no LLM/tool
  logic and dispatches no side effects except by POSTing to the endpoint below.
  Gated on Hermiq being enabled (absent means hidden, not a broken tab).
- **Scoped run-on-object endpoint.** New `POST /api/agents/{id}/run-on-object`,
  `#[NoAdminRequired]`, authorization scoped by the triggering object's own
  OpenRegister permissions (NOT admin-gated like `GraphController::run`). Body:
  `{register, schema, objectId, resultField?, skill?, prompt?}`. It resolves the
  object through the caller's RBAC scope (fail-closed to 404), builds the
  bounded context (below), and dispatches the SAME governed
  `AgentRunRequestedEvent` recipe (ADR-041 typed command) with `mode: "async"`.
  It never calls `FlowAgentRunService` directly and never re-implements a run.
- **Declarative context allowlist.** A new `x-openregister-agent-context`
  keyword on a schema (a list of property names, beside `x-openregister-flows`)
  lets the leaf/endpoint auto-build a bounded, fail-closed object context from
  ONLY those fields — generalizing procest's hand-coded `buildCaseSummary()`.
  Absent or empty means an empty context, never the whole object.
- **Manifest action-type contract (companion, spec-only here).** Document the
  contract for a new discriminated `type: "agent"` manifest action (a sibling
  to `api-call` / `object-op` in `app-manifest-v2.schema.json` +
  `actionsDispatcher`) that targets the endpoint above, AND the interim
  `type: "api-call"` fallback that already works against it. The nc-vue schema
  and dispatcher files are authored in a separate nextcloud-vue change; this
  change only fixes the CONTRACT they must satisfy.

## Impact

- Affected specs: new `agent-object-leaf` capability.
- Depends on the OpenRegister change `app-leaf-provider-registration`
  (sibling-app leaf-registration hook — a server-side `RegisterLeafProviders`
  collect-event plus the JS integration-registry render contract). This change
  is its first consumer.
- Companion nextcloud-vue change: the `type: "agent"` manifest action-type +
  `actionsDispatcher` case (contract specified here, files authored there).
- Coordinates with the OR flow-engine runtime programme (or#2067/2068/2070,
  MCP surface or#2071, and hermiq#35 retiring the Hermiq `GraphExecutor` onto
  the OR flow engine). This change does NOT re-spec any of that — the run
  invocation rides the existing sanctioned `AgentRunRequestedEvent` path and
  references those tickets as dependencies only.
- No breaking changes: additive endpoint, additive schema keyword, additive
  leaf registration. procest's existing case-assistant is unaffected and can
  migrate onto the leaf later.
