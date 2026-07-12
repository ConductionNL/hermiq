## ADDED Requirements

### Requirement: Run history surfaces retry attempts and dead-letter/circuit-breaker outcomes [MVP]

The run-history read surface MUST expose, per run record, the retry attempt
number when the run is part of a retry sequence, and MUST support `status`
values `retry_pending`, `dead_letter`, and `paused_circuit_breaker` (in
addition to the existing `ok` / `error` / `running` / `skipped_killswitch` /
`awaiting_approval` vocabulary), sourced from the audit entry's redacted
context exactly like the existing `status`/`durationMs`/`summary` fields.

#### Scenario: A dead-lettered occurrence's full retry sequence is visible

- GIVEN an occurrence that failed once, retried twice, and was ultimately
  marked `dead_letter`
- WHEN the owner opens run history for that schedule
- THEN the system MUST list each attempt (including both retries) newest-first
  with its own status, timing, and attempt number
- AND the final (most recent) entry MUST show `status='dead_letter'`

#### Scenario: A circuit-breaker auto-pause is visible in run history

- GIVEN a schedule whose third consecutive dead-letter trips the circuit
  breaker
- WHEN the owner opens run history for that schedule
- THEN the entry for that occurrence MUST show `status='paused_circuit_breaker'`
  alongside the prior `dead_letter` entries, so the owner can see why the
  schedule stopped running
