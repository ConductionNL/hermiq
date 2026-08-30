---
kind: code
---

## Why

`tool-scope-security-default` made an unconfigured agent tool-less, deliberately and
with no override. That is the right default and it left a hole at the other end: **an
agent cannot discover what it is missing, and has no way to ask.**

Concretely, the case that prompted this. A user says *"address this subsidiebesluit to
a client"*. There is a client MCP in pipelinq that would answer it. Today the agent:

- cannot see that tool — `searchTools` is backed by `ToolSearchService`, which holds
  **this run's resolved, grant-filtered, default-denied descriptor set** (verified in
  its own docblock). It can only find what the agent already has;
- cannot ask for it — there is no request path;
- so it answers "I don't have a tool for that", which is true, unhelpful, and
  indistinguishable from "no such tool exists anywhere".

The operator's only recourse is to guess which grant string to add. That is exactly
the pressure that produces a wildcard grant, which undoes the default-deny the earlier
change installed. **A default-deny without a request path decays into over-granting.**

## What Changes

- **A discovery tool that sees past the agent's own grants.** Lists tools across the
  instance as METADATA ONLY — id, description, owning app — and is never a route to
  invoking them.
- **An access-request tool.** The agent names a tool and states why it needs it. This
  creates a request. It does not create a grant.
- **The owning user decides.** A request notifies the agent's owner, who alone may
  grant it. Granting emits an alert and a notification.
- **A grant is an event, not a silent state change.** The owner is told what was
  granted, to which agent, and on whose request.

## The line this change must not cross

**A request is not a grant, and the agent must never be able to close that gap.** The
agent authors the request, including its justification; a human authorises it. Every
requirement here exists to keep those two acts separate.

## ⚠️ The justification is model-authored text aimed at a human decision

The agent writes the sentence the owner reads before clicking grant. That is an
influence channel pointing at the person holding the permission, and it is the part of
this design that is genuinely new risk rather than new convenience.

It is worth stating plainly because the mitigation is not "trust the model": the
approval surface must show what the agent ASKED FOR (a tool id, its owning app, its
declared write-or-read reach) with the same weight as why it says it needs it, so the
decision can be made from the facts rather than from the argument.

## Capabilities

### New Capabilities
- `tool-access-requests`: how an agent discovers tools it does not hold, how it asks
  for them, and who may say yes.

## Impact

- **Code**: hermiq — the two meta-tools, the request record, the approval surface, the
  notification.
- **Interaction**: `agent-tool-governance` — grants remain the enforcement point; this
  adds a governed path for changing them, and changes nothing about how a resolved
  grant is applied.
- **Not in scope**: automatic granting under any condition, and any weakening of
  `tool-scope-security-default`'s "no configured tools means no tools".
