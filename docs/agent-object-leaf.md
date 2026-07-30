---
title: Agent object leaf
sidebar_position: 6
description: Surface and trigger Hermiq agents on any OpenRegister object in any OpenBuild app — the reusable agent render leaf, the object-scoped run-on-object endpoint, the declarative context allowlist, and the manifest action recipe.
keywords:
  - Hermiq
  - OpenRegister
  - Leaf
  - Agent
  - ADR-019
  - ADR-041
  - ADR-066
---

# Agent object leaf

Hermiq registers a reusable **OpenRegister integration leaf** (`hermiq-agent`) so
any OpenBuild manifest app that stores objects in OpenRegister can surface — and
trigger — Hermiq agents on those objects **without hand-rolling its own AI
plumbing**. This generalises the six-file case-assistant procest hand-wrote into
one leaf every app gets for free.

The leaf is **render-and-read only** (ADR-066): the tab chats through the
tool-free `converse` endpoint and the widget reads OpenRegister's audit trail;
the single write is a POST to the governed run-on-object endpoint. Starting a run
stays an ADR-041 typed command — the leaf never runs anything itself.

## What the leaf gives an object

- An **Agent chat tab** (`CnAgentChatTab`) that reuses `POST /api/assistant/converse`
  (the tool-free surface) and forwards **only** the bounded object context.
- An **Agent runs widget** (`CnAgentRunsWidget`) showing this object's run history
  and status (ok / error / skipped_killswitch / skipped_budget / awaiting_approval),
  plus a **Run agent** button that dispatches a governed run.

The surface is gated on Hermiq being installed (`requiredApp: 'hermiq'`) — on an
instance without Hermiq the tab is hidden, never a broken tab.

### Render surfaces

Both halves of the registration declare the SAME surface set, EXPLICITLY:

| Half | Where |
|------|-------|
| PHP `LeafDescriptor` | `RegisterAgentLeafListener::SURFACES` |
| JS `registerIntegration()` | the `SURFACES` const in `src/integration-leaf.js` |

```
['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity']
```

Every member is drawn from OpenRegister's `LeafDescriptor::VALID_SURFACES`. The
dashboard surfaces are included because the leaf ships a `widget` with a default
grid size (`{ w: 4, h: 4 }`) and consuming apps place that widget on dashboards.

Neither half may declare its surfaces by OMISSION. That is not style: the JS half
previously declared no `surfaces` key at all while shipping a dashboard-sized
widget, and the PHP half said the leaf was not dashboard-placeable — so the two
disagreed, dashboard-first consumers could not place the widget, and there was
nothing for a parity check to compare. `tests/Unit/Listener/LeafSurfaceParityTest.php`
now compares them.

### When the object contributes no context

When the schema's allowlist resolves to ZERO properties, the chat tab says so in
text (`[data-testid="cn-agent-chat-tab-no-context"]`) and marks each reply
produced in that state as not grounded in the object. Fail-closed context is
correct security; an ungrounded answer presented as grounded is a correctness
defect, and the two must be distinguishable in the surface.

## The run-on-object endpoint

```
POST /index.php/apps/hermiq/api/agents/{id}/run-on-object
```

- `#[NoAdminRequired]` — **object-permission-scoped, not admin-gated.** The object
  is resolved in the caller's RBAC scope; a caller who cannot read it gets a
  **404** (fail-closed, indistinguishable from nonexistent).
- Body: `register`, `schema`, `objectId` (required); `resultField`, `skill`,
  `prompt` (optional). Missing a required field → **400**.
- On success it dispatches the **same governed `AgentRunRequestedEvent` recipe**
  every other trigger uses (`mode: "async"`, `flowName: "run-on-object"`, a fresh
  correlation id) and returns **202** with the correlation id. It never calls the
  run service directly, so kill-switch / budget / human-approval / redacted-audit
  all still apply.
- `requiresApproval` is derived from the **agent's own policy**, never the request
  body — a caller cannot downgrade an approval requirement.

The run is asynchronous: the result lands on the object's `resultField` and as an
OpenRegister audit entry, which the widget's run history reads. There is no
synchronous run mode in v1.

## Declarative context allowlist — `x-openregister-agent-context`

A schema declares which of its properties an agent surface may read, **beside**
`x-openregister-flows`, in its configuration:

```json
{
  "title": "Case",
  "x-openregister-agent-context": ["title", "identifier", "status", "caseType"]
}
```

Per-field caps (multibyte-safe truncation) use the map form:

```json
{
  "x-openregister-agent-context": {
    "title": {},
    "status": {},
    "description": { "maxLength": 500 }
  }
}
```

The rule is **fail-closed** (`AgentContextBuilder`, mirrored in JS by
`buildAgentContext`):

- keyword **absent or empty** → **empty** context (never the whole object);
- a listed property **missing** on the instance → omitted, not an error;
- a property **never listed** (documents, contacts, initiator PII) → never sent.

> Keyword validation is owned by OpenRegister's schema meta-schema (a follow-up OR
> delta may register it formally). Hermiq's consumption is fail-closed regardless,
> so an unregistered keyword is still safe.

## Wiring "Run agent" from a manifest — interim `api-call`

