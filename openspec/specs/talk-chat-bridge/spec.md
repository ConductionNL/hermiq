# Talk Chat Bridge Specification

**Status**: in-progress
**Standards**: Nextcloud Talk (spreed ≥ 24) bot API — `nextcloudapp://` in-process dispatch, `BotInvokeEvent`, bot message OCS endpoint; Nextcloud TaskProcessing
**Feature tier**: MVP

**OpenSpec changes:**
- `openspec/changes/talk-chat-bridge-schema/` — the `Conversation.talkRoomToken` binding and `participants` roster, plus `Message.authorId` / `authorDisplayName` (kind: config)
- `openspec/changes/talk-chat-bridge/` — bot registration, the inbound listener, hybrid turn hand-off, room↔session resolution, mention gating and opt-in (kind: code, depends_on talk-chat-bridge-schema)

## Purpose

Make Nextcloud Talk an optional, **bidirectional** chat surface for Hermiq agent sessions — the
inbound half of the two-way channel ADR-005 chose Talk for and only ever half-built.

Hermiq registers as an in-process Talk bot. Where the bot is enabled, a room message becomes a
turn on a Hermiq session and the agent's answer is posted back into the room. Because Talk bot
messages are server-side, this makes an agent reachable from the Talk **iOS and Android apps**
with no mobile-client work at all.

It also closes the loop on scheduled runs. A cron report already lands in a Talk room via
`talk-delivery`; binding that room to the conversation the run produced means a reply — sent from
a phone, in the room where the report arrived — continues **that** session with its full history,
instead of dead-ending in a room nobody can answer.

The bridge is inert until deliberately switched on: an agent must be opted in, and a Talk
moderator must enable the bot in the room. With Talk absent, Hermiq behaves exactly as before.
## Requirements
### Requirement: An agent is reachable from Talk without any client-side work

The system MUST make an opted-in agent conversable from Nextcloud Talk using Talk's own clients,
including its mobile apps, without shipping or modifying client code.

#### Scenario: A user converses with an agent from Talk

- GIVEN an agent opted in to Talk and a room where the Hermiq bot is enabled
- WHEN a user addresses the agent in that room
- THEN the system MUST answer in that room using Talk's normal message rendering

### Requirement: Agent output delivered to Talk can be replied to

The system MUST bind a run's session to the Talk room its output was delivered into, so that a
reply in that room continues that session rather than starting an empty one.

#### Scenario: A cron report is answered from a phone

- GIVEN a scheduled agent run whose report was delivered to a Talk room
- WHEN a user replies in that room asking a follow-up
- THEN the system MUST answer with the originating run's history available

### Requirement: The bridge is optional and inert by default

The system MUST require explicit opt-in on both the Hermiq side and the Talk side before any
message produces a turn, and MUST behave exactly as it did before this feature when Talk is
absent or the bot is not enabled.

#### Scenario: Talk is not installed

- GIVEN an instance without Talk
- WHEN Hermiq boots, runs scheduled agents and renders its settings
- THEN the system MUST behave exactly as it did before this feature existed

### Requirement: A reply never blocks the person who sent it

The system MUST execute the agent turn outside the request that delivered the inbound message, and
MUST acknowledge receipt within that request so the sender knows their message landed.

#### Scenario: A message send is not held open by an agent turn

- GIVEN an agent turn that takes tens of seconds
- WHEN a user sends the message that triggers it
- THEN their message send MUST complete promptly
- AND the system MUST acknowledge receipt before the answer is ready

### Requirement: Hermiq registers as an in-process Talk bot

The system MUST register a Talk bot whose URL uses spreed's `nextcloudapp://` scheme, so that
spreed dispatches `BotInvokeEvent` in-process rather than issuing an HTTP webhook. The system
MUST NOT require a reachable callback URL, a shared webhook secret, or any outbound network
access for the bridge to function. Bot install, enable, disable and uninstall MUST go through
Talk's own bot lifecycle so that an operator can remove the integration from Talk alone.

#### Scenario: The bot is installed with the app URL scheme

- **WHEN** the Hermiq Talk bot is installed
- **THEN** its registered URL MUST use the `nextcloudapp://` prefix
- **AND** no webhook secret MUST be required for inbound dispatch to work
@e2e exclude Bot installation is an `occ talk:bot:install` lifecycle step verified by the live round-trip scenarios below; a Playwright test cannot observe the registered URL scheme.

#### Scenario: Uninstalling the bot stops all inbound dispatch

