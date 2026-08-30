# hermiq-domain-mcp-tools (delta)

Extends `HermiqToolProvider` (nc-native-tools' provider class) with MCP tools for Hermiq's
own domain objects — Agent, Schedule/Run, Approval, Skill — so the in-app AI chat companion
(ADR-034/035) can answer questions about the surfaces the user is actually looking at,
instead of only Nextcloud-native leaf capabilities.

## ADDED Requirements

### Requirement: The AI companion can list the caller's agents
The system MUST expose an MCP tool (`hermiq.listAgents`) that returns the calling user's
tenant-scoped agents, using the same read path and authorization boundary as the Agent
Catalog page.

#### Scenario: A user asks the companion what agents they have
- **GIVEN** an authenticated user with at least one agent in their organisation
- **WHEN** the AI companion invokes `hermiq.listAgents`
- **THEN** the system MUST return only agents the caller's tenant scope permits
- **AND** MUST NOT return agents belonging to a different organisation

### Requirement: The AI companion can list the caller's pending approvals
The system MUST expose an MCP tool (`hermiq.listPendingApprovals`) that returns the pending
approvals for which the caller is a reviewer, using the identical scoping
`ApprovalController::index()` already enforces.

#### Scenario: A reviewer asks the companion about pending approvals
- **GIVEN** an authenticated user who is the reviewer on one pending approval
- **WHEN** the AI companion invokes `hermiq.listPendingApprovals`
- **THEN** the system MUST return that approval
- **AND** MUST NOT return approvals for which the caller is not the assigned reviewer

### Requirement: The AI companion can trigger an agent run only for schedules the caller owns
The system MUST expose an MCP tool (`hermiq.runAgentNow`) that triggers an immediate run of
a schedule's agent, enforcing the same owner-scoped guard `RunNowController::run()` enforces.
A caller who does not own the schedule MUST receive a structured error, not a triggered run.

#### Scenario: A user asks the companion to run an agent they do not own
- **GIVEN** a schedule owned by a different user
- **WHEN** the AI companion invokes `hermiq.runAgentNow` for that schedule's id
- **THEN** the system MUST NOT trigger a run
- **AND** MUST return a structured error envelope (not throw)

### Requirement: The AI companion can search the tenant's skill catalog
The system MUST expose an MCP tool (`hermiq.searchSkills`) that searches the caller's
tenant-scoped skill catalog via the existing `SkillService` read path.

#### Scenario: A user asks the companion to find a skill
- **GIVEN** an authenticated user whose tenant has at least one active skill
- **WHEN** the AI companion invokes `hermiq.searchSkills` with a query matching that skill
- **THEN** the system MUST return that skill
- **AND** MUST NOT return skills belonging to a different tenant
