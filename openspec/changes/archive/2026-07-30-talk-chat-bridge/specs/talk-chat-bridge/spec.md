# talk-chat-bridge (delta)

The inbound half of ADR-005's two-way Talk channel: Hermiq registers as an in-process Talk
bot, turns room messages into agent turns, and posts answers back — so an agent is reachable
from the Talk mobile apps with no mobile-client work.

## ADDED Requirements

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

In a one-to-one conversation with the bot, the system MUST treat every inbound message as a
turn. In a group conversation, the system MUST take a turn only when the agent is `@`-mentioned
or when the inbound message is a reply to one of the agent's own messages, so that the agent
does not answer unrelated conversation in a shared room.

#### Scenario: An unaddressed group message is ignored

- **GIVEN** a group room with the bot enabled
- **WHEN** a participant sends a message that neither mentions the agent nor replies to it
- **THEN** no turn MUST be taken and no answer MUST be posted
@e2e Live Talk round-trip: post an unaddressed message in a group room and assert no agent reply appears.

#### Scenario: A mention is answered

- **GIVEN** a group room with the bot enabled
- **WHEN** a participant `@`-mentions the agent
- **THEN** a turn MUST be taken and the answer posted to the room
@e2e Live Talk round-trip: mention the bot in a group room and assert a reply arrives.

#### Scenario: Every message is a turn in a one-to-one room

- **GIVEN** a one-to-one room between a user and the bot
- **WHEN** the user sends a message without a mention
- **THEN** a turn MUST be taken
@e2e Live Talk round-trip in a one-to-one room asserting an unmentioned message is answered.

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
