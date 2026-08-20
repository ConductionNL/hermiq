# sub-agent-delegation Specification

## Purpose
TBD - created by archiving change sub-agent-delegation. Update Purpose after archive.
## Requirements
### Requirement: Delegation is refused by default until explicitly allowlisted
The system MUST refuse a delegation attempt from an agent whose `delegationAllowlist` does not
explicitly name the requested target agent, and an agent's `delegationAllowlist` MUST default to
empty (may delegate to no one) on every newly created agent.

#### Scenario: An agent with no configured allowlist attempts to delegate
- **GIVEN** an agent whose `delegationAllowlist` is empty (the default)
- **WHEN** that agent's turn calls the `hermiq.delegateAgent` tool with any `targetAgentId`
- **THEN** the system MUST refuse the delegation with a `delegation_not_allowed` error
- **AND** the system MUST NOT invoke the target agent

#### Scenario: An agent delegates to a target explicitly named in its allowlist
- **GIVEN** an agent whose `delegationAllowlist` contains target agent B's UUID
- **WHEN** that agent's turn calls `hermiq.delegateAgent` with `targetAgentId` = B
- **THEN** the system MUST proceed to the remaining delegation gates (depth, fan-out, organisation,
  model-policy, kill-switch, budget, approval) rather than refusing on the allowlist check

### Requirement: Self-delegation and delegation cycles are refused
The system MUST refuse a delegation whose target agent is the calling agent itself, and MUST refuse a
delegation whose target agent already appears in the current call's ancestor chain (the sequence of
agents that led to this delegation call), regardless of what any agent's `delegationAllowlist` permits.

#### Scenario: An agent attempts to delegate to itself
- **GIVEN** an agent A running a turn
- **WHEN** agent A's turn calls `hermiq.delegateAgent` with `targetAgentId` = A's own id
- **THEN** the system MUST refuse with a `delegation_self` error, even if A's own id somehow appears
  in its own `delegationAllowlist`

#### Scenario: A delegation chain would form a cycle
- **GIVEN** agent A has delegated to agent B (B is now running, with A in its ancestor chain), and B's
  `delegationAllowlist` names agent A
- **WHEN** agent B's turn calls `hermiq.delegateAgent` with `targetAgentId` = A
- **THEN** the system MUST refuse with a `delegation_cycle` error
- **AND** the refusal MUST NOT depend on whether the maximum delegation depth has been reached yet

### Requirement: Delegation depth and fan-out are bounded
The system MUST enforce a configurable, low-default maximum delegation depth and a configurable,
low-default maximum fan-out (number of delegate calls) per agent turn, tracked from server-side call
state — never from the delegating agent's tool-call arguments — and MUST refuse, with a distinct
audited error, any delegation that would exceed either limit.

#### Scenario: A delegation would exceed the configured maximum depth
- **GIVEN** the instance-wide maximum delegation depth is configured to 2, and agent A (a top-level,
  depth-1 run) has already delegated to agent B (now running at depth 2)
- **WHEN** agent B's turn calls `hermiq.delegateAgent` targeting an allowed agent C
- **THEN** the system MUST refuse with a `delegation_depth_exceeded` error
- **AND** the system MUST NOT invoke agent C

#### Scenario: A single turn exceeds the configured maximum fan-out
- **GIVEN** the instance-wide maximum fan-out is configured to 3, and agent A's current turn has
  already made 3 successful delegate calls
- **WHEN** agent A's turn calls `hermiq.delegateAgent` a fourth time, targeting any allowed agent
- **THEN** the system MUST refuse with a `delegation_fanout_exceeded` error
- **AND** a delegation call that was itself refused by an earlier gate MUST NOT count toward this limit

### Requirement: Delegation is scoped to the same organisation
The system MUST refuse a delegation whose target agent belongs to a different organisation than the
calling agent, unconditionally — no `delegationAllowlist` entry or configuration can permit
cross-organisation delegation.

#### Scenario: An allowlisted target belongs to a different organisation
- **GIVEN** agent A (organisation X) has target agent B (organisation Y) in its `delegationAllowlist`
- **WHEN** agent A's turn calls `hermiq.delegateAgent` with `targetAgentId` = B
- **THEN** the system MUST refuse with a `delegation_cross_organisation` error
- **AND** the system MUST NOT invoke agent B, regardless of the allowlist entry