- **GIVEN** the Hermiq bot is installed and enabled in a room
- **WHEN** an operator uninstalls the bot through Talk
- **THEN** subsequent messages in that room MUST NOT produce an agent turn
- **AND** Hermiq MUST continue to operate normally in every other respect
@e2e exclude Requires `occ` bot lifecycle commands outside the browser session; covered by the live-verification task in tasks.md.

### Requirement: Listener registration is unconditional and availability is probed at invoke time

The system MUST register its `BotInvokeEvent` listener unconditionally at application
registration time. It MUST NOT gate that registration on a `class_exists()` check for a spreed
class, because a sibling app may not be loaded when Hermiq registers, so such a check can
return false on a healthy instance and silently disable the feature. Talk availability MUST
instead be probed when the listener is invoked, using `IBroker::hasBackend()` with spreed
classes resolved lazily through the container, mirroring `DeliveryService`.

#### Scenario: Hermiq boots with Talk absent

- **GIVEN** an instance where spreed is not installed
- **WHEN** Hermiq boots
- **THEN** it MUST construct and operate normally
- **AND** no error MUST be raised by the bridge's registration
@e2e exclude Boot-time behaviour on a Talk-less instance; covered by unit tests plus a documented manual check.

#### Scenario: Registration does not depend on spreed class availability

- **WHEN** the listener is registered during application registration
- **THEN** registration MUST NOT consult `class_exists()` for any spreed class
@e2e exclude Static structural property of the registration code, asserted by unit test.

### Requirement: The bot listener never runs an agent turn inline

The system MUST NOT call the agent engine from within the `BotInvokeEvent` listener, because
spreed invokes bots from a synchronous listener inside the message sender's request. The
listener MUST restrict itself to resolving the conversation, deciding whether the agent is
addressed, acknowledging receipt, and handing the turn off for out-of-request execution. The
agent turn MUST execute outside the originating request.

The listener MUST NOT persist the user turn itself: the engine already writes it, so a listener
that also wrote it would double-store every message. Authorship is therefore threaded THROUGH
the engine to that single writer rather than recorded ahead of it.

#### Scenario: An inbound message returns promptly

- **GIVEN** the bot is enabled in a room and an agent is bound to it
- **WHEN** a participant sends a message that addresses the agent
- **THEN** the listener MUST return without invoking the engine
- **AND** the turn MUST be handed off for out-of-request execution
@e2e exclude Asserted by unit test on the listener (engine collaborator must not be called); the user-visible outcome is covered by the live round-trip scenario.

#### Scenario: Receipt is acknowledged immediately

- **WHEN** the listener accepts an inbound message as a turn
- **THEN** it MUST acknowledge receipt with a reaction on that message before returning
@e2e exclude Reaction acknowledgement is verified in the live Talk round-trip task; Playwright does not drive the Talk mobile/web client in this suite.

### Requirement: Turn hand-off is event-driven when possible and queued otherwise

The system MUST enqueue every turn as a durable background job, and MUST additionally nudge a
registered `ITriggerableProvider` within the originating request so that a runner able to pull
the turn does so without waiting for a background-job tick. The queued job MUST remain the
durable record on both paths, so a turn can never be lost between the two mechanisms. Both paths
MUST execute the turn through the same turn service, so that turn behaviour cannot diverge
between them. The choice of path MUST NOT change any user-visible behaviour other than latency.

Note the deliberate limit of what ships: no triggerable runner for Hermiq turns exists yet, so
in practice every turn currently takes the queued path. The nudge is the seam that activates
when such a runner ships; it is NOT a latency improvement that is already realised. An
`ISynchronousProvider` does not qualify — core runs those on the same cron tick as the fallback.

#### Scenario: A registered triggerable runner is nudged in-request

- **GIVEN** a triggerable provider is registered
- **WHEN** an addressed message is handed off
- **THEN** that provider MUST be nudged before the listener returns
- **AND** the turn MUST still be durably enqueued
@e2e exclude Requires a registered triggerable provider, which does not yet exist for Hermiq turns; asserted by unit test on the hand-off selector.

#### Scenario: No triggerable runner falls back to the queue

- **GIVEN** no triggerable hermiq provider is registered
- **WHEN** an addressed message is handed off
- **THEN** the turn MUST be enqueued as a background job
- **AND** the answer MUST still be posted to the originating room
@e2e Live Talk round-trip on an instance with no triggerable provider, asserting the answer still arrives.

#### Scenario: The turn runs as the speaker, not as nobody

