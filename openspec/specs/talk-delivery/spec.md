# Talk Delivery Specification

**Status**: active (shipped to `main` v0.1.10; Talk room → note-to-self → notification fallback, live-verified)
**Standards**: Nextcloud Talk (spreed) OCS API, Nextcloud Notifications
**Feature tier**: MVP

**OpenSpec changes:**
- `openspec/changes/talk-delivery-schema/` — `deliverTarget` + `lastDeliveryError` Schedule fields (kind: config) — **done**
- `openspec/changes/talk-delivery/` — the delivery service + dispatcher wiring (kind: code, depends_on talk-delivery-schema)

## Purpose

Deliver the output of a scheduled (or manual) agent run to the user inside Nextcloud —
primarily as a **Nextcloud Talk** message, with a **Notification** fallback. This replaces
Hermes' 22-platform chat gateway with a single Nextcloud-native channel, so there is no
separate gateway process to run and delivery inherits Nextcloud identity and permissions.
## Requirements
### Requirement: Deliver run output to Nextcloud Talk [MVP]
When a schedule's `deliver=talk`, the system MUST post the agent's run output as a message to a configured Talk room the owner is a member of.

#### Scenario: Daily briefing arrives in Talk
- GIVEN a schedule with `deliver=talk` and a target Talk room token
- WHEN the agent run completes with output
- THEN the system MUST post the output as a Talk message to that room authored by the Hermiq bot
- AND if the output is empty or explicitly silent, MUST post nothing

### Requirement: Notification fallback [MVP]
When `deliver=notification` (or Talk is unavailable), the system MUST raise a Nextcloud notification to the owner linking to the run record.

#### Scenario: Talk not installed → notification
- GIVEN Talk (spreed) is not installed on the instance
- WHEN a run completes for a schedule set to `deliver=talk`
- THEN the system SHOULD fall back to a Nextcloud notification and record a delivery warning on the run

### Requirement: Delivery failures are recorded, not fatal [MVP]
A delivery error MUST NOT fail the run; it MUST be recorded on the run/schedule (`lastDeliveryError`).

#### Scenario: Talk post fails
- GIVEN a run produced output
- WHEN the Talk post returns an error
- THEN the run MUST still be marked complete and audited, with the delivery error stored separately

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

## User Stories

- As a user, I want my agent's results to show up in a Talk chat so that I read them where I already work.
- As a user without Talk, I want a notification instead so that I still get results.
- As an admin, I want delivery failures logged so that I can see when a channel is misconfigured.

## Acceptance Criteria

- [ ] A Talk delivery adapter posts run output to a room via the spreed OCS chat API as a bot.
- [ ] A Notification fallback delivers to the owner and links to the run record.
- [ ] Empty/silent output produces no message.
- [ ] Delivery errors are stored on the run and never fail the run itself.
- [ ] The target channel is configurable per schedule.

## Notes

- **Dependency:** Nextcloud Talk (spreed) is NOT installed on the current dev instance
  (only `opentalk` video) — this is a hard operator dependency; see ADR-005 for the
  decision and fallback. NC Mail (IMailer) outbound is planned under `nc-native-tools`.
- Related: **ADR-005** (delivery via Nextcloud Talk), `agent-schedule`, `run-audit-log`.
