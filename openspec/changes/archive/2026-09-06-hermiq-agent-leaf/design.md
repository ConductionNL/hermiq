# Design: hermiq-agent-leaf

## Context

Hermiq is the fleet's single home for AI/agent functionality (fleet rule). Leaf
apps must not grow their own LLM/agent logic; they consume Hermiq. procest is
the precedent: `procest/lib/Service/Assistant/*` + `CaseAssistantPanel.vue`
implement a case-scoped assistant against `AssistantController::converse`, with
a hand-coded `buildCaseSummary()` deciding which case fields are safe to send.
The goal here is to lift that pattern into a reusable OpenRegister integration
leaf so any OpenBuild app gets an "Agent" surface on its objects for free.

Two Hermiq paths are reused verbatim and MUST NOT be rebuilt:

1. **Governed agent-run command** — `AgentRunRequestedEvent` (OpenRegister
   event) → `AgentRunRequestedListener` → `AgentRunRequestedJob` →
   `FlowAgentRunService`. Verified wired in `lib/AppInfo/Application.php`
   (`registerEventListener(AgentRunRequestedEvent::class, ...)`). The service
   applies GATE 1 kill-switch, GATE 2 budget hard cap, GATE 3 human approval,
   runs the turn via `ScheduleService::runAgentAsOwner`, writes the result to
   the object's `resultField`, and writes a redacted per-run audit entry.
   Payload keys: `subjectUuid, subjectRegister, subjectSchema, agent, skill,
   prompt, resultField, requiresApproval, mode, flowName, correlationId`.
2. **Tool-free chat** — `AssistantController::converse` (case-assistant-surface),
   `POST /api/assistant/converse`, `#[NoAdminRequired]`, no tool execution by
   construction.

## Goals / Non-Goals

Goals: a reusable render leaf (chat + run history) on any OR object; a
user-initiated, object-permission-scoped run-on-object endpoint that rides the
existing governed recipe; a declarative context allowlist that removes per-app
context builders; a documented manifest action-type contract.

