# Agent Capability Profile Specification

**Status**: active (the acting-user rule below is superseded, see the note)

**Feature tier**: V1

**OpenSpec changes:** `agent-capability-profile` — DONE (12 of 12 tasks): an `Agent` carries an
explicit `skillInstalls` allowlist kept in sync on install, the already-shipped `tools` allowlist is
formalised as part of the profile, and `actingUser` is wired as the impersonation identity.

## Purpose

What one agent is allowed to be: which skills it has installed, which tools it may call, and whose
rights its turns execute with. Implements `SPECTR-NEXTCLOUD-PLAN.md` section 6.3.

The three fields are one profile because they are read together at turn assembly. Splitting them
across surfaces is how an agent ends up with a tool it may call and no identity to call it as.

## Requirements

### Requirement: Agent acting-user impersonation

The system MUST allow an `Agent` to declare an `actingUser` (an NC user id). When a scheduled run's
bound agent has a set `actingUser` that resolves to an existing, enabled NC user, the system MUST
impersonate that user (not the schedule owner) for the duration of the agent turn
(conversation/message writes), and MUST record the identity that actually ran on the run's audit
entry.

🔴 The unresolvable case is governed by [agent-identity](../agent-identity/spec.md), not by this
spec. `agent-identity-narrows` supersedes what this requirement originally said: a declared
`actingUser` that no longer resolves MUST refuse the run rather than fall back to the schedule
owner. Disabling an account is how a departure is normally processed, so falling back is least
acceptable exactly when it is most likely to fire. An agent that declares no `actingUser` at all
still runs as the schedule's own identity, because expressing no preference is not the same as
naming an identity that has gone.

#### Scenario: A schedule's agent has a valid actingUser configured
- **GIVEN** an `Agent` with `actingUser` set to an existing, enabled NC user distinct from
  the schedule's owner
- **WHEN** the schedule fires (engine-enabled path)
- **THEN** the system MUST impersonate `actingUser` for the agent turn
- **AND** the run's audit entry MUST record `actingUser` as the identity that ran

#### Scenario: actingUser is unset
- **GIVEN** an `Agent` with no `actingUser`
- **WHEN** the schedule fires (engine-enabled path)
- **THEN** the system MUST impersonate the schedule owner
- **AND** the run MUST NOT fail

### Requirement: Agent skill allowlist

The system MUST let an `Agent` declare `skillInstalls`, an explicit array of installed
Skill uuids, kept in sync whenever a Skill is installed onto that agent.

#### Scenario: A skill is installed onto an agent
- **GIVEN** a Skill S and an Agent A
- **WHEN** S is installed onto A
- **THEN** S's `installedOn` MUST include A's uuid
- **AND** A's `skillInstalls` MUST include S's uuid

### Requirement: Agent tool allowlist

The system MUST enforce `Agent.tools` as the fleet MCP tool allowlist at turn assembly: an
empty array allows every discovered tool; a non-empty array restricts LLM function
definitions to the listed `{appId}.{toolName}` ids. This formalises the already-shipped
`ToolLoop` contract as part of the capability-profile surface, per section 6.3's
"toolAllowlist".

#### Scenario: An agent's tool allowlist restricts the turn's available functions
- **GIVEN** an Agent with `tools = ["decidiq.listMeetings"]`
- **WHEN** a chat turn assembles the LLM's available functions
- **THEN** only `decidiq.listMeetings` MUST be offered to the model
- **AND** no other fleet-registry tool MUST be exposed for that turn
