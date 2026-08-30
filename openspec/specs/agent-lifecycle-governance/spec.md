# agent-lifecycle-governance Specification

## Purpose
TBD - created by archiving change agent-lifecycle-governance. Update Purpose after archive.
## Requirements
### Requirement: Automatic offboarding pause on Nextcloud user deletion or disable
When a Nextcloud user is deleted or disabled, the system MUST automatically set
`Schedule.enabled = false` on every Schedule owned by that user or whose Agent's `actingUser`
resolves to that user, and MUST flag each affected Agent for reassignment. This MUST happen
without any human action being required to stop future occurrences from firing.

#### Scenario: A schedule owner's Nextcloud account is deleted
- GIVEN a user owns one enabled Schedule that fires a daily briefing
- WHEN that user's Nextcloud account is deleted
- THEN the system MUST set that Schedule's `enabled` field to `false`
- AND the Schedule MUST NOT fire again until an org admin explicitly re-enables it
- AND the owning Agent MUST be flagged for reassignment

#### Scenario: An agent's acting user is disabled, not deleted
- GIVEN an Agent declares `actingUser` set to a Nextcloud user, and a Schedule fires that Agent
- WHEN that Nextcloud user is disabled (not deleted)
- THEN the system MUST pause every Schedule that resolves to that `actingUser`
- AND the Agent MUST be flagged for reassignment
- AND the pause MUST NOT affect Schedules belonging to other users in the same organisation

#### Scenario: A currently-executing run is not interrupted
- GIVEN a Schedule's run is already in progress when its owner's account is deleted
- WHEN the offboarding pause is applied
- THEN the in-progress run MUST be allowed to complete normally
- AND only future occurrences of that Schedule MUST be prevented from firing

### Requirement: Org-admin reassignment flow for flagged agents
The system MUST let an org admin reassign a flagged Agent to a new, existing, active Nextcloud
user, and MUST clear the reassignment flag once reassignment is recorded. The system MUST NOT
automatically re-enable any Schedule paused by offboarding — re-enabling MUST remain an explicit,
separate, auditable org-admin action.

#### Scenario: An org admin reassigns a flagged agent
- GIVEN an Agent is flagged for reassignment after its acting user was deleted
- WHEN an org admin assigns a new, existing, active Nextcloud user to that Agent
- THEN the system MUST update the Agent's `actingUser` to the new user
- AND the system MUST clear the reassignment flag
- AND any Schedule the offboarding pause disabled MUST remain disabled until the org admin
  separately re-enables it

#### Scenario: An org admin attempts to reassign to a non-existent user
- GIVEN an Agent is flagged for reassignment
- WHEN an org admin attempts to reassign it to a user id that does not exist or is disabled
- THEN the system MUST reject the reassignment
- AND the Agent MUST remain flagged for reassignment

### Requirement: Periodic access review with capability summary
The system MUST provide an org-admin view listing every Agent in the caller's organisation with
its owner, `actingUser` (if set), last-run timestamp, and a capability summary (tool allowlist,
RAG scope), so the reviewer does not need to inspect each Agent individually.

#### Scenario: An org admin opens the access review list
- GIVEN an organisation with several Agents, each with different tool allowlists and owners
- WHEN an org admin opens the access review view
- THEN the system MUST list every Agent in that organisation with owner, `actingUser`, last-run
  timestamp, and a summary of its tool allowlist and RAG scope
- AND Agents belonging to other organisations MUST NOT appear in the list

### Requirement: Reviewed attestation is recorded and auditable
The system MUST let an org admin record a "reviewed" attestation for an individual Agent,
capturing the reviewing user's id and a timestamp, and this attestation MUST be durably recorded
and appear in the organisation's audit trail.

#### Scenario: An org admin attests that an agent was reviewed
- GIVEN an org admin is viewing the access review list
- WHEN the org admin marks one Agent as reviewed
- THEN the system MUST record the reviewing user's id and the current timestamp on that Agent
- AND that attestation MUST be retrievable later as part of the organisation's audit record
- AND re-attesting the same Agent MUST update the timestamp and reviewer rather than create a
  duplicate, unbounded attestation record

### Requirement: Incident records linked to runs and agents
The system MUST let an org admin open an incident record capturing a description, its impact, and
the actions taken, optionally linked to one or more runs and/or an Agent, and MUST persist each
incident record as a durable, tenant-scoped OpenRegister object.

#### Scenario: An org admin opens an incident from a problematic run
- GIVEN a run produced an unexpected or harmful result
- WHEN an org admin opens an incident record describing what happened, its impact, and the
  actions taken, linking it to that run and its Agent
- THEN the system MUST persist the incident record scoped to the caller's organisation
- AND the incident record MUST reference the linked run(s) and Agent
- AND the incident MUST appear in that organisation's incident list, newest first

#### Scenario: A cross-tenant incident list request is made
- GIVEN organisations A and B each have their own incident records
- WHEN a user in organisation A requests the incident list
- THEN the system MUST return only organisation A's incident records
- AND organisation B's incident records MUST NOT appear in the response

### Requirement: Incident records are included in the Art. 12 audit export
The system's per-tenant EU AI Act audit export MUST include the organisation's incident records
alongside its run and approval-decision audit entries, so that human-authored incident response is
part of the demonstrable record-keeping trail, not only the raw event stream.

#### Scenario: An org admin exports the audit trail after recording an incident
- GIVEN an organisation has one or more incident records
- WHEN an org admin requests the EU AI Act audit export
- THEN the export MUST include each incident record's description, impact, actions taken, and
  linked run/agent references
- AND the export MUST remain scoped strictly to the caller's own organisation

