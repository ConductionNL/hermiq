# hermiq-domain-mcp-tools Specification

## Purpose
Hermiq's own objects are reachable as MCP tools, so an agent or the in-app companion can answer about agents, approvals, runs and skills instead of only generic registers. Each tool (`hermiq.listAgents`, `hermiq.getAgentStatus`, `hermiq.listPendingApprovals`, `hermiq.searchSkills`) uses the same read path and guard as its HTTP counterpart, so no tool grants more than the page does. No tool starts an agent run (`hermiq-mcp-adoption`, 2026-11-25). Created by archiving change `hermiq-domain-mcp-tools` (2026-11-24).
## Requirements
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

### Requirement: The AI companion can search the tenant's skill catalog
The system MUST expose an MCP tool (`hermiq.searchSkills`) that searches the caller's
tenant-scoped skill catalog via the existing `SkillService` read path.

#### Scenario: A user asks the companion to find a skill
- **GIVEN** an authenticated user whose tenant has at least one active skill
- **WHEN** the AI companion invokes `hermiq.searchSkills` with a query matching that skill
- **THEN** the system MUST return that skill
- **AND** MUST NOT return skills belonging to a different tenant

### Requirement: No domain tool starts an agent run
The system MUST NOT expose an MCP tool that starts an agent run. The domain tools over Hermiq's own objects (`hermiq.listAgents`, `hermiq.getAgentStatus`, `hermiq.listPendingApprovals`, `hermiq.searchSkills`) MUST all be read-only, each with `readOnlyHint: true` and `scope: 'read'`. A run by hand stays a human action on the Run now button, because an agent able to run agents could re-enter itself and spend the organisation's budget unattended.

#### Scenario: The companion is asked to run an agent
- **GIVEN** a schedule the caller owns
- **WHEN** an agent or the AI companion invokes `hermiq.runAgentNow` for that schedule's id
- **THEN** the system MUST answer the `unknown_tool` error envelope
- **AND** MUST NOT load or run the schedule

#### Scenario: The domain tools only read
- **GIVEN** the domain tool descriptors
- **WHEN** the catalogue is built
- **THEN** every domain tool MUST declare `readOnlyHint: true` and `scope: 'read'`

