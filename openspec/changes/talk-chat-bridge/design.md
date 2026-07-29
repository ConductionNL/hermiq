# Design: talk-chat-bridge

## Context

`talk-delivery` implemented the outbound half of ADR-005: `DeliveryService` posts a run's
output to a Talk room, Note-to-self, or a notification. The reply path ADR-005 also promised
was never built, so a delivered report is a dead end — especially on a phone, where leaving
Talk to open a browser is the end of the interaction.

This change builds the inbound half. The relevant existing surfaces:

| Surface | Fact that shapes this design |
|---|---|
| `BotService::afterChatMessageSent` (spreed) | Plain **synchronous** listener registered at spreed `Application.php:216`; the HTTP-webhook variant caps at a 5s timeout |
| `Bot::URL_APP_PREFIX` = `nextcloudapp://` | For this scheme spreed dispatches `BotInvokeEvent` **in-process** (`BotService::invokeBots`) — no HTTP, no HMAC, no egress |
| `BotInvokeEvent` | `getMessage()` (ActivityPub-shaped), `addAnswer(...)`, `addReaction(...)` |
| `Engine::processMessage(conversationId, userId, …)` | Explicit `userId`, **no `IUserSession`** — already background-job-safe |
| `Engine.php:274` | `if (($conversationData['userId'] ?? null) !== $userId)` — a hard **owner** check inside the engine, beyond `ChatController`'s guards |
| `ContextAssembler::assembleForAgent(agent, actingUserId:)` | Resolves attached context files via `getUserFolder($actingUserId)` |
| `CredentialScopeResolver::resolve(provider, actingUserId, organisation)` | Scopes credentials per acting user |
| `ScheduleService:2489` | A scheduled run **already materialises a real `Conversation`** |
| `WebhookAgentRunJob` / `AgentRunRequestedJob` | `QueuedJob` wrappers delegating to a service — the established async-turn pattern |
| `DeliveryService` | Probes Talk via `IBroker::hasBackend()` and resolves spreed classes lazily through the container |

The decisive one is the first: the bridge's entry point runs inside the *sender's* HTTP
request. Everything below follows from not being allowed to block there.

## Goals / Non-Goals

**Goals**

- An agent reachable from the Talk mobile apps with **no mobile-client work**.
- A delivered report that can be **replied to**, continuing the same session with its history.
- Sessions shared by a team, with a transcript that records who said what.
- Zero behaviour change when spreed is absent or the bot is not enabled.

**Non-Goals**

- Streaming, Talk threads as sessions, approvals-via-reaction, calls/voice, federation,
  non-Nextcloud chat platforms (ADR-005), and backfilling existing conversations.

## Decisions

### D1: In-process bot (`nextcloudapp://hermiq`), not a webhook bot

spreed supports both. The webhook form requires a reachable URL, a shared secret, HMAC
verification, and outbound HTTP from spreed to Hermiq — on the same instance. The
`nextcloudapp://` form makes spreed dispatch `BotInvokeEvent` in-process instead. Chosen
because it removes the network surface, the secret to manage and rotate, and the 5s HTTP
timeout, and because it makes the bridge a plain listener that unit tests can dispatch
directly. The trade-off is that Hermiq must be installed on the same instance as Talk, which is
already true by construction.

### D2: The listener hands the turn off; it never calls the engine

The listener's whole job is cheap work: resolve the room, decide whether the agent is
addressed, write the user turn, add a reaction, hand off. This is not a preference; calling
`Engine::processMessage` inline would hold the sender's message-send request open for the
length of an LLM call. A test asserts the listener never reaches the engine, because this is
the kind of thing a later refactor quietly undoes.

### D2b: Hand-off is event-driven when a triggerable runner exists, queued when it does not

"Make it event-driven instead of queue-driven" is the right instinct, but a plain
`IEventDispatcher` cannot deliver it: NC event dispatch is **synchronous**, so listeners run
inside the same request — swapping the queue for an event would put the LLM call straight back
into the Talk sender's request, the exact failure D2 exists to prevent.

Nextcloud's genuinely asynchronous seam is `OCP\TaskProcessing`. From core's
`Manager::scheduleTask()`:

| Provider kind | What core does on schedule | Latency |
|---|---|---|
| `ISynchronousProvider` | `jobList->add(SynchronousBackgroundJob::class)` | Next cron tick |
| `ITriggerableProvider` | Calls `$provider->trigger()` **in-request** — a cheap nudge; the worker then pulls the task out-of-band | Seconds |

