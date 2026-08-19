# talk-chat-bridge (delta)

The hand-off requirement is amended: the queued job becomes the durable fallback and
record while an immediate execution path (triggerable runner, or post-flush
in-process execution) executes the turn, with a turn-id claim preventing double
execution. The listener's fast-ack posture ("The bot listener never runs an agent
turn inline" — within the sender's request) is unchanged.

## MODIFIED Requirements

### Requirement: Turn hand-off is event-driven when possible and queued otherwise

The system MUST enqueue every turn as a durable background job, and MUST additionally
attempt immediate execution of the same turn: via a registered
`ITriggerableProvider` runner when one exists, otherwise via post-response
in-process execution where the runtime allows running work after the sender's
response has been flushed. The queued job MUST remain the durable record on all
paths, so a turn can never be lost between the mechanisms, and a turn-id claim MUST
ensure that only one path executes any given turn. All paths MUST execute the turn
through the same turn service, so that turn behaviour cannot diverge between them.
The choice of path MUST NOT change any user-visible behaviour other than latency,
and MUST NOT hold the request of the person who sent the message. An
`ISynchronousProvider` does not qualify as an immediate path — core runs those on
the same cron tick as the fallback.

#### Scenario: A registered triggerable runner is nudged in-request

- **GIVEN** a triggerable provider is registered
- **WHEN** an addressed message is handed off
- **THEN** that provider MUST be nudged before the listener returns
- **AND** the turn MUST still be durably enqueued
@e2e exclude Requires a registered triggerable provider; asserted by unit test on the hand-off selector.

#### Scenario: Post-flush execution answers without a cron tick

- **GIVEN** no triggerable provider is registered and the runtime supports
  post-response execution
- **WHEN** an addressed message is handed off
- **THEN** the turn MUST execute after the sender's response is flushed, without
  waiting for a background-job tick
- **AND** the turn MUST still be durably enqueued, and the queued job MUST find the
  claim and do nothing
@e2e Live Talk round-trip on a default install, asserting the answer arrives without an intervening cron run.

#### Scenario: No triggerable runner falls back to the queue

- **GIVEN** neither a triggerable provider nor post-response execution is available
- **WHEN** an addressed message is handed off
- **THEN** the turn MUST be enqueued as a background job
- **AND** the answer MUST still be posted to the originating room
@e2e exclude Requires disabling the post-flush capability; asserted by unit test on the hand-off selector.

#### Scenario: The turn runs as the speaker, not as nobody

- **GIVEN** a turn executing outside any HTTP request
- **WHEN** the turn writes its message objects
- **THEN** the system MUST act as the speaking user
- **AND** MUST restore the prior identity afterwards, whatever the outcome
@e2e Live: covered by the round-trip — without impersonation every write is attributed to "Anonymous" and refused by OpenRegister RBAC before the model is reached.

#### Scenario: A failed turn does not leave the room silent

- **GIVEN** a turn that fails during execution
- **WHEN** the failure is reported on any hand-off path
- **THEN** the system MUST NOT leave the acknowledging reaction as the only signal
@e2e exclude Requires an injected turn failure; asserted by unit tests covering the task-failure event, the post-flush error path, and the queued-job error path.
