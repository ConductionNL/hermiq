# agent-capability-profile (delta)

This change implements the per-agent capability profile described in
`SPECTR-NEXTCLOUD-PLAN.md` §6.3: an explicit skill allowlist, the (already-shipped) tool
allowlist, and a wired acting-user impersonation identity.

## ADDED Requirements

### Requirement: Agent acting-user impersonation
The system MUST allow an `Agent` to declare an `actingUser` (an NC user id). When a
scheduled run's bound agent has a set `actingUser` that resolves to an existing, enabled
NC user, the system MUST impersonate that user (not the schedule owner) for the duration
of the agent turn (conversation/message writes), and MUST record the identity that
actually ran on the run's audit entry. When `actingUser` is unset, or does not resolve to
an existing enabled user, the system MUST fall back to impersonating the schedule owner
(today's default) without failing the run.

#### Scenario: A schedule's agent has a valid actingUser configured
- **GIVEN** an `Agent` with `actingUser` set to an existing, enabled NC user distinct from
  the schedule's owner
- **WHEN** the schedule fires (engine-enabled path)
- **THEN** the system MUST impersonate `actingUser` for the agent turn
- **AND** the run's audit entry MUST record `actingUser` as the identity that ran

#### Scenario: actingUser is unset or invalid
- **GIVEN** an `Agent` with no `actingUser`, or an `actingUser` naming a non-existent or
  disabled account
- **WHEN** the schedule fires (engine-enabled path)
- **THEN** the system MUST impersonate the schedule owner (unchanged default behavior)
- **AND** the run MUST NOT fail because of the invalid override

### Requirement: Agent skill allowlist
The system MUST let an `Agent` declare `skillInstalls`, an explicit array of installed
Skill uuids, kept in sync whenever a Skill is installed onto that agent.

#### Scenario: A skill is installed onto an agent
- **GIVEN** a Skill S and an Agent A
- **WHEN** S is installed onto A
- **THEN** S's `installedOn` MUST include A's uuid
- **AND** A's `skillInstalls` MUST include S's uuid

## MODIFIED Requirements

### Requirement: Agent tool allowlist
The system MUST enforce `Agent.tools` as the fleet MCP tool allowlist at turn assembly: an
empty array allows every discovered tool; a non-empty array restricts LLM function
definitions to the listed `{appId}.{toolName}` ids (unchanged behavior — this requirement
formalises the already-shipped `ToolLoop` contract as part of the capability-profile
surface, per §6.3's "toolAllowlist").

#### Scenario: An agent's tool allowlist restricts the turn's available functions
- **GIVEN** an Agent with `tools = ["decidesk.listMeetings"]`
- **WHEN** a chat turn assembles the LLM's available functions
- **THEN** only `decidesk.listMeetings` MUST be offered to the model
- **AND** no other fleet-registry tool MUST be exposed for that turn