Completion dispatches `TaskSuccessfulEvent` / `TaskFailedEvent`, so the answer path is a real
event listener rather than a poll.

Note that TaskProcessing alone is **not** a latency fix: hermiq's existing providers
(`AbstractTextProvider`, `ContextAgentProvider`) are all `ISynchronousProvider`, which core runs
via `SynchronousBackgroundJob extends QueuedJob` — the same cron tick, with more indirection.
The win comes specifically from a **triggerable** runner.

So the bridge selects at hand-off time:

- **A triggerable hermiq runner is registered** → schedule a TaskProcessing task. Core nudges
  the runner in-request, it pulls the turn immediately, and `TaskSuccessfulEvent` /
  `TaskFailedEvent` drives posting the answer into the room. Seconds.
- **Otherwise** → `TalkTurnJob extends QueuedJob` delegating to `TalkTurnService`, the exact
  shape of `WebhookAgentRunJob`. Bounded by the instance's background-job cadence.

Both paths converge on the same `TalkTurnService`, so the turn logic exists once and only the
hand-off and completion differ. The fallback is what keeps the feature strictly additive: a
deployment with no sidecar still works, just more slowly, and the acknowledging reaction makes
the difference legible rather than mysterious.

**Residual cost:** two hand-off paths to build and test, and on a queue-only deployment the
original latency concern stands — mitigated by documenting the cron-cadence requirement rather
than solved.

### D3: Availability probe mirrors `DeliveryService`; registration is unconditional

Talk availability is checked with `IBroker::hasBackend()` and spreed classes are resolved
lazily through the container, exactly as `DeliveryService` does — so Hermiq constructs and
boots cleanly with no Talk installed.

The listener registration in `Application::register()` is **unconditional**. Guarding it with
`class_exists('OCA\\Talk\\…')` is the obvious-looking move and is wrong: at `register()` time a
sibling app may not be loaded yet, so the check returns false on a perfectly healthy instance
and the feature is silently disabled with nothing in the logs. Registration is cheap and
`addServiceListener` is lazy, so the guard buys nothing and costs the feature. The availability
check belongs at *invoke* time, inside the listener.

### D4: Room = session, resolved by a top-level filterable property

One Talk room maps to one long-lived `Conversation`, resolved by filtering on
`Conversation.talkRoomToken`. The binding is written by `DeliveryService` when it delivers into
a room, and by the bridge when a room's first message opens a session.

The property is top-level rather than inside `metadata` because the resolve is a filter query
and OpenRegister's dot-path filters on nested JSON match nothing — a `metadata.talkRoomToken`
lookup would return zero rows *silently*, opening a blank session on every message. That failure
is invisible to unit tests with in-memory doubles, which is why verification requires a live
round-trip.

Since the register cannot express uniqueness, the resolver treats multiple bindings as possible
and picks deterministically (most recent wins) rather than assuming one row.

### D5: Authorization relaxes from *owner* to *owner-or-participant*, at both layers

`Engine:274` and `ChatController`'s guards both change. The rules:

- Permitted = `userId` owner **or** a uid listed in `participants`. Never "any authenticated
  user", and never derived implicitly from live room membership at read time — an explicit
  roster is auditable and does not change silently when someone is added to a room.
- Both layers keep their check. The engine's guard is defense-in-depth behind the controller's,
  and the bridge is a *third* entry point that bypasses the controller entirely — which is
  precisely why the engine-level check must not be removed while relaxing it.
- Per ADR-023 Rule 1, data-layer authorization remains OpenRegister's job. The participant check
  is not a re-implementation of object RBAC; it is an action-level guard on *taking a turn in
  this session*, layered on top.

Both guards get direct negative tests. This is the highest-risk edit in the change.

### D6: The speaker is the acting user; the owner is not

Each turn calls `Engine::processMessage` with the **speaker's** uid. Because
`ContextAssembler::assembleForAgent()` and `CredentialScopeResolver::resolve()` already take an
`actingUserId`, this means context files and credentials resolve as the person who typed —
participant A cannot make the agent read participant B's Files, and each speaker's own
credentials are used for their own turns.

The consequence must be stated plainly rather than discovered: **the same agent in the same room
can behave differently depending on who speaks**, because attached context files resolve against
different user folders. That is the correct security posture and a surprising user-visible
behaviour at the same time.

