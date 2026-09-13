## ADDED Requirements

### Requirement: Every run writes an explicit OpenRegister audit entry

The system MUST write an explicit OpenRegister `AuditTrail` entry for each agent
run when the run finalises (success or failure), via OpenRegister's public
`AuditTrailMapper::createAuditTrailEntry(ObjectEntity, action, context)` with
`action = 'run'`. The entry MUST reference the run's Schedule object and carry, in
its context, the bound `agentId`, the run `status` (ok/error), timing, and a
redacted output summary. Because the dispatcher impersonates the schedule owner
before finalising, the entry MUST inherit that owner's `user` and the Schedule's
`organisation`, and MUST chain into OpenRegister's existing `hash`/`previousHash`
trail. Hermiq MUST NOT create a second audit store or write state outside
`ObjectService`/OpenRegister's audit layer.

#### Scenario: A scheduled run is audited on success

- **WHEN** a scheduled agent run completes successfully on a dispatcher tick
- **THEN** an explicit `AuditTrail` entry with `action = 'run'` MUST exist for
  that Schedule object, carrying the owner as `user`, the schedule's
  `organisation`, `status = ok`, timing, and the bound `agentId`

#### Scenario: A failed run is audited

- **WHEN** a scheduled agent run fails during the agent turn
- **THEN** an explicit `AuditTrail` entry with `action = 'run'` and
  `status = error` MUST still be written for that Schedule object, so no run is
  absent from the trail

### Requirement: Sensitive data is redacted before the audit write

Secret- and PII-shaped tokens MUST be redacted from the run's output summary
**before** it is placed in the audit context and written. Because OpenRegister's
trail is an append-only hash chain, a value written once cannot be removed
without breaking the chain; therefore redaction MUST run before, never after,
the `createAuditTrailEntry` call.

#### Scenario: An API-key-shaped token in run output is masked

- **WHEN** the run output summary contains an API-key-shaped token and the run
  audit entry is being built
- **THEN** the persisted audit context MUST show the token masked, and the raw
  token MUST NOT appear anywhere in the written entry

### Requirement: An owner can read the run history for their schedule

A user MUST be able to read the run history for a schedule they own as a list of
run records (newest first), each with status and timing, sourced from
OpenRegister's audit entries for that Schedule object via
`ObjectService::getLogs()`. The read surface MUST be owner-scoped: it MUST NOT
return audit entries for a schedule the requesting user is not permitted to see,
and it MUST NOT expose an admin-only or cross-tenant path.

#### Scenario: Owner reviews recent runs

- **WHEN** the owner of a schedule with several past runs requests its run
  history
- **THEN** the system MUST return recent run records (newest first) with status
  and timing, restricted to entries the owner may see

#### Scenario: A non-owner is refused

- **WHEN** a user who does not own a schedule requests that schedule's run
  history
- **THEN** the system MUST NOT return that schedule's audit entries
