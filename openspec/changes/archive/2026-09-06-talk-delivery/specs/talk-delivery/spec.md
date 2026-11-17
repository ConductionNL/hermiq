## MODIFIED Requirements

### Requirement: Deliver run output to Nextcloud Talk [MVP]

When a schedule's `deliver = talk`, the system MUST post the agent's run output as a Talk message resolved through this ordered chain for the already-impersonated owner: (1) if the schedule's `deliverTarget` room token is set, post to **that room** — but only after verifying the owner is a member of it via an owner-scoped room lookup; (2) if `deliverTarget` is empty/unset, OR the owner is not a member of that room, OR the room is invalid/unreachable, post to the owner's **Note-to-self** conversation; (3) if Talk (spreed) is unavailable or Note-to-self cannot be resolved, fall back to a Nextcloud notification. Each fall-through MUST record a delivery warning. The system MUST NEVER post to a room the owner is not a member of.

#### Scenario: Targeted room delivery

- **GIVEN** a schedule with `deliver = talk` and `deliverTarget` set to a room the owner is a member of
- **WHEN** the agent run completes with non-empty output
- **THEN** the system MUST post the output as a message to **that** room
- **AND** MUST resolve the room with an owner-scoped lookup (proving membership) before posting

#### Scenario: Empty deliverTarget uses Note-to-self

- **GIVEN** a schedule with `deliver = talk` and no `deliverTarget`
- **WHEN** the agent run completes with non-empty output
- **THEN** the system MUST post the output to the owner's Note-to-self conversation

#### Scenario: Owner not a member of the target room falls back

- **GIVEN** a schedule with `deliver = talk` and a `deliverTarget` the owner is NOT a member of (or an invalid/unreachable room)
- **WHEN** the agent run completes with output
- **THEN** the system MUST NOT post to that room
- **AND** MUST fall back to the owner's Note-to-self conversation and record a delivery warning

#### Scenario: Talk not installed → notification fallback

- **GIVEN** Talk (spreed) is not installed or its backend is unavailable
- **WHEN** a run completes for a schedule set to `deliver = talk`
- **THEN** the system MUST fall back to a Nextcloud notification to the owner
- **AND** MUST record a delivery warning

#### Scenario: Empty or silent output posts nothing

- **GIVEN** a schedule with `deliver = talk`
- **WHEN** the agent run produces empty, whitespace-only, or explicitly silent output
- **THEN** the system MUST post nothing to any room and raise no fallback notification

### Requirement: Notification fallback [MVP]

When `deliver = notification` (a first-class channel), OR when a `talk` delivery falls back, the system MUST raise a Nextcloud notification (`OCP\Notification\IManager`) to the schedule **owner** linking to the schedule/run record, and MUST register an `INotifier` so the notification renders with a subject, message, and link. This channel MUST NOT depend on Talk being installed.

#### Scenario: First-class notification delivery

- **GIVEN** a schedule with `deliver = notification`
- **WHEN** the agent run completes with non-empty output
- **THEN** the system MUST raise a Nextcloud notification to the owner linking to the schedule/run
- **AND** the notification MUST render via a registered `INotifier`

#### Scenario: Notification channel works without Talk

- **GIVEN** Talk (spreed) is not installed on the instance
- **WHEN** a run completes for a schedule set to `deliver = notification`
- **THEN** the notification MUST still be delivered (this channel has no Talk dependency)

### Requirement: Delivery failures are recorded, not fatal [MVP]

A delivery error MUST NOT fail the run and MUST NOT set the run's `lastStatus` to `error`; the delivery layer MUST catch its own errors and return a result. The failure MUST be recorded both as a structured log warning (schedule UUID + channel) AND by persisting the message into the schedule's `lastDeliveryError` field via `ObjectService`, written through the dispatcher's existing `sanitizeForSave` path so date-time/`repeat` round-trip artifacts are not corrupted. A fully-successful delivery MUST clear `lastDeliveryError`.

#### Scenario: Talk post fails but the run stays complete

- **GIVEN** a run produced output and `deliver = talk`
- **WHEN** the Talk post (and every fallback) returns an error
- **THEN** the run MUST still be finalised with `lastStatus = ok` (not `error`)
- **AND** the delivery failure MUST be recorded as a warning AND persisted to the schedule's `lastDeliveryError`, separately from the run status

#### Scenario: Successful delivery clears the last error

- **GIVEN** a schedule whose `lastDeliveryError` is set from a prior failed run
- **WHEN** a later run delivers successfully
- **THEN** the system MUST clear `lastDeliveryError` on the schedule

#### Scenario: `deliver = none` is silent

- **GIVEN** a schedule with `deliver = none`
- **WHEN** the agent run completes
- **THEN** the system MUST perform no delivery and raise no notification
