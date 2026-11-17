# agent-memory

## ADDED Requirements

### Requirement: An owner can have an agent tidy its memory (REQ-SPCOV-008)

When an agent's memory is over its character budget, the Memory page SHALL show a nudge with
a Consolidate action. Consolidating SHALL let the agent's model merge overlapping facts and
drop facts that no longer hold, and SHALL leave the memory within its budget. Only the
agent's owner SHALL be able to start it.

Rows: `me-consolidate`. Board: `hermiq/HmGeheugen`.

#### Scenario: An owner consolidates an over-budget memory
- **GIVEN** an agent whose memory holds 40 facts and is over its character budget
- **WHEN** its owner chooses "Consolidate" on the Memory page
- **THEN** the memory holds fewer facts, is within its budget, and the nudge is gone

#### Scenario: Someone else cannot consolidate
- **WHEN** a user who does not own the agent calls the consolidate endpoint
- **THEN** the call is refused and the memory is unchanged