### Requirement: Delegated runs inherit the parent's acting-user attribution
The system MUST run a delegated sub-agent as the identity the PARENT run is already impersonating, and
MUST NOT apply the target agent's own `actingUser` override for a delegated invocation, so that
attribution cannot be laundered from the acting human/service identity to a different one via
delegation.

#### Scenario: The target agent has its own actingUser configured
- **GIVEN** agent A is running as impersonated user U (its own resolved acting user), and target agent
  B has `actingUser` set to a different user, V
- **WHEN** agent A delegates to agent B via `hermiq.delegateAgent`
- **THEN** the system MUST run agent B's sub-turn as user U (the parent's acting user)
- **AND** the system MUST NOT impersonate V for this delegated run
- **AND** the resulting audit entry MUST record U, not V, as the run's acting user

### Requirement: A delegated sub-agent runs in an isolated conversation
The system MUST run a delegated sub-agent in a fresh conversation containing only the delegated task,
and MUST NOT give the sub-agent visibility into the parent's conversation history, and MUST return
only the sub-agent's final text result to the parent's tool loop.

#### Scenario: A parent agent delegates a task
- **GIVEN** a parent agent's conversation containing several prior turns of unrelated context
- **WHEN** the parent delegates a task to a sub-agent via `hermiq.delegateAgent`
- **THEN** the system MUST start the sub-agent's run in a new conversation containing only the
  delegated task, not the parent's prior turns
- **AND** the parent's tool loop MUST receive only the sub-agent's final text result, not the
  sub-agent's own intermediate tool calls or reasoning

### Requirement: Delegation is refused when gated by kill-switch, budget, or a target requiring approval
The system MUST refuse a delegation whose organisation's kill-switch is engaged, MUST refuse a
delegation whose organisation's or target agent's budget has reached its hard cap, and MUST refuse a
delegation whose target agent is configured to require human approval — applying the SAME kill-switch
and budget checks a scheduled or flow-triggered run already applies, before ever invoking the target
agent.

#### Scenario: The organisation's kill-switch is engaged
- **GIVEN** organisation X's kill-switch is engaged
- **WHEN** an agent in organisation X calls `hermiq.delegateAgent`
- **THEN** the system MUST refuse with a `delegation_killswitch` error
- **AND** the system MUST NOT invoke the target agent

#### Scenario: The organisation's or target agent's budget is at its hard cap
- **GIVEN** organisation X's budget (or the target agent's own budget) has reached its hard cap for the
  current period
- **WHEN** an agent in organisation X calls `hermiq.delegateAgent` targeting an allowed agent
- **THEN** the system MUST refuse with a `delegation_budget_exhausted` error
- **AND** the system MUST NOT invoke the target agent

#### Scenario: The target agent requires human approval
- **GIVEN** target agent B has `requiresApproval` set to true
- **WHEN** any allowed agent calls `hermiq.delegateAgent` targeting B
- **THEN** the system MUST refuse with a `delegation_requires_approval` error
- **AND** the system MUST NOT create a pending Approval or otherwise pause the parent's turn awaiting
  a human decision
- **AND** agent B MUST remain runnable via its own schedule or flow trigger

### Requirement: Delegation is traceable as one auditable tree
The system MUST record every delegation attempt — refused or successful — as a step on the parent
run's trace timeline, and MUST write a distinct `AuditTrail` entry for a successful delegated sub-run
carrying its own run identifier and a reference to the calling run's own run identifier, so the whole
delegation tree can be reconstructed from the audit trail.

#### Scenario: A successful delegation
- **GIVEN** agent A successfully delegates a task to agent B
- **WHEN** agent B's sub-run completes
- **THEN** the system MUST write an `AuditTrail` entry for agent B's run carrying a fresh run
  identifier and a reference to agent A's run's own run identifier
- **AND** the parent run's step timeline MUST include a timed step for the delegate call, with its
  outcome (`ok`/`error`)

#### Scenario: A refused delegation
- **GIVEN** any delegation gate refuses a delegation attempt
- **WHEN** the refusal occurs
- **THEN** the system MUST still record the attempt as a step on the parent run's trace timeline with
  an error outcome
- **AND** the system MUST NOT write a sub-run `AuditTrail` entry for a target that was never invoked