Non-Goals (owned by the coordinated programme, referenced only): the OR
flow-engine runtime and node types (or#2067), the Nextcloud-native trigger set
(or#2068), execution tooling (or#2070), the MCP server/client/trigger surface
(or#2071), and retiring Hermiq's `GraphExecutor` onto the OR flow engine
(hermiq#35). Also non-goals: any new LLM/tool engine, a synchronous run mode
(v1 dispatch is `mode: "async"` only), and authoring the nc-vue schema/dispatcher
files (companion change).

## Decision 1 — Render / command split: ADR-019 leaf vs ADR-041 command

The surface is split along the two governing ADRs:

- **Render side (ADR-019 + ADR-022).** The `hermiq-agent` integration provider
  contributes only Vue components (`tab`, `widget`) through the OpenRegister
  integration registry (`registerIntegration()` on
  `window.OCA.OpenRegister.integrations`). Per the ADR-019 cross-Vue-bundle
  trap (openregister#1958), a leaf app's bespoke components live in the leaf's
  OWN bundle and are registered live/queued via the load-order-safe
  `registerIntegration()` shim; they are NOT lib-owned and MUST NOT be swapped
  per render-bundle. The render leaf holds NO agent-run authority: everything
  it does is read (chat turns via `converse`, run history via OR audit) or a
  single POST to the command endpoint.
- **Command side (ADR-041).** Starting a governed run is a cross-app command.
  The endpoint dispatches the typed `AgentRunRequestedEvent` and returns; it
  does not reach into Hermiq run internals and does not call
  `FlowAgentRunService` directly. This keeps ONE governed path
  (dispatch → listener → job → service) shared by flows, schedules, webhooks,
  and now the leaf — so kill-switch/budget/approval/audit can never be bypassed
  by adding a new caller.

This is the same separation procest respects (a thin HTTP client for the read
surface; declarative flows for writes) generalized into the OR leaf framework.

## Decision 2 — Endpoint authorization: object-permission-scoped, not admin-gated

`GraphController::run` is admin-gated (`groupManager->isAdmin()` → 403 for
non-admins) because it executes an arbitrary caller-supplied graph. The
run-on-object endpoint is different: the caller names an EXISTING agent id and
an EXISTING object, and asks to run one against the other. The correct
authorization boundary is the OBJECT's own OpenRegister permissions:

- Resolve the object via `ObjectService` in the caller's RBAC scope
  (`_rbac: true`, i.e. NOT the `_rbac: false` system scope `FlowAgentRunService`
  uses post-authorization). A caller who cannot read the object gets a 404 —
  fail-closed and indistinguishable from "does not exist", matching procest's
  `loadReadableCase()` 404-not-403 convention and Hermiq's IDOR posture
  (hydra-gate-no-admin-idor). This is the per-object guard that keeps
  `#[NoAdminRequired]` safe.
- The agent id is resolved and its ownership/enabled state validated exactly as
  `FlowAgentRunService::resolveAgent()` does.
- The dispatched run then executes under the SAME governance as any other
  agent-run occurrence — the endpoint authorizes the REQUEST; the governed job
  authorizes the RUN.

The endpoint is fire-and-forget: it dispatches with `mode: "async"` and returns
202 Accepted with the correlation id. The result lands on the object's
`resultField` and as an OR audit entry, which the render leaf's run-history
reads — no synchronous run mode is introduced (v1 supports async only).

## Decision 3 — Invocation via AgentRunRequestedEvent (reuse, not rebuild)

The endpoint builds an `AgentRunRequestedEvent` (OpenRegister's typed event,
already imported by Hermiq) and dispatches it via `IEventDispatcher`. It fills:
`subjectUuid/subjectRegister/subjectSchema` from the resolved object; `agent`
from the `{id}` path segment; `skill`/`prompt` from the body (optional);
`resultField` from the body or a schema/config default; `requiresApproval` from
the agent's own policy (NOT caller-supplied — a caller must not be able to
downgrade an approval requirement); `mode: "async"`; a `flowName` marker such
as `"run-on-object"`; and a fresh `correlationId` returned to the caller. The
existing `AgentRunRequestedListener` picks it up and the existing job/service
chain runs it. No new run code path exists to misgovern.

## Decision 4 — Declarative context allowlist `x-openregister-agent-context`

procest's `buildCaseSummary()` hardcodes nine safe fields and truncates
`description`. That decision belongs on the SCHEMA, not in per-app PHP. This
change specifies a `x-openregister-agent-context` keyword on a schema: a list
of property names the object may expose to an agent surface, plus optional
per-field caps. The endpoint and the render leaf build the forwarded context
from ONLY those named properties. The rule is FAIL-CLOSED:

- keyword absent or empty list → empty context (never the whole object);
- a listed property missing on the instance → omitted, not an error;
- fields never listed (documents, contacts, initiator PII) are never sent.

This removes the per-app context builder and moves the safety decision to a
reviewable, declarative place beside `x-openregister-flows`. The chat surface
sends this bounded context as `context.contextData` to `converse` (the exact
shape procest already sends); the run-on-object endpoint uses the same bounded
context to render the prompt.

## Decision 5 — Manifest action: interim `api-call` vs end-state `agent`

The end-state is a new discriminated `type: "agent"` action in
`app-manifest-v2.schema.json` (a sibling to `api-call` / `object-op` in the
`oneOf` action discriminator) plus an `agent` case in `actionsDispatcher`,
carrying `agent` (id), optional `skill`, optional `resultField`, `confirm`
gating, and toast/refresh semantics — and understanding that the call is
async (it fires the run and surfaces the queued/running state rather than a
synchronous result). This is authored in a COMPANION nextcloud-vue change; this
change fixes the contract it must satisfy (POST target, token interpolation of
`@objectId` / `@object.<field>`, async 202 semantics, confirm intent).

Interim: `type: "api-call"` already covers the endpoint. `app-manifest-v2`'s
`api-call` action supports a token-interpolated `url`
(`/index.php/apps/hermiq/api/agents/@object.agentId/run-on-object` or a fixed
id), a JSON `params` body carrying `register`/`schema`/`objectId` (via
`@objectId`), success/error toasts, confirm gating, and page refresh. So an
OpenBuild app can wire "run this agent" TODAY with a plain `api-call` action —
no nc-vue change required to ship. Recommendation: ship on `api-call`; migrate
to `type: "agent"` when the companion change lands, for the agent-aware
affordances (agent/skill pickers, run-status surfacing) `api-call` cannot
express. Both target the same endpoint, so the migration is manifest-only.

## Cross-repo dependencies

- **openregister `app-leaf-provider-registration`** (hard dependency). Supplies
  the sibling-app leaf-registration hook this leaf plugs into: a server-side
  `RegisterLeafProviders` collect-event and the JS integration-registry render
  contract (`registerIntegration()` descriptor: `id, label, icon, tab, widget,
  order, group, requiresPermission`). This change is its first consumer and is
  specified against that intent; it does not define that hook.
- **nextcloud-vue `type: "agent"` action-type** (companion, soft). The schema +
  `actionsDispatcher` case for the end-state action. Contract only here; the
  interim `api-call` path removes it from the critical path.
- **openregister `x-openregister-agent-context`** (co-located). The schema
  keyword is validated/consumed by OR's object read path; this change specifies
  the keyword's semantics and Hermiq's fail-closed consumption of it. If OR
  owns keyword validation, a follow-up OR delta registers it in the schema
  meta-schema; Hermiq's consumption is fail-closed regardless.
- **or#2067 / or#2068 / or#2070 / or#2071 / hermiq#35** (coordinated, no
  overlap). The flow-engine runtime, trigger set, execution tooling, MCP
  surface, and GraphExecutor retirement. The run invocation here rides the
  existing `AgentRunRequestedEvent` recipe untouched; when hermiq#35 moves the
  executor onto the OR flow engine, this endpoint keeps dispatching the same
  typed event and inherits the new runtime for free.

## Risks / Trade-offs

- **Async-only UX.** The run-on-object endpoint returns 202, not a result. The
  render leaf must poll/refresh run history for the outcome. Acceptable: it
  matches the sanctioned `mode: "async"` contract and keeps governance
  (kill-switch/budget/approval) meaningful. A synchronous mode is explicitly a
  non-goal.
- **Context allowlist adoption.** Schemas without `x-openregister-agent-context`
  expose an empty context — the chat still works but is ungrounded. This is the
  safe default; per-schema opt-in is a one-line schema edit.
- **Leaf load-order.** Mitigated by the `registerIntegration()` queue-stub shim
  (nc-vue registry) — a leaf that loads before OpenRegister's bundle enqueues
  and is replayed.
- **Approval downgrade.** `requiresApproval` is taken from the agent policy, not
  the request body, so a caller cannot start an unapproved run of an agent that
  requires approval.
