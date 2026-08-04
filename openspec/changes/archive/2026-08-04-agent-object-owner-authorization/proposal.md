---
kind: config
---

# Close the agent write hole: only the owner may change an agent

## Why

**Any authenticated user can rewrite any agent's tool grants, prompt, model and
schedule.** This is reproduced, not inferred. Against a default instance:

1. create an agent as `admin` with `tools: ["hermiq.readFile"]`
2. `PUT /apps/openregister/api/objects/hermiq/agent/{uuid}` as `hermiq-outsider`
   — a user who does not own it — with `tools: ["hermiq.sendMail"]`
3. response **HTTP 200**; reading the object back shows `"owner":"admin"` and
   `"authorization":[]` unchanged, but `tools` is now the attacker's list

A non-owner granted an agent the ability to send irreversible external email.
The same `PUT` also carries `prompt`, `model`, `requiresApproval` and
`delegationAllowlist`, so the same request can repoint an agent at another model,
rewrite its instructions, or widen who it may delegate to.

Why it happens: `ToolOversightController` *is* guarded, but it is not the path
the app uses. `AgentFormModal.vue` — the primary agent editor — writes through
`store.saveObject('agent', …)` → `PUT /apps/openregister/api/objects/…`, which
never reaches that controller. The Agent schema declares no `authorization`
block, and OpenRegister evaluates an empty block as OPEN for `update`. The
sentence "Only the agent owner can change tool grants" in the grants widget is
help text, not enforcement.

**This blocks the capability-reach work.** `agent-capability-reach` adds a
per-grant waiver of the human approval gate. Shipping a waiver on top of this
hole would let any user waive oversight on any agent — the feature would become
an amplifier for the vulnerability beneath it.

## What changes

One `authorization` block on the `Agent` schema in
`lib/Settings/hermiq_register.json`:

```json
"authorization": { "read": ["authenticated"] }
```

🔴 **This grants read and closes writes BY OMISSION, which is the part that reads
wrong at a glance.** OpenRegister fails closed on an action a non-empty block
does not list: *"a non-empty authorization block that does not list this action
no longer opens the table … an omitted action yields owner-only rows"*
(`MagicRbacHandler`), and on the write path *"if authorization is configured but
the action is not granted, access is denied"* (`PermissionHandler`). Owners and
admins are admitted before that check, so the owner keeps full control.

`scope: private` was considered and rejected — it is a single key covering every
action, admitting only owner and admins, so it would have closed the hole and
broken agent sharing in the same commit. Hermiq shares agents through its own
`invitedUsers` / `groups` fields, checked in PHP, and never projects them into
OpenRegister object grants.

## Verified

Four-way live check on the running instance, before and after:

| check | before | after |
|---|---|---|
| non-owner UPDATE | HTTP 200 — the bug | **HTTP 403** |
| non-owner READ | 200 | **200** — sharing intact |
| owner UPDATE | 200 | **200** — not broken |
| grants after the attack | attacker's `sendMail` landed | **unchanged** |

The middle two rows are the point. A fix that only proved "non-owner denied"
could not tell a working fix from one that broke reads for everybody.

## Capabilities

Modified: `agent-management-ui`

## Impact

- No API change, no new endpoint, no frontend change.
- Existing agents are unaffected in normal use: owners keep full control and
  invited users keep read.
- Anything that today edits an agent it does not own starts receiving 403. That
  is the intended effect, and it is the one behaviour to watch on rollout.

## Rollback

Remove the `authorization` block and re-import the register. The hole reopens,
so rollback is only appropriate if the block is found to deny a legitimate
owner — which the owner-UPDATE row above is the control for.

## Out of scope

- The fleet-wide question of whether OpenRegister should treat an empty block as
  closed (`enforce_default_closed` defaults `false`). Raised separately; every
  apps-extra schema shipping an empty block has the same shape.
- Hermiq's other schemas. This change fixes the schema with the demonstrated
  privileged write; a sweep of the rest belongs with the fleet-wide audit.
