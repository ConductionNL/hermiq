# sub-agent-delegation

## ADDED Requirements

### Requirement: Several parts of one task run on sub-agents at the same time (REQ-DLGPAR-001)

An agent turn SHALL be able to hand several parts of a task to sub-agents in one call of the tool
`hermiq.delegateAgents`, with a list of target agent and task per part. Each part SHALL pass every
gate a single delegation passes, and each passing part SHALL count toward the per-turn fan-out
limit. Passing parts SHALL run at the same time, at most `delegation.maxParallel` at once, each in
its own isolated conversation, as the parent's acting user, and each SHALL appear in the
delegation audit tree under the parent run. The tool SHALL return one result per part in the order
of the call: the part's final text, or its refusal or error. A refused or failed part SHALL NOT stop
the other parts.

Rows: `fl-parallel`. Board: `hermiq/HmChat`.

#### Scenario: Three parts finish in about the time of the slowest
- **GIVEN** two background workers are running and `delegation.maxParallel` is 3
- **AND** an agent allowed to delegate to agents B, C and D, each of which takes about 60 seconds
- **WHEN** its turn calls `hermiq.delegateAgents` with one part for each of B, C and D
- **THEN** the runs of B, C and D overlap in time
- **AND** the tool returns three results in the order B, C, D
- **AND** the audit tree shows three delegated runs under the parent run

@e2e exclude needs several background workers and slow sub-agents; covered by a DelegationService unit test with a fake job list and run clock.

#### Scenario: One refused part does not stop the others
- **GIVEN** an agent whose allowlist names B and C but not D
- **WHEN** its turn calls `hermiq.delegateAgents` with parts for B, D and C
- **THEN** B and C run
- **AND** the second result is a refusal naming the allowlist
- **AND** the refused part does not count toward the fan-out limit

@e2e exclude a tool call from inside an agent turn has no page; covered by a DelegationService unit test.

#### Scenario: The fan-out limit applies to the whole list
- **GIVEN** `delegation.maxFanOut` is 3 and the turn has already delegated once
- **WHEN** it calls `hermiq.delegateAgents` with three allowed parts
- **THEN** the first two parts run and the third returns `delegation_fanout_exceeded`

@e2e exclude a tool call from inside an agent turn has no page; covered by a DelegationService unit test.

### Requirement: A part no worker picks up runs inline (REQ-DLGPAR-002)

A part that no background worker has claimed within `delegation.parallelGraceSeconds` SHALL be
claimed by the parent turn and run inline, so a turn never waits on a worker that is not there.
A part SHALL run once, whoever claims it. A part still running at
`delegation.parallelTimeoutSeconds` SHALL return `delegation_timeout` in its slot and be recorded
as timed out in the audit tree.

Rows: `fl-parallel`. Board: `hermiq/HmChat`.

#### Scenario: An instance with no free worker runs the parts one after another
- **GIVEN** no background worker is free
- **WHEN** a turn calls `hermiq.delegateAgents` with two allowed parts
- **THEN** after the grace period the parent runs both parts itself, one after the other
- **AND** the tool returns both results

@e2e exclude depends on background worker timing; covered by a DelegationService unit test with a fake clock.

#### Scenario: A worker and the parent race for a part
- **GIVEN** a worker claims a part at the same moment the grace period ends
- **WHEN** the parent tries to claim it
- **THEN** the parent's claim fails and the part runs only on the worker

@e2e exclude a timing race has no page; covered by a unit test of the claim.
