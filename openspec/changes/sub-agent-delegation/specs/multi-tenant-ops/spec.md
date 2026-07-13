# multi-tenant-ops (delta)

Extends the existing budget hard-cap and tenant-isolation requirements so a delegated sub-agent run
(`sub-agent-delegation`) is governed identically to a scheduled or flow-triggered run: it counts
against the SAME organisation's/agent's budget, is blocked by the SAME hard cap, and never crosses a
tenant boundary.

## MODIFIED Requirements

### Requirement: Per-scope budget guardrails — soft threshold and hard cap
The system MUST allow an organisation admin to configure a `Budget` (scoped to an organisation or
to one agent, with a token limit and/or a EUR limit per a configurable period) and MUST enforce
two thresholds against that budget's current-period actual usage: a soft threshold that notifies
the organisation owner without blocking runs, and a hard cap that blocks NEW runs for that
budget's scope. The hard cap MUST NOT abort a run already in progress. Current-period usage MUST
be computed from the organisation's/agent's own `action='run'` AuditTrail entries — the same
source `run-analytics` aggregates — never from a separately maintained counter. A delegated
sub-agent run (`sub-agent-delegation`) MUST be gated by, and its usage MUST count toward, the SAME
budget the top-level run that ultimately triggered the delegation counts against — never a separate,
unaccounted budget scope.
<!-- Previous behavior: the hard cap/soft threshold applied to scheduled and flow/webhook-triggered
     runs only; delegated sub-agent runs did not exist. -->

#### Scenario: A soft threshold is crossed
- **GIVEN** a `Budget` for agent X with a token limit of 100,000 and an 80% soft threshold
- **WHEN** agent X's current-period recorded usage crosses 80,000 tokens
- **THEN** the system MUST send one notification to the organisation owner via the existing
  Talk/Notification delivery dialect
- **AND** agent X's schedules MUST continue to run normally

#### Scenario: A hard cap blocks a new run but never an in-flight one
- **GIVEN** a `Budget` for agent X with a token limit of 100,000, already at or above 100,000
  recorded tokens for the current period
- **WHEN** a schedule for agent X becomes due
- **THEN** the system MUST skip the run at the dispatch gate (recorded with a distinct
  budget-exhausted status) and MUST NOT invoke the agent
- **AND** any run of agent X already executing at the moment the cap is reached MUST be allowed
  to finish uninterrupted

#### Scenario: An organisation-scoped budget blocks all of that organisation's schedules
- **GIVEN** organisation A has an organisation-scoped `Budget` at its hard cap
- **WHEN** any schedule belonging to organisation A becomes due
- **THEN** the system MUST skip every one of organisation A's due schedules at the gate
- **AND** schedules belonging to any other organisation MUST be unaffected

#### Scenario: Budget enforcement applies identically to a webhook/event-triggered run
- **GIVEN** an agent whose organisation-scoped `Budget` is at its hard cap
- **WHEN** a webhook or platform event triggers a run for that agent (the `flow-agent-listener`
  path)
- **THEN** the system MUST apply the same hard-cap block as a scheduled tick would
- **AND** the block MUST be recorded via the same gate-skip audit convention

#### Scenario: A delegated sub-agent run counts against the parent's triggering budget
- **GIVEN** a scheduled run for agent A (organisation X) is in progress and organisation X has an
  organisation-scoped `Budget` with 90,000 of a 100,000 token limit already used this period
- **WHEN** agent A delegates a task to an allowed agent B, whose own sub-run consumes 15,000 tokens
- **THEN** the system MUST record agent B's sub-run usage so that organisation X's budget status
  reflects at least 105,000 used tokens for the period (over the hard cap)
- **AND** a subsequent NEW top-level run for organisation X MUST be blocked by that same hard cap

#### Scenario: A delegation is refused when the triggering budget is already exhausted
- **GIVEN** organisation X's budget is already at its hard cap
- **WHEN** an already-running agent in organisation X (started before the cap was reached) attempts
  to delegate a task via `hermiq.delegateAgent`
- **THEN** the system MUST refuse the delegation before invoking the target agent
- **AND** the already-running parent turn MUST be allowed to finish uninterrupted

### Requirement: Strict per-tenant isolation across all object types
The system MUST ensure every object type Hermiq introduces (agents, schedules, memory, skills,
approvals, runs, budgets) carries `organisation`/`owner`/`groups` fields, and MUST NOT include
another tenant's objects in any API response. Delegation MUST NOT be usable to reach across a
tenant boundary: an agent MUST NOT be able to delegate to a target agent belonging to a different
organisation, regardless of any configured `delegationAllowlist` entry.

#### Scenario: A cross-tenant list request is made
- **GIVEN** organisations A and B each have their own agents, schedules, memory, and budget
  objects
- **WHEN** a user in organisation A requests a list of any Hermiq object type, including budgets
- **THEN** the system MUST return only objects belonging to organisation A
- **AND** objects belonging to organisation B MUST NOT appear in the response

#### Scenario: An agent's allowlist names a target in a different organisation
- **GIVEN** agent A belongs to organisation A and its `delegationAllowlist` names agent B, which
  belongs to organisation B
- **WHEN** agent A attempts to delegate to agent B via `hermiq.delegateAgent`
- **THEN** the system MUST refuse the delegation
- **AND** agent B MUST NOT be invoked, regardless of the allowlist entry