You can wire a run **today**, with **no nextcloud-vue change**, using the existing
`type: "api-call"` action. Point it at the endpoint and token-interpolate the
body from the object:

```json
{
  "type": "api-call",
  "label": "Run agent",
  "icon": "RobotOutline",
  "confirm": {
    "title": "Run agent on this object?",
    "message": "This dispatches a governed agent run. The result appears asynchronously."
  },
  "url": "/index.php/apps/hermiq/api/agents/00000000-0000-0000-0000-000000000001/run-on-object",
  "method": "POST",
  "params": {
    "register": "@register",
    "schema": "@schema",
    "objectId": "@objectId"
  },
  "onSuccess": { "toast": "Run queued", "refresh": true },
  "onError": { "toast": "Could not start the run" }
}
```

The call is **asynchronous** — it returns 202 and surfaces the queued state; the
outcome shows up in the Agent runs widget once the governed job finishes.

## End-state `type: "agent"` action (companion nextcloud-vue change)

The end-state is a discriminated `type: "agent"` manifest action — a sibling to
`api-call` / `object-op` in `app-manifest-v2.schema.json` + `actionsDispatcher` —
carrying `agent` (id), optional `skill`, optional `resultField`, `confirm`
gating, and toast/refresh semantics. It **MUST target the same endpoint**
(`/api/agents/{id}/run-on-object`) and **MUST treat the call as async** (surface
the queued/running state, not a synchronous result), so migrating from the
interim `api-call` is manifest-only. Those nextcloud-vue schema/dispatcher files
are authored in a **separate change**; this change only fixes the contract they
must satisfy.

## Argument-scoped tool grants

`Agent.tools` stays a `string[]` (ADR-035 Decision 4). An entry may now narrow an
exact tool id by constraining the ARGUMENTS it may be invoked with:

```
openregister.runFlow?flowId=00000000-0000-0000-0000-000000000000
openregister.runFlow?flowId=00000000-0000-0000-0000-000000000000&label=in:needs-input,retry:queued
```

- `?` opens the constraint list, `&` separates constraints.
- `key=value` PINS an argument to one literal value.
- `key=in:a,b,c` declares a CLOSED set of permitted values.
- Values containing `,` or `&` are percent-encoded.

**Why it exists.** Some tools pick their target from an argument rather than from
their id. OpenRegister's `openregister.runFlow` runs ANY flow on the instance from
a `flowId` argument, so before this form the only way to let an agent run one flow
was to let it run all of them.

**Semantics you can rely on:**

- An argument-scoped grant resolves to the SAME catalog tool id as the bare
  exact-id grant. No second catalog entry, no rewritten descriptor.
- Constraints are enforced BEFORE dispatch, at `FacadeToolInvoker` — the one
  chokepoint that already holds the guardrail, approval-gate and dry-run
  short-circuits. A non-conforming call never reaches the facade.
- A pinned argument must match exactly; a constrained argument must be a member of
  the set; an argument the grant does not mention is left to the tool's own
  validation. An argument the grant DOES mention but the call omits is a violation.
- Two grants over the same tool are ALTERNATIVES whose arguments stay paired:
  `?flowId=A&label=x` plus `?flowId=B&label=y` permits (A,x) and (B,y), not (A,y).
- A bare exact-id grant beside a constrained one stays unconstrained — it means
  every target, and a sibling grant does not narrow it.
- Narrowing NEVER downgrades classification. A write/destructive tool stays
  write/destructive for default-deny, dry-run and approval.
- A constrained WILDCARD (`app.schema.*?x=y`) resolves to NOTHING — fail closed,
  reported by `ToolGrantResolver::resolvesToNothing()`.

**Refusal shape** (structured, never thrown):

```json
{
  "ok": false,
  "error": "grant_constraint_violated",
  "argument": "label",
  "message": "Argument 'label' is not permitted by this agent's grant."
}
```

The refusal's trace step records the tool, the offending argument and the
constraint it violated.

**Every pre-existing grant form is unchanged.** A grant string with no `?` is
split, expanded and classified byte-for-byte as before, so no stored `Agent.tools`
value needs rewriting.

## Flow-run owner attribution

A tool that QUEUES A FLOW RUN (`openregister.runFlow`) is refused outright when the
run has no resolvable owning Nextcloud UID — `{"error": "owner_unresolved"}` — and
carries the owner into the call when it does. A flow's terminal step may command an
external system, so an unattributed run of one is an unattributed command;
refusing is deliberately chosen over defaulting to an empty or system owner.

The owner is resolved, most authoritative first: the acting session user, then the
agent record's `actingUser`, then the agent object's owner.

> **Upstream gap.** `FlowMcpToolProvider::runFlow()` calls
> `FlowRunService::queue()` without a `$user`, even though that method already
> accepts one (ConductionNL/openregister#2158). Hermiq injects the resolved owner
> as a `triggeredBy` argument so attribution lands the moment that gap closes;
> until then the REFUSAL half is what holds the line.

## References

- ADR-019 — integration registry render/link.
- ADR-041 — cross-app commands via typed `*RequestedEvent`.
- ADR-066 — cross-app leaf registration (render-and-read boundary).
- OpenRegister `app-leaf-provider-registration` — the leaf-registration hook this
  leaf plugs into (openregister#2108).