- **GIVEN** a turn executing outside any HTTP request
- **WHEN** the turn writes its message objects
- **THEN** the system MUST act as the speaking user
- **AND** MUST restore the prior identity afterwards, whatever the outcome
@e2e Live: covered by the round-trip — without impersonation every write is attributed to "Anonymous" and refused by OpenRegister RBAC before the model is reached.

#### Scenario: A failed turn does not leave the room silent

- **GIVEN** a turn that fails during execution
- **WHEN** the failure is reported on either hand-off path
- **THEN** the system MUST NOT leave the acknowledging reaction as the only signal
@e2e exclude Requires an injected turn failure; asserted by unit tests covering the task-failure event and the queued-job error path.

### Requirement: A room message becomes a turn on the bound session and is answered in the room

The system MUST resolve an inbound message's room token to the `Conversation` bound to that
room by filtering on the top-level `talkRoomToken` property. When no conversation is bound and
the agent is addressed, the system MUST open a new conversation and bind it to the room. The
agent's answer MUST be posted back into the originating room. When more than one conversation
is bound to the same room token, the system MUST resolve deterministically to the most recent
binding rather than assuming a single result.

#### Scenario: A reply continues the bound session

- **GIVEN** a `Conversation` bound to a room via `talkRoomToken`, carrying prior history
- **WHEN** a participant sends an addressed message in that room
- **THEN** the turn MUST be appended to that same conversation
- **AND** the answer MUST be posted back into that room
@e2e Live Talk round-trip: send a message in a bound room and assert the agent's answer appears in the same room and the turn is appended to the bound conversation.

#### Scenario: First contact opens and binds a session

- **GIVEN** a room with the bot enabled and no conversation bound to it
- **WHEN** a participant addresses the agent
- **THEN** a new conversation MUST be created and bound to that room's token
- **AND** subsequent messages in the room MUST resolve to that same conversation
@e2e Live Talk round-trip: first message in an unbound room, then a second message, asserting both land on one conversation.

#### Scenario: Duplicate bindings resolve deterministically

- **GIVEN** two conversations carrying the same `talkRoomToken`
- **WHEN** a message arrives in that room
- **THEN** the system MUST resolve to the most recently bound conversation
- **AND** MUST NOT fail or select arbitrarily
@e2e exclude Requires deliberately corrupt state that cannot be produced through the UI; covered by a unit test on the resolver.

### Requirement: The agent responds only when addressed in a group room

The system MUST take a turn on every inbound human message in a room Hermiq created for the
session, and in a one-to-one conversation with the bot. In any other group conversation the system
MUST take a turn only when the agent is `@`-mentioned by name or when the inbound message is a
reply to one of the agent's own messages, so that the agent does not answer unrelated conversation
in a shared room — which is precisely the case scheduled reports are delivered into.

Whether Hermiq created the room MUST be read from what the session recorded at creation time, and
MUST NOT be inferred from the room's current participants or type, so that inviting somebody into
an agent's own room cannot silently change whether the agent answers.

Because Talk does not offer bots as a source in its collaborator search, `@` does not autocomplete
a bot name and the mention arrives as literal typed text. The mention match MUST therefore be made
against the agent's own registered name on the decoded message text, and MUST tolerate multi-word
names, differences of case, and punctuation immediately following the name. A non-match MUST
result in no turn and MUST NOT raise an error.

#### Scenario: Every message is a turn in the session's own room

- **GIVEN** a room Hermiq created for a session
- **WHEN** a participant sends a message that neither mentions the agent nor replies to it
- **THEN** a turn MUST be taken and the answer posted to that room
@e2e exclude Requires driving Talk's own client to send a room message; covered by the live round-trip task in tasks.md.

#### Scenario: An unaddressed group message is ignored in a room Hermiq did not create

- **GIVEN** a group room the agent was invited into, with the bot enabled
- **WHEN** a participant sends a message that neither mentions the agent nor replies to it
- **THEN** no turn MUST be taken and no answer MUST be posted
@e2e exclude Requires driving Talk's own client in a team room; covered by the live round-trip task in tasks.md.

#### Scenario: A mention by agent name is answered

- **GIVEN** a group room the agent was invited into, with the bot enabled
- **WHEN** a participant types the agent's own name after an `@`
- **THEN** a turn MUST be taken and the answer posted to the room
@e2e exclude Requires driving Talk's own client; covered by the live round-trip task in tasks.md.

#### Scenario: A multi-word name with trailing punctuation still matches

- **GIVEN** an agent whose name is more than one word
- **WHEN** a participant types that name after an `@` in a different case and follows it with a comma
- **THEN** the message MUST be treated as addressing the agent
@e2e exclude Text-matching detail with several variants; asserted by unit tests on the matcher.

