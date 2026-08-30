# agent-versioning Specification

## Purpose
TBD - created by archiving change agent-versioning. Update Purpose after archive.
## Requirements
### Requirement: List an Agent's version history
The system MUST list an Agent's version history as the OpenRegister
AuditTrail `create`/`update` entries already recorded for that Agent object,
newest-first, each carrying a stable version identifier (the AuditTrail
entry's own UUID), a timestamp, the acting user, and the action
(`create`/`update`).

#### Scenario: An agent owner views their agent's version history
- GIVEN an Agent has been edited three times since creation (four AuditTrail
  entries: one `create`, three `update`)
- WHEN the agent's owner requests its version history
- THEN four versions are returned, newest-first
- AND each version has a version id, a timestamp, and the acting user

#### Scenario: A non-owner without access cannot list another tenant's agent versions
- GIVEN an Agent is private and the requesting user is neither its owner nor
  an invited user
- WHEN that user requests the agent's version history
- THEN the request is denied
- AND no version data is returned

#### Scenario: A newly created agent has exactly one version
- GIVEN an Agent was just created and has never been edited
- WHEN its version history is requested
- THEN exactly one version is returned, corresponding to the `create` entry

### Requirement: Diff two agent versions across the versioned-config field set
The system MUST compute a field-level diff between any two versions of an
Agent, limited to the fixed versioned-config field set (`prompt`, `model`,
`provider`, `temperature`, `maxTokens`, `configuration`, `tools`,
`skillInstalls`, `contextRefs`, `enableRag`, `ragSearchMode`, `ragNumSources`,
`ragIncludeFiles`, `ragIncludeObjects`, `views`, `searchFiles`,
`searchObjects`), showing the old and new value of every field that differs
between the two versions.

#### Scenario: Diffing two versions where only the prompt changed
- GIVEN version A has `prompt = "Draft permits"` and version B (a later
  version of the same agent) has `prompt = "Draft permits concisely"`, with
  no other versioned-config field changed between them
- WHEN the diff between version A and version B is requested
- THEN the diff contains exactly one changed field, `prompt`, with its old
  and new values
- AND no unrelated field (e.g. `name`, `isPrivate`) appears in the diff

#### Scenario: Diffing two versions that also differ in tools and skills
- GIVEN version A allows tools `["opencatalogi.search"]` and version B adds
  `"openconnector.fetch"`, and version A has no `skillInstalls` while version
  B has one skill installed
- WHEN the diff between version A and version B is requested
- THEN the diff includes `tools` showing the old and new arrays
- AND the diff includes `skillInstalls` showing the old and new arrays

#### Scenario: Diffing a version against itself yields no changes
- GIVEN a single version id is given as both the "from" and "to" version
- WHEN the diff is requested
- THEN the diff contains no changed fields

### Requirement: Roll back an agent to a previous version without mutating history
The system MUST let an authorized user roll back an Agent's versioned-config
fields to the values recorded in a previous version by creating a new
version whose versioned-config field values equal that previous version's
values; existing AuditTrail entries MUST NOT be altered or deleted by a
rollback.

#### Scenario: An agent owner rolls back to a previous version
- GIVEN an Agent's current prompt differs from the prompt recorded in an
  earlier version V
- WHEN the owner rolls the agent back to version V
- THEN the agent's live prompt (and other versioned-config fields) matches
  version V's recorded values
- AND a brand-new version is recorded as a result of the rollback
- AND version V's own AuditTrail entry is unchanged and still listed in the
  version history exactly as it was before the rollback

#### Scenario: Rolling back does not touch identity, visibility, or quota fields
- GIVEN an Agent's `name`, `isPrivate`, and `tokenQuota` differ between the
  current state and the target rollback version
- WHEN the owner rolls the agent back to that version
- THEN `name`, `isPrivate`, and `tokenQuota` retain their CURRENT values
  after the rollback
- AND only the versioned-config fields are changed to match the target
  version

#### Scenario: A non-owner cannot roll back another user's agent
- GIVEN a user who is not the Agent's owner
- WHEN that user attempts to roll back the agent to a previous version
- THEN the request is denied
- AND the agent's live configuration is unchanged

### Requirement: A run's audit entry pins the exact Agent version that executed it
The system MUST record the executing Agent's version identifier on every
run/interaction AuditTrail entry Hermiq writes (scheduled runs,
flow-triggered runs, webhook-triggered runs, and context-agent
interactions), alongside the existing `attempt`/`steps`/`usage` context, so
each run can be traced back to the exact prompt/model/provider/tools that
produced it.

#### Scenario: A scheduled run's audit entry records the agent version that ran
- GIVEN a Schedule bound to an Agent currently at version V
- WHEN the schedule dispatches and the run completes
- THEN the run's AuditTrail entry context includes the agent version
  identifier V
- AND that identifier matches the version that was current at run start,
  not any version created after the run began

#### Scenario: A flow-triggered run's audit entry also records the agent version
- GIVEN an Agent run triggered via `FlowAgentRunService` (OpenRegister's
  `AgentRunRequestedEvent`)
- WHEN the run completes
- THEN the flow-run's AuditTrail entry context includes the executing
  agent's version identifier

#### Scenario: A version pin is never fatal to the run itself
- GIVEN the version-lookup used to pin the agent version fails unexpectedly
  (e.g. the AuditTrail table is temporarily unavailable)
- WHEN a run completes
- THEN the run's audit entry is still written (without the version pin)
- AND the run's own status/output is unaffected

### Requirement: Run history surfaces the pinned agent version
The system MUST expose the pinned agent version identifier on the
already-shipped run-history read surface so a user reviewing a schedule's
run history can see, per run, which agent version produced it.

#### Scenario: A run history row shows its pinned agent version
- GIVEN a schedule has completed runs with pinned agent versions recorded
- WHEN a user requests that schedule's run history
- THEN each run record includes the pinned agent version identifier alongside
  its existing `attempt`/`status`/`summary` fields

#### Scenario: A pre-existing run with no pinned version degrades gracefully
- GIVEN a run's AuditTrail entry was written before this capability existed
  and therefore has no recorded agent version
- WHEN that run appears in run history
- THEN the run record's agent version field is empty/null rather than
  causing an error

