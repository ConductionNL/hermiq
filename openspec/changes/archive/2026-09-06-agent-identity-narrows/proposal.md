---
kind: code
depends_on: []
---

## Why

Two paths let something other than the caller decide whose rights an agent turn
executes with. ADR-099 forbids both: identity narrows along an invocation chain,
and widening requires a grant checked against the caller.

**A flow document named the identity.** `HermiqAgentNode` and
`HermiqWorkloadNode` read `$config['owner'] ?? $context['triggeredBy']`. A flow
document is authored by anyone who may edit flows, so `owner: admin` written into
a node made the step execute with admin's rights and be attributed to admin — an
authoring-time privilege escalation, in a field that reads as configuration.
Integriq's `FlowOwner` already names this by hand as "the anti-pattern this class
exists to avoid, not the template", so two apps that call each other held
opposite positions on the same question.

**A declared identity was silently substituted.** `ScheduleService::resolveActingUser()`
fell back to the schedule owner when an agent's `actingUser` named a user that was
missing or disabled.

## What Changes

- **BREAKING — `config['owner']` is removed from both nodes.** A node is a callee
  and acts as whoever invoked it, which reaches it through the run context.
- **BREAKING — a declared-but-unresolvable `actingUser` refuses the run.** This
  supersedes `agent-capability-profile` task 3-1 ("the run is NOT failed by a
  misconfigured profile field"), which valued availability over attribution.

  An agent that declares NO `actingUser` still falls back to the schedule owner.
  That is not an escalation and is unchanged: the agent expressed no preference,
  so the schedule's own identity applies.

## Capabilities

### New Capabilities
- `agent-identity`: whose rights an agent turn executes with, where that identity
  comes from, and what happens when it cannot be resolved.

## Impact

**Code** — `lib/Flow/HermiqAgentNode.php`, `lib/Flow/HermiqWorkloadNode.php`,
`lib/Service/ScheduleService.php`.

**Behaviour** — a flow that set `owner` on an agent or workload node now runs as
its caller instead. An agent whose `actingUser` names a departed user stops
running rather than running as the schedule owner.

**Tests** — twelve `HermiqWorkloadNodeTest` cases supplied the identity as
`config['owner']` with an EMPTY context, so they depended on the escalation path
itself and no test exercised how a node actually receives an identity. They now
pass a real run context, plus a negative control proving a config-supplied owner
cannot override the caller.

**Supersedes** — `agent-capability-profile` task 3-1.

**Follows** — switching the nodes from `context['triggeredBy']` to
`context['runAs']` once ConductionNL/openregister#2835 is deployed. That key now
exists on `development` but reading it before hermiq's target instances have it
would break these nodes.
