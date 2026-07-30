# talk-delivery (delta)

Delivery gains one new obligation: record which room it delivered into, so the report it just
posted can be replied to. Every existing delivery behaviour — the room → Note-to-self →
notification fallback chain, non-fatal failure handling, `lastDeliveryError` persistence — is
unchanged.

## ADDED Requirements

### Requirement: Talk delivery binds the delivered-for conversation to the room

When the system delivers a run's output into a Talk room, it MUST record that room's token on
the `Conversation` the run produced, using the top-level `talkRoomToken` property, so that a
later message in that room resolves to the session that produced the output. The binding MUST
be written for every trigger that delivers to a room — scheduled, event/flow-triggered and
webhook-triggered runs alike.

#### Scenario: A scheduled report binds its session to the room

- **GIVEN** a schedule with `deliver = talk` and a target room
- **WHEN** the schedule fires and its output is delivered to that room
- **THEN** the conversation the run produced MUST carry that room's token in `talkRoomToken`
@e2e Live: trigger a Talk-delivering schedule and assert the produced conversation carries the target room's token.

#### Scenario: A reply to a delivered report continues that session

- **GIVEN** a report delivered into a room by a scheduled run
- **WHEN** a user replies in that room addressing the agent
- **THEN** the turn MUST be appended to the conversation the report came from
- **AND** the agent's answer MUST have that run's history available
@e2e Live: deliver a report, reply in the room, and assert the answer lands on the same conversation as the report.

#### Scenario: Every triggered path binds

- **WHEN** output is delivered to a room by an event-, flow- or webhook-triggered run
- **THEN** the conversation that run produced MUST be bound to that room
@e2e exclude Requires driving three separate trigger sources; the scheduled path is covered live and the remaining paths share the delivery seam, asserted by unit tests per trigger.

### Requirement: Binding never breaks delivery

Writing the room binding MUST NOT be able to fail a delivery or a run. If the binding cannot be
persisted, the system MUST still deliver the output and MUST record the failure, consistent
with the existing rule that a delivery failure never fails the run.

#### Scenario: A failed binding still delivers

- **GIVEN** a delivery to a room where persisting the binding fails
- **WHEN** the run delivers its output
- **THEN** the output MUST still be posted to the room
- **AND** the run MUST NOT be marked failed
@e2e exclude Requires an injected persistence failure; asserted by unit test on the delivery service.

### Requirement: Delivery without a room does not bind

The system MUST NOT write a `talkRoomToken` binding when output is delivered by any path that
is not a Talk room — Note-to-self, a notification, email or webhook — so that no conversation is
bound to a room it was never delivered into.

#### Scenario: Note-to-self delivery leaves the conversation unbound

- **GIVEN** a schedule with `deliver = talk` and no target room, falling back to Note-to-self
- **WHEN** the run delivers its output
- **THEN** the produced conversation MUST carry no `talkRoomToken`
@e2e exclude Fallback-path shape assertion; asserted by unit test alongside the existing fallback-chain coverage.

#### Scenario: Notification delivery leaves the conversation unbound

- **GIVEN** a schedule delivering by notification
- **WHEN** the run delivers its output
- **THEN** the produced conversation MUST carry no `talkRoomToken`
@e2e exclude Fallback-path shape assertion; asserted by unit test.
