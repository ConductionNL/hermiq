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

## References

- ADR-019 — integration registry render/link.
- ADR-041 — cross-app commands via typed `*RequestedEvent`.
- ADR-066 — cross-app leaf registration (render-and-read boundary).
- OpenRegister `app-leaf-provider-registration` — the leaf-registration hook this
  leaf plugs into (openregister#2108).
