## ADDED Requirements

### Requirement: Hermiq registers as an alternative ContextAgent provider

The system MUST register an `ISynchronousProvider` for the
`core:contextagent:interaction` task type, backed by Hermiq's governed agent engine.
It registers ALONGSIDE Nextcloud's stock `context_agent` ExApp (an admin selects the
preferred provider per task type) — it MUST NOT disable or replace the stock provider.

#### Scenario: The provider handles the contextagent interaction task type

- **WHEN** `ContextAgentProvider::getTaskTypeId()` is read
- **THEN** it MUST return `core:contextagent:interaction`

### Requirement: An interaction runs one governed turn and returns the ContextAgent shape

For a valid interaction the system MUST bind the `conversation_token` to a Hermiq
`Conversation` (creating one when the token is empty, reusing the token's conversation
when it exists and is owned by the requesting user), run one turn through Hermiq's
engine, and return `output` (the reply), `conversation_token` (the conversation's
UUID), and `actions` (the serving agent's tool allowlist as JSON).

#### Scenario: A first turn creates a conversation and returns its token

- **GIVEN** an authenticated user, an available agent, and an empty `conversation_token`
- **WHEN** the interaction runs
- **THEN** a new `Conversation` MUST be created for that user and agent
- **AND** the returned `conversation_token` MUST be that conversation's UUID
- **AND** `actions` MUST be a JSON document containing the agent's tool allowlist

#### Scenario: No user context is a processing error

- **WHEN** an interaction is attempted with a null user id
- **THEN** it MUST throw a `ProcessingException`

### Requirement: The org kill-switch halts a ContextAgent interaction before the agent runs

The system MUST check the serving agent's organisation against the same
`TenantControl` kill-switch a scheduled run checks
(`ScheduleService::isOrganisationEngaged`) and MUST halt the interaction — without
invoking the engine — when the kill-switch is engaged.

#### Scenario: An engaged kill-switch stops the turn

- **GIVEN** the serving agent's organisation has an engaged kill-switch
- **WHEN** an interaction runs
- **THEN** the engine MUST NOT be invoked
- **AND** the interaction MUST throw a `ProcessingException`

### Requirement: Confirmation maps to an approval-gate decision

The system MUST map a supplied confirmation value onto an approval-gate decision on
the requesting user's pending approval for the serving agent. It MUST approve when the
confirmation is one or greater and MUST deny when the confirmation is zero. When no
matching pending approval exists, the confirmation MUST be a recorded no-op (the
single-turn scope does not create a mid-turn approval; the pause/resume loop is
deferred).

#### Scenario: Confirming resolves a pending approval

- **GIVEN** the user has a pending Approval whose `agentId` is the serving agent
- **WHEN** an interaction runs with `confirmation` = 1
- **THEN** that approval MUST be approved via `ApprovalService`

#### Scenario: Denying rejects a pending approval

- **GIVEN** the user has a pending Approval whose `agentId` is the serving agent
- **WHEN** an interaction runs with `confirmation` = 0
- **THEN** that approval MUST be denied via `ApprovalService`
