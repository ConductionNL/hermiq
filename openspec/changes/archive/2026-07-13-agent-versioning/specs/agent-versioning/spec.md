# agent-versioning Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- agent-versioning

## Purpose

Every save of an Agent's configuration (prompt, model, provider, tools,
skills, capability profile) already produces an immutable, hash-chained
OpenRegister AuditTrail entry — no new storage is introduced by this
capability. This capability adds the read surface (version history, diff
between two versions), the write action (one-click rollback that creates a
new version equal in content to an old one), and threads the executing
Agent's version onto the run-audit trail so a trace always shows exactly
which configuration produced it. Addresses the "workflow/prompt versioning"
gap identified against 12 rival products (n8n, Windmill, Temporal, Zapier,
Make, Prefect, Airflow, Kestra, Trigger.dev, ProcessMaker, Langfuse,
Node-RED). Relates to EU AI Act Art. 12 traceability.

## ADDED Requirements

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

## Non-Functional Requirements

- **Performance:** Listing an Agent's version history MUST complete without
  scanning any object other than that Agent's own AuditTrail entries
  (filtered by `object_uuid`, matching the existing `RunHistoryService`
  query pattern).
- **Accessibility:** The version-history and diff UI MUST use standard NC
  components (NcDialog, NcButton) so keyboard navigation and screen-reader
  labelling are inherited, matching every other Hermiq dialog.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for
  all new user-facing strings (version-history dialog, diff labels, rollback
  confirmation).

## Acceptance Criteria

- [ ] An Agent's version history lists every `create`/`update` AuditTrail
  entry for that Agent, newest-first, with no new database table introduced.
- [ ] Diffing two versions returns only fields in the fixed versioned-config
  allowlist that actually differ.
- [ ] Rolling back creates a new AuditTrail entry and leaves every prior
  entry unmodified.
- [ ] Rollback never changes `name`/`description`/`type`/`active`/
  `isPrivate`/`invitedUsers`/`groups`/`requestQuota`/`tokenQuota`/
  `actingUser`/`user`/`reassignmentFlag`/`reviewedAt`/`reviewedBy`.
- [ ] All four per-run/per-interaction AuditTrail writers record the
  executing agent's version identifier.
- [ ] Run history displays the pinned agent version per run.
- [ ] Non-owners cannot read another tenant's private agent's version
  history and cannot roll back an agent they do not own.

## Notes

- Reuses OpenRegister's existing `AuditTrailMapper` (`createAuditTrail`,
  `findAll`, and the backward-diff-replay technique also used internally by
  `revertObject()`/`revertChanges()`) rather than introducing any new
  storage — see design.md Decisions 1-4.
- Deliberately does not call OpenRegister's own `GET .../audit-trails` HTTP
  endpoint (admin-gated) or its `/revert` endpoint (reverts the whole object,
  not the versioned-config subset) — see design.md Decisions 3 and 5.
- Out of scope: git sync/export of agent definitions (`agent-template-gallery`)
  and prompt A/B testing (`agent-evals`) — see proposal.md Out of Scope.
