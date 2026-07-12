## ADDED Requirements

### Requirement: Deliver a failure alert to the schedule owner [MVP]

The system MUST notify a schedule's owner — regardless of the schedule's own
`deliver` output-channel setting (including `deliver='none'`) — when a run
becomes dead-lettered or when a schedule is auto-paused by the circuit breaker,
using the same Talk (Note-to-self) → Notification fallback chain already used
for normal run-output delivery. A failure to deliver this alert MUST NOT alter
the run/schedule state that was already recorded and MUST NOT fail the tick.

#### Scenario: Dead-letter triggers an owner alert even when deliver=none

- GIVEN a schedule with `deliver='none'` and `retryEnabled=true`
- WHEN its retry budget is exhausted and the occurrence is marked
  `dead_letter`
- THEN the owner MUST still receive a Talk message (or, when Talk is
  unavailable, a Nextcloud notification) describing the failure and linking to
  the schedule/run

#### Scenario: Circuit-breaker trip triggers a distinct owner alert

- GIVEN a schedule reaches its `circuitBreakerThreshold` and is auto-paused
- WHEN the auto-pause is recorded
- THEN the owner MUST receive an alert distinct from the dead-letter alert,
  stating that the schedule was automatically paused after repeated failures,
  linking to the schedule

#### Scenario: A failed alert never fails the run or reverts recorded state

- GIVEN both the Talk and Notification channels throw
- WHEN a dead-letter or circuit-breaker alert attempt is made
- THEN the run/schedule state already recorded (`dead_letter` or
  `paused_circuit_breaker`) MUST remain unchanged
- AND the delivery failure MUST only be logged, never re-thrown to the
  dispatcher