#### Scenario: Every message is a turn in a one-to-one room

- **GIVEN** a one-to-one room between a user and the bot
- **WHEN** the user sends a message without a mention
- **THEN** a turn MUST be taken
@e2e exclude Requires a one-to-one Talk room driven through Talk's client; covered by the live round-trip task in tasks.md.

#### Scenario: A reply to the agent is answered wherever the room came from

- **GIVEN** a group room the agent was invited into
- **WHEN** a participant replies to one of the agent's own messages without naming it
- **THEN** a turn MUST be taken
@e2e exclude Reply threading is a Talk client interaction; asserted by unit test on the addressing rule and confirmed in the live round-trip task.

### Requirement: The integration is opt-in on both sides

The system MUST require an explicit per-agent opt-in before an agent can be reached through
Talk, in addition to Talk's own per-room bot enablement. Neither switch alone MUST activate the
bridge. Both MUST default to off, so installing this change changes no behaviour until an
operator deliberately enables it.

#### Scenario: A non-opted-in agent is not reachable

- **GIVEN** the bot is enabled in a room bound to an agent that is not Talk-enabled in Hermiq
- **WHEN** a participant addresses the agent
- **THEN** no turn MUST be taken
@e2e Live: disable the agent's Talk opt-in, address it in a bound room, assert no reply.

#### Scenario: Defaults leave the bridge inert

- **WHEN** the change is installed and no agent is opted in and no room has the bot enabled
- **THEN** no Hermiq behaviour MUST differ from before the change
@e2e exclude Absence-of-behaviour across the whole app; covered by the unit suite plus the pre/post live smoke check in tasks.md.

### Requirement: Administrators can see the bridge's configuration

The system MUST surface, in admin settings, whether the Hermiq Talk bot is installed and which
conversations it is enabled in, so that an administrator can answer why an agent is responding
in a given room without inspecting the database.

#### Scenario: Admin sees installed bot and enabled rooms

- **GIVEN** the bot is installed and enabled in at least one room
- **WHEN** an administrator opens Hermiq's admin settings
- **THEN** the bot's installed state and the list of rooms it is enabled in MUST be shown
@e2e Playwright: open Hermiq admin settings as an administrator and assert the bot state and enabled-room list render.

#### Scenario: Admin settings render with Talk absent

- **GIVEN** an instance without spreed
- **WHEN** an administrator opens Hermiq's admin settings
- **THEN** the page MUST render without error and MUST indicate Talk is unavailable
@e2e exclude Requires a Talk-less instance the shared e2e environment does not provide; covered by a unit test on the settings controller and a documented manual check.

## User Stories

- As a team member, I want to ask our triage agent a follow-up straight from Talk on my phone, so that I do not have to open a browser to understand a report I just received.
- As a schedule owner, I want the report my agent posts to be the start of a conversation rather than a dead end, so that context from the run is not lost.
- As an administrator, I want the integration off until I turn it on, and removable from Talk alone, so that installing Hermiq does not change how Talk behaves.

## Acceptance Criteria

- [ ] An opted-in agent can be conversed with from Talk's web and mobile clients with no client changes.
- [ ] A reply in a room where a report was delivered continues that run's session with its history.
- [ ] Both opt-ins are off by default, and uninstalling the bot in Talk stops all inbound dispatch.
- [ ] With Talk absent, boot, delivery and settings are unchanged.
- [ ] The sender's message send is never held open by an agent turn, and receipt is acknowledged promptly.

## Notes

- **ADR-005** (delivery via Nextcloud Talk) — the decision this feature completes; its two-way
  channel and reply path were the stated justification for choosing Talk over a multi-platform gateway.
- **ADR-023** — Rule 1 keeps data RBAC in OpenRegister; the bridge's participant check is an
  action-level guard layered on top, not a replacement.
- **ADR-031** — the inbound listener and out-of-request turn are named imperative exceptions
  (external integration, queued/triggered work); the binding, roster and authorship are declarative data.
- Related features: `talk-delivery` (the outbound half), `talk-shared-sessions` (multi-participant),
  `talk-room-grouping` (sidebar grouping of agent rooms).
- Reply latency depends on the hand-off path: seconds where a triggerable runner is registered,
  otherwise bounded by the instance's background-job cadence. Not a property of Talk.
- Deliberately deferred: streaming into Talk, Talk threads as sessions, approvals via reaction,
  calls/voice, federation, and bridging to non-Nextcloud chat platforms (rejected by ADR-005).
