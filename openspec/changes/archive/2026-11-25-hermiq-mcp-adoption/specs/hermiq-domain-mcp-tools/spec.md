# hermiq-domain-mcp-tools Specification (delta)

## ADDED Requirements

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

## REMOVED Requirements

### Requirement: The AI companion can trigger an agent run only for schedules the caller owns
**Reason**: Ruben, 25 Nov 2026: the domain tools stay, but `hermiq.runAgentNow` is removed (self-re-entry, budget burn). Replaced by "No domain tool starts an agent run".
**Migration**: none; a person runs a schedule with the Run now button (`POST /api/schedules/{id}/run`), which keeps the same owner guard.
