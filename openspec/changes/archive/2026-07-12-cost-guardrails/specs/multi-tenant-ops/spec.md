# multi-tenant-ops (delta)

Adds per-organisation and per-agent budget guardrails (token and/or EUR spend caps per period)
as a new tenant-ops control alongside the existing agent/schedule quotas and the kill-switch: a
soft threshold that warns without blocking, and a hard cap enforced at the SAME synchronous gate
point in the dispatch path (`ScheduleService::dispatch()`, `FlowAgentRunService`) that already
enforces the kill-switch and the human-approval gate. Also extends the existing tenant-isolation
requirement's object-type list to cover the new `Budget` object type.

## ADDED Requirements

### Requirement: Per-scope budget guardrails — soft threshold and hard cap
The system MUST allow an organisation admin to configure a `Budget` (scoped to an organisation or
to one agent, with a token limit and/or a EUR limit per a configurable period) and MUST enforce
two thresholds against that budget's current-period actual usage: a soft threshold that notifies
the organisation owner without blocking runs, and a hard cap that blocks NEW runs for that
budget's scope. The hard cap MUST NOT abort a run already in progress. Current-period usage MUST
be computed from the organisation's/agent's own `action='run'` AuditTrail entries — the same
source `run-analytics` aggregates — never from a separately maintained counter.

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

## MODIFIED Requirements

### Requirement: Strict per-tenant isolation across all object types
The system MUST ensure every object type Hermiq introduces (agents, schedules, memory, skills,
approvals, runs, budgets) carries `organisation`/`owner`/`groups` fields, and MUST NOT include
another tenant's objects in any API response.

#### Scenario: A cross-tenant list request is made
- **GIVEN** organisations A and B each have their own agents, schedules, memory, and budget
  objects
- **WHEN** a user in organisation A requests a list of any Hermiq object type, including budgets
- **THEN** the system MUST return only objects belonging to organisation A
- **AND** objects belonging to organisation B MUST NOT appear in the response
