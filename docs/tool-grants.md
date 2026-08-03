---
title: Tool grants
sidebar_position: 12
description: The complete grant model for Hermiq agents — every grant form, the reach axis that measures blast radius independently of the CRUD verb, the default-deny rule, when the human-approval gate fires, and exactly what the #noapproval waiver gives up.
keywords:
  - Hermiq
  - Tool grants
  - Reach
  - Approval gate
  - Governance
  - ADR-063
---

# Tool grants

What an agent may do is decided by one field: `Agent.tools`, a list of grant
strings. This page is the complete grammar, the two axes a tool is classified on,
and what happens when a grant does not cover a call.

> **If you change nothing after upgrading, two tools change behaviour.**
> `hermiq.webSearch` and `hermiq.webFetch` are no longer available to an agent
> that does not name them. See [What existing agents lose](#what-existing-agents-lose).

---

## The two axes

Every tool carries **two independent classifications**. They answer different
questions, and neither substitutes for the other.

**`scope`** — the data verb: `read`, `create`, `update`, `delete`.
What the call does to data.

**`reach`** — the blast radius: `self` < `user` < `instance` < `external`.
Who can observe or be affected by the call.

The reason for two axes is that the first one lies about risk. Consider two
tools:

| Tool | `scope` | `reach` | Reversible? | Who sees it |
|---|---|---|---|---|
| `hermiq.forgetMemory` | `delete` | `self` | yes | nobody |
| `hermiq.sendMail` | `create` | `external` | **no** | **a stranger** |

Read the `scope` column alone and you would guard the first and wave through the
second. The CRUD verb is a fact about storage, not about consequences.

### The reach vocabulary

**`self`** — the agent's own state. Nobody else can observe it and the agent can
undo it.
*Example: `hermiq.rememberMemory` writes a note only this agent recalls.*

**`user`** — data the acting user could already reach themselves. The agent acts
as them, so nothing crosses a boundary they do not already stand on.
*Example: `hermiq.searchContacts` reads the directory. Some of those cards were
created by other people — that does not make it `instance` reach, because
reading changes nothing and tells nobody.*

**`instance`** — other users of this Nextcloud can observe the effect.
*Example: `openregister.zaak.update` writes an object colleagues read.*

**`external`** — the effect, or the data, leaves this Nextcloud. Nothing
downstream is recallable.
*Example: `hermiq.webFetch` sends a request to a URL the model chose.*

### An undeclared reach means `external`

A tool whose descriptor declares no `reach`, or declares a value outside that
list, is treated as `external`. It is never assumed to be `self`.

This is deliberate and it has a cost: a tool nobody thought carefully enough
about to annotate is exactly the tool that must not run unsupervised. If you
maintain an app that contributes tools, **an unannotated tool is not neutral —
it is denied by default**. Annotate it.

The one exception is a derived `{app}.{schema}.{verb}` id, where the verb is
enough to infer: `search`/`get` → `user`; `create`/`update`/`delete` →
`instance`. Any other verb falls back to `external`.

---

## Grant forms

`Agent.tools` is a list of strings. Full grammar:

```
{toolId}[?{constraints}][#noapproval]
```

### Exact id

```
hermiq.sendMail
openregister.zaak.create
```

Grants exactly that tool. An explicitly named tool is never default-denied — if
you name it, you meant it.

### Schema wildcard — read verbs only

```
openregister.zaak.*
```

Expands to the `search` and `get` verbs of that schema **that exist in the
catalogue**. It does **not** grant `create`, `update` or `delete`, and no
spelling of `*` alone will.

### Schema wildcard — including writes

```
openregister.zaak.*:write
```

The same expansion plus `create`, `update` and `delete`.

### Argument constraints

A single tool often picks its target from an argument. Constraints let you grant
one specific capability instead of the tool's whole target space.

```
openregister.runFlow?flowId=00000000-0000-0000-0000-000000000000
openregister.runFlow?flowId=in:00000000-0000-0000-0000-000000000000,11111111-1111-1111-1111-111111111111
hermiq.readFile?path=in:/Reports,/Templates
```

- `key=value` **pins** the argument to one literal.
- `key=in:a,b,c` declares a **closed set**.
- Several constraints are joined with `&` and must **all** hold.
- Values are percent-decoded, so a value containing `&` or `,` can be expressed.

**Multiple entries for one tool stay separate alternatives.** Given:

```
openregister.runFlow?flowId=A&label=x
openregister.runFlow?flowId=B&label=y
```

the agent may call `(A, x)` and `(B, y)` — but **not** `(A, y)`. The pairs are
not merged. Merging them would silently widen the grant, which is the exact
mistake this form exists to prevent.

A constrained **wildcard** is refused outright and grants nothing: the
constraints would have to apply to ids only the catalogue knows.

An **unconstrained** grant for a multi-target tool is legal and means every
target.

---

## Default-deny, and when the gate fires

A tool needs to be **named explicitly** when either is true:

1. it is classified write/destructive by its `scope`/`destructiveHint`/`readOnlyHint`, **or**
2. its `reach` is `instance` or higher.

These compose as a **union**. A low reach never makes a tool more permissive than
it already was — `hermiq.forgetMemory` is `self` reach and still requires an
explicit grant, because it is a `delete`.

Tools meeting neither condition are available to an agent with an empty
`Agent.tools` (which means "everything discovered, default-denied").

When an agent's run attempts a tool it was not granted, the call does not
dispatch — it routes to the human-approval gate, and the refusal names the reach
that triggered it so a run trace shows *why*.

Separately, an organisation's guardrail policy can classify a tool `confirm`
(every use needs a human) or `deny` (never). Those are admin-level and are
checked before anything below.

### What existing agents lose

`hermiq.webSearch` and `hermiq.webFetch` both declare `scope: read` and
`readOnlyHint: true` — honestly, they read. Both also send something out of the
instance: a query the model composed, or a URL the model chose.

Under the union rule they are `external` reach, so **an agent relying on the
default catalogue can no longer use them**. They are not removed; they need
naming:

```
hermiq.webSearch
hermiq.webFetch
```

This is the only capability an existing agent can lose in this change.

> **Unattended agents.** An agent run from a schedule or a flow goes through the
> same gate as an interactive one. If it blocks on approval, nobody is watching.
> **An agent that runs unattended must name every tool it needs.**

---

## Waiving approval — `#noapproval`

Append `#noapproval` to a grant entry to suppress the human confirmation for
**that entry**:

```
openregister.runFlow?flowId=00000000-0000-0000-0000-000000000000#noapproval
```

### What it does

It removes the human confirmation step for calls covered by that one grant. That
is the whole of it.

### What it does **not** do

- It **widens nothing.** A waiver on a tool the agent was not granted is inert.
  The waiver is read only after grant expansion has already placed the tool in
  the agent's set.
- It **narrows nothing.** It does not relax an argument constraint. The example
  above waives that one flow; calling a different flow is still refused with
  `grant_constraint_violated`, before the waiver is even consulted.
- It **does not cover a sibling grant.** Given
  `runFlow?flowId=A#noapproval` and `runFlow?flowId=B`, flow B still meets a
  human. The waiver rides on the entry you wrote it on.
- It **does not override an organisation `deny`.** An admin's refusal outranks an
  owner's opt-out.
- It **does not touch OpenRegister RBAC.** Permissions are still enforced at
  invoke time. A waiver cannot obtain access the RBAC layer refuses.

### The cost, stated plainly

You are removing the person who would otherwise notice. For an irreversible,
externally-visible tool, that person is the last thing between a bad model turn
and an effect you cannot recall. Waive narrowly — pair `#noapproval` with an
argument constraint so the autonomy you grant is the specific one you meant, not
the tool's whole surface.

### Who may set it, and what gets recorded

**Only the agent's owner** may persist a grant list. This holds on the Hermiq
tool-grants endpoint and on the generic OpenRegister object write path; neither
is a way around the other.

**Adding or removing a waiver is written as its own audit event**
(`tool-grant-approval-waiver`), recording which entries were added, which were
removed, and who did it. Ordinary grant changes do not write this event, so its
presence always means a waiver actually moved.

Both directions are recorded. Re-enabling approval is the safe change, but a
trail that only logged the dangerous direction could not show that a waiver was
temporary — and "approval was off for two hours" is exactly what an audit needs
to establish.

---

## Special values

**Empty list** (`[]`) — every discovered tool, with default-deny applied. This
is the default and is *not* the same as "no tools".

**`__none__`** — this agent is intentionally tool-less. Spelling it explicitly is
what lets a deliberate no-tools agent be told apart from one whose grants
resolve to zero by accident (a typo, or an id from a stale catalogue). Both end
up with an empty function list; only the second is a defect.

---

## Reading an agent's effective catalogue

`GET /apps/hermiq/api/agents/{agentId}/tool-catalog` returns every catalogue
entry with its `scope`, its `reach`, whether it is `granted`, which grant
produced it, and whether it `requiresExplicitGrant`. That is the fastest way to
answer "why can this agent not do X".
