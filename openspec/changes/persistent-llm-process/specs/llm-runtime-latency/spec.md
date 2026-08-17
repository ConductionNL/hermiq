## ADDED Requirements

### Requirement: A pooled process MUST NOT be shared across users

A long-lived model process MUST be keyed by BOTH the agent and the acting user, and
MUST NOT serve a turn for any other user.

A live process holds resolved credentials. Hermiq's personal-scope contract states
that a subscription credential serves its owner and not an organisation, so a pool
keyed by agent alone would route one user's turn through another user's credential —
a cross-user credential leak wearing the costume of a cache hit.

#### Scenario: A second user does not reach the first user's process

- **GIVEN** a pooled process created for agent `A` and user `alice`
- **WHEN** user `bob` starts a turn on agent `A`
- **THEN** `bob`'s turn MUST NOT be dispatched to `alice`'s process
- **AND** a separate process MUST be created or `bob`'s turn MUST take the cold path

#### Scenario: An unattributable turn takes the cold path

- **GIVEN** a turn whose acting user cannot be resolved
- **WHEN** dispatch is attempted
- **THEN** it MUST NOT be routed to any pooled process

### Requirement: A pooled process MUST NOT carry conversation state between turns

Reuse MUST be limited to process-level warmth — runtime, module graph, resolved
config, MCP client. Nothing derived from a previous turn's messages may influence a
later turn.

A fresh process guarantees this by construction. A pooled one must demonstrate it,
because the failure is silent: an answer subtly shaped by an earlier, unrelated
prompt looks exactly like a normal answer.

#### Scenario: A second turn cannot see the first turn's content

- **GIVEN** a pooled process that has completed a turn containing a distinctive token
- **WHEN** a second turn runs on the same process with an unrelated prompt
- **THEN** the response MUST NOT contain or reference that token
- **AND** the test MUST use a token that could not plausibly be generated independently

### Requirement: A grant change MUST invalidate that agent's pooled processes

When an agent's `tools` change, every pooled process for that agent MUST be
invalidated before it serves another turn.

A pooled process holds the tool set it started with. Without invalidation, revoking a
tool would leave it live in memory for as long as the process survives — governance
that the running system does not observe. This is the specific interaction with
`tool-scope-security-default`: scoping tools is worthless if a cached process ignores
the scope.

#### Scenario: A revoked tool is not reachable after invalidation

- **GIVEN** a pooled process started when the agent granted a tool
- **AND** that grant is subsequently removed
- **WHEN** the next turn runs
- **THEN** the tool MUST NOT be callable
- **AND** the turn MUST use a process built from the current grants

#### Scenario: Invalidation is not deferred to idle reaping

- **GIVEN** an agent whose grants change while a pooled process is warm and in use
- **WHEN** the next turn arrives before any idle timeout
- **THEN** the stale process MUST already have been invalidated

### Requirement: Pooling MUST NOT be able to fail a turn that would otherwise succeed

A turn MUST fall back to spawning a process whenever a pooled one is unavailable,
unhealthy, or fails to accept the turn. Pool failure MUST be an internal detail, not
a user-visible error.

This change trades a guarantee (every turn gets a clean process) for latency. It may
not also trade away reliability.

#### Scenario: An unhealthy pooled process falls back

- **GIVEN** a pooled process that has crashed or stopped responding
- **WHEN** a turn is dispatched to it
- **THEN** the turn MUST complete via a freshly spawned process
- **AND** the failure MUST be recorded, because a pool that silently never hits is indistinguishable from one that is working

#### Scenario: Pool exhaustion does not queue indefinitely

- **GIVEN** the pool is at its bound
- **WHEN** another turn arrives
- **THEN** it MUST take the cold path rather than wait without limit

### Requirement: The saving MUST be measured, before and after

The change MUST be accompanied by measurements of spawn cost and handshake cost on
the same instance, taken before and after, and reported even where disappointing.

The predicted saving is ~340 ms of spawn per turn plus up to ~2 s of avoided MCP
re-handshake. **Neither is measured.** A previous optimisation in this programme cut
a payload by 76% and moved latency by only 20–25%, because the cost was not where it
appeared to be. That precedent is the reason this requirement exists.

#### Scenario: A cold turn and a pooled turn are compared directly

- **GIVEN** the same prompt, agent and instance
- **WHEN** it is run against a cold path and a pooled path
- **THEN** both timings MUST be recorded
- **AND** the reported saving MUST be the difference, not an estimate of the spawn cost

#### Scenario: A pool that never hits is reported as such

- **GIVEN** a deployment where every turn takes the cold path
- **WHEN** the metrics are read
- **THEN** the hit rate MUST be visible as zero
- **AND** it MUST NOT be reportable as a latency improvement
