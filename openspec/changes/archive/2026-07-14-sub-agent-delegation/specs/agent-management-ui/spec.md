# agent-management-ui (delta)

Adds a small control to the agent create/edit form so a user can configure which other agents an
agent may delegate a sub-task to (`sub-agent-delegation`), mirroring the existing tool-allowlist
editor already shipped for the `tools` field.

## ADDED Requirements

### Requirement: Agent detail manages the delegation allowlist in place [MVP]
The system MUST let a user edit an agent's `delegationAllowlist` (which other agents it may delegate
a sub-task to) from the agent create/edit form, presenting the caller's visible agent catalog as
selectable options, and MUST default a newly created agent's `delegationAllowlist` to empty.

#### Scenario: Configure an agent's delegation allowlist
- **GIVEN** the agent create/edit form for agent A, with other agents B and C visible in the
  caller's agent catalog
- **WHEN** the user selects agent B in the delegation-allowlist field and saves
- **THEN** the system MUST persist agent A's `delegationAllowlist` as containing agent B's UUID via
  OpenRegister
- **AND** agent C MUST remain excluded from agent A's `delegationAllowlist` until explicitly added

#### Scenario: A newly created agent cannot delegate until configured
- **GIVEN** the agent create form with the delegation-allowlist field left empty
- **WHEN** the user saves the new agent
- **THEN** the system MUST create the agent with `delegationAllowlist: []`
- **AND** the new agent MUST be unable to delegate to any other agent until an admin explicitly
  adds one

#### Scenario: An agent cannot select itself in its own allowlist
- **GIVEN** the agent edit form for agent A
- **WHEN** the user opens the delegation-allowlist field
- **THEN** the system MUST NOT offer agent A itself as a selectable option