This composes with, and does not replace, `agent-capability-profile`'s `actingUser`
impersonation: when an agent declares an `actingUser`, that identity governs the run as it does
today; the speaker remains the *author* of the turn either way.

### D7: Authorship is captured per turn and carried into the model's history

Each user turn stores `authorId` and `authorDisplayName` (captured at send time, per the schema
change). The history handed to the model labels human turns with the display name, so in a
shared room the model can distinguish two people rather than seeing one undifferentiated `user`
voice. Assistant, system and tool turns carry no author.

### D8: Mention gating by room type

In a one-to-one room with the bot, every message is a turn. In a group room, the agent responds
only when `@`-mentioned, or when the message is a reply to one of the agent's own messages.
Without this the agent answers every message in a team room — and team rooms are exactly where
reports are delivered, so the un-gated version would be actively unpleasant in the feature's
main use case.

### D9: Opt-in is per agent, plus Talk's own per-room enablement

Two independent switches, both off by default: an agent must be marked Talk-enabled in Hermiq,
and the bot must be enabled in the room by a Talk moderator. Neither alone activates anything.
Admin settings surface which bot is installed and which rooms it is enabled in, so "why is this
agent answering here" is answerable without database access.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Inbound Talk message → agent turn | **Imperative** | External-integration seam. Consuming spreed's `BotInvokeEvent` and posting via its bot API is integration glue with a third-party app's event contract — an explicit ADR-031 exception. Not expressible as a derived field. |
| Async turn execution | **Imperative** | Queued/triggered work via `QueuedJob` or `TaskProcessing`, following the existing `WebhookAgentRunJob` and TaskProcessing-provider precedents. |
| Room ↔ conversation binding | **Declarative (data)** | Plain persisted property on `Conversation` (`talkRoomToken`), declared in `talk-chat-bridge-schema`. No lifecycle or calculation. |
| Participant roster | **Declarative (data)** | Plain persisted array; the *check* is an action-level guard (ADR-023 Rule 2 territory), not a derived field. |
| Per-message authorship | **Declarative (data)** | Two persisted properties, written at turn time. |
| Per-agent Talk opt-in | **Declarative (data)** | A flag on the agent, read by the listener. |

No lifecycle, aggregation, calculation, notification, relation or widget behaviour is introduced,
so nothing here belongs in `x-openregister-*`. The imperative parts are integration glue and
queued work — both named ADR-031 exceptions.

## Seed Data (ADR-001)

No new schemas are introduced by this change; the shapes and their seeds are owned by
`talk-chat-bridge-schema` (a Talk-bound shared `Conversation`, one authored user `Message`, one
unauthored assistant `Message`). This change consumes those seeds and adds none of its own.

For live verification, the seeds needed are environmental rather than register data: a Talk room
with the Hermiq bot enabled, an agent marked Talk-enabled, and two NC users — the conversation
owner and a second participant — so the multi-participant and per-speaker-scoping paths can both
be exercised.

## Risks / Trade-offs

- **The listener is a synchronous choke point** → mitigated by D2; guarded by a test asserting
  the listener never reaches the engine. The risk is regression, not initial implementation.
- **Two authorization guards relax at once** → mitigated by D5's explicit roster and negative
  tests at both layers. Accepted as the highest-risk edit; called out for security review.
- **Two hand-off paths double the surface** → the triggered and queued paths must converge on one
  `TalkTurnService`, or the turn logic drifts between them and only one gets exercised in
  practice. Both paths need coverage; the queued one is the fallback nobody will remember to test.
- **Queue-only deployments keep the original latency problem** → D2b narrows it to deployments
  without a triggerable runner rather than eliminating it. Documented as a cadence requirement.
- **A silently-empty resolve looks like a working feature** → D4; only a live round-trip proves
  it, so mocked tests alone are not acceptable evidence here.
- **Prompt injection from any room participant** → the exposure is genuinely wider than the web
  UI's. Existing guardrails and approval gates still apply; per-speaker credential scoping bounds
  the blast radius. Stated, not solved.
- **Per-speaker scoping makes agent behaviour vary by speaker** → correct security posture (D6),
  surprising UX. Must be documented in user-facing docs, not just here.
- **Talk's bot interface is a third-party contract** → pinned in contract.md; a spreed major
  upgrade is the thing most likely to break this change.
