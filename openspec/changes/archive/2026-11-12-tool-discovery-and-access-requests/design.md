## Context

Measured in the current code, 2026-08-16:

- `ToolSearchService` docblock: it holds *"this run's resolved (grant-filtered,
  default-denied) descriptor set"* and answers *"was this tool id part of the agent's
  resolved set"*. **Discovery today is bounded by the grant.**
- `ToolRegistryFacade::listTools([])` returns the full instance catalogue — 137 unique
  tools on the dev instance — so the wider list exists and is already reachable
  server-side.
- `ToolGrantResolver::resolve()` returns `[]` for empty grants, with no override. 99
  of 111 agents are in that state.

So the catalogue exists, the enforcement exists, and the bridge between them does not.

## Goals / Non-Goals

**Goals:**
- An agent can find out that a capability exists elsewhere on the instance.
- An agent can ask for it, once, with a reason.
- Exactly one party can say yes, and is told when it happens.

**Non-Goals:**
- Auto-granting on any signal, including "the user seemed to want it".
- Letting discovery become invocation.
- Changing how a resolved grant is enforced.

## Decisions

### D1 — Discovery returns metadata, never a callable handle

`listAvailableTools` returns `{ id, description, app, reach }` and nothing that can be
dispatched. The invocation path stays `ToolLoop` → resolved whitelist → facade, so a
discovered-but-ungranted id fails the same way an invented one does.

Two separate surfaces is the point: if discovery returned anything the dispatcher
would accept, the grant check would be one bug away from being optional.

### D2 — Discovery is scoped to what the REQUESTING USER may see, not to what exists

The catalogue names the apps installed on the instance and what they can do. Returning
it wholesale to any agent leaks the shape of the deployment to whoever can start a
chat.

Scope: tools from apps the acting user can access. **Not** the agent's grants — that
is the bound this change exists to see past — but not "everything on the box" either.

⚠️ This is the requirement most likely to be dropped as a refinement, because the
unscoped version is easier and looks identical in a demo.

### D3 — A request is a record, and only the agent's OWNER may resolve it

The owner is `Agent.owner`. Not admins — an admin can already edit the agent directly
and does not need this path; routing requests to admins would make them a queue of
decisions about agents they did not create.

⚠️ Open question for review: an admin **can** grant by editing `Agent.tools`
directly, so "only the owner may grant" is true of THIS surface, not of the system.
The spec says what this surface does; it does not claim to be the only door.

### D4 — Granting is an event, and the event names the reach

On grant: a notification to the owner, and an alert on the agent, both naming the tool
id, its owning app, and whether it is read or write. A grant that is only visible by
re-reading `Agent.tools` is how an agent's capability drifts away from what its owner
believes it has.

The measured precedent is exact: 89% of agents were receiving the entire catalogue and
nobody could see it from the agent, which is what made the earlier default so hard to
notice.

### D5 — Rate-limit requests, and make a refusal stick

An agent that can ask can ask repeatedly. Unbounded, a persistent model plus a tired
human is an approval mechanism with a known failure mode.

- A pending request for the same (agent, tool) is not duplicated.
- A refused request is not re-raisable for that agent/tool without the owner
  re-opening it.
- Requests per agent per interval are bounded.

### D6 — Show the facts beside the argument

The approval surface presents, with equal weight: the tool id, its owning app, its
read/write reach — and the agent's justification, marked as agent-authored.

The justification is written by the model to persuade the person holding the
permission (see the proposal). A surface that shows only the reason invites a decision
about the sentence rather than about the capability.

## Seed Data (ADR-001)

None. A seeded access request would assert that an agent asked for something it did
not.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The request record | **Declarative** | A schema in `hermiq_register.json`, with a lifecycle (`pending` → `granted`/`refused`) expressed as `x-openregister-lifecycle`. |
| Owner notification on state change | **Declarative** | `x-openregister-notifications` on that lifecycle — this is precisely the declarative surface ADR-031 prefers. |
| Discovery + request meta-tools | **Imperative** | They read the live tool registry and act inside a run; there is no declarative vocabulary for a tool. |
| Applying a grant | **Imperative, existing** | Writes `Agent.tools`; enforcement is unchanged `ToolGrantResolver`. |

## Risks / Trade-offs

**Discovery is a disclosure surface.** Mitigated by D2, and D2 is the requirement to
defend in review.

**The justification is an influence channel** aimed at a human. Mitigated by D6, not
by trusting the text.

**Request fatigue leads to blanket approval.** Mitigated by D5. A user who is asked
often enough will grant a wildcard to stop being asked — the same pressure that
produced the legacy default this programme removed.

**A granted tool is granted until revoked.** This change adds no expiry. Worth
considering, deliberately out of scope here, and the reason is honest: a
time-boxed grant needs a renewal path, and a renewal path is another notification
stream.
