---
kind: code
depends_on: [talk-chat-bridge-schema]
---

# Proposal: talk-chat-bridge

## Summary

Make Nextcloud Talk an **optional, bidirectional** chat surface for Hermiq agent sessions.
Hermiq registers itself as an in-process Talk bot (`nextcloudapp://hermiq`); when that bot is
enabled in a conversation, messages in the room become turns on a Hermiq session and the
agent's answers are posted back into the room. Because Talk bot messages are server-side, an
agent becomes reachable from the Talk **iOS and Android apps** with zero mobile-client work.
The same wiring closes the loop on scheduled runs: a cron report already lands in a Talk room
(`talk-delivery`), and after this change a reply in that room continues **that** session with
its full history. Sessions also become multi-participant, so a team can work with one agent in
one room and the transcript records who said what. Everything is additive — with spreed absent
or the bot not enabled, Hermiq behaves exactly as it does today.

## Motivation

ADR-005 chose Nextcloud Talk over Hermes' 22-platform gateway partly because "Talk gives a
real two-way channel and a reply path". Only the outbound half was built. Today a scheduled
agent posts its report into a room and the conversation dead-ends: the obvious next move —
asking a follow-up question — has nowhere to go, and the user must leave Talk, open Hermiq in a
browser, find the session and type there. On a phone that is effectively a dead end.

Three things make this the right moment:

- **The session already exists.** A scheduled run materialises a real `Conversation`
  (`ScheduleService:2489`) and runs `Engine::processMessage` against it. Nothing needs to be
  invented to have something to reply to — only a binding, which `talk-chat-bridge-schema`
  adds.
- **The async machinery already exists.** `WebhookAgentRunJob` / `AgentRunRequestedJob` already
  run agent turns off-request as `QueuedJob`s delegating to a service. The bridge needs exactly
  that shape, and needs it badly (see Risk 1).
- **The engine is already session-free.** `Engine::processMessage(conversationId, userId, …)`
  takes an explicit `userId` and has no `IUserSession` dependency, so it runs unchanged from a
  background job.

The multi-participant half is motivated by the same scenario: the rooms these reports land in
are team rooms. A report delivered to a shared room that only its owner may answer is a
confusing half-feature.

## Affected Projects

- [ ] Project: `hermiq` — new Talk bot registration + `BotInvokeEvent` listener, a queued
  turn job and its service, a room↔conversation resolver, participant-aware authorization in
  `Engine` and `ChatController`, per-message authorship capture, mention gating, a
  `DeliveryService` back-reference write, and a per-agent opt-in plus admin bot visibility in
  the settings UI.

## Scope

### In Scope

- **Bot registration.** Register Hermiq as a Talk bot with the `nextcloudapp://hermiq` URL so
  spreed dispatches `BotInvokeEvent` in-process — no webhook, no shared secret, no egress.
  Install/uninstall handled through Talk's own bot lifecycle, with a clean removal path.
- **Inbound bridge.** Listen for `BotInvokeEvent`, resolve the room token to a bound
  `Conversation` (or open one on first contact), acknowledge immediately with a reaction, and
  hand the turn off. Never call the engine inline (Risk 1).
- **Hybrid hand-off.** Schedule the turn through `OCP\TaskProcessing` when a triggerable hermiq
  runner is registered — core nudges it in-request and `TaskSuccessfulEvent` / `TaskFailedEvent`
  drives the reply — and fall back to a `QueuedJob` when none is. Both paths converge on one
  turn service.
- **Answer delivery.** Post the agent's answer back into the originating room from the
  background job via Talk's bot message endpoint.
- **Resumable reports.** `DeliveryService` records `talkRoomToken` on the `Conversation` it
  delivered output for, so a reply in that room continues that session — covering cron
  schedules, event/flow triggers and webhook-triggered runs.
- **Multi-participant sessions.** Relax the owner checks in `Engine` (line 274) and
  `ChatController` from *owner* to *owner-or-participant*; capture `authorId` +
  `authorDisplayName` on each user turn; carry the author into the history handed to the model
  so it can tell speakers apart.
- **Acting-user scoping.** Each turn resolves context files and credentials as the **speaker**,
  not the conversation owner, using the `actingUserId` axis that `ContextAssembler` and
  `CredentialScopeResolver` already take.
- **Mention gating.** In a group room the agent answers only when `@`-mentioned or when the
  message replies to one of its own; in a one-to-one room with the bot it answers everything.
- **Optionality.** Per-agent opt-in, admin visibility of the installed bot and which rooms it
  is enabled in, and a no-op-when-spreed-is-absent guarantee.

### Out of Scope

- **Streaming into Talk.** Bots post whole messages; there is no Talk equivalent of
  `ChatStreamController`. The reaction ack stands in for a typing indicator.
- **Talk threads as sessions.** Session mapping is room = session. Thread-scoped sessions are a
  deliberate follow-up, even though spreed's bot API already carries `threadId`/`threadTitle`.
- **Approvals via reaction.** `BotService::afterReactionAdded` already invokes bots, so
  resolving the `human-approval-gate` from a 👍 on a phone is attractive — and is its own
  change.
- **Calls, voice, and federation.**
- **Bridging to non-Nextcloud chat platforms.** Explicitly rejected by ADR-005.
- **Backfilling existing conversations** with a room binding or participant roster.

## Approach

Hermiq registers a bot whose URL uses spreed's `nextcloudapp://` scheme. For that scheme spreed
skips HTTP and HMAC entirely and dispatches `BotInvokeEvent` in-process, so the bridge is a
plain event listener rather than a webhook endpoint — nothing is exposed to the network.

The listener does as little as possible, because `BotService::afterChatMessageSent` is a
**synchronous** listener in the sender's request. It resolves the room to a conversation,
decides whether the agent is being addressed, adds an acknowledging reaction via
`$event->addReaction()`, writes the user turn, and hands the turn off.

Hand-off is event-driven where it can be. Nextcloud's `IEventDispatcher` is synchronous and so
cannot carry work out of the request, but `OCP\TaskProcessing` can: core's `Manager::scheduleTask()`
nudges an `ITriggerableProvider` **in-request**, the runner pulls the task immediately, and
`TaskSuccessfulEvent` / `TaskFailedEvent` fire on completion. Where no triggerable runner is
registered the bridge falls back to a `QueuedJob` mirroring `WebhookAgentRunJob`. Both paths call
`Engine::processMessage` with the **speaker** as `userId` through the same turn service, and post
the result back into the room.

Availability is probed exactly the way `DeliveryService` already does it: `IBroker::hasBackend()`
plus lazy resolution of spreed classes through the container. Notably the listener registration
itself is unconditional — a `class_exists()` guard at `register()` time would silently disable
the feature on a healthy instance, because a sibling app may not be loaded yet when Hermiq
registers.

Authorization moves from *owner* to *owner-or-participant* in the two places that enforce it,
and each turn keeps the speaker as its acting identity, so participant A can never make the
agent read participant B's files.

## New Dependencies

None. Talk (spreed) is an **optional runtime** dependency that Hermiq already declares and
probes for `talk-delivery`; this change adds no package, no library and no external service.
No new outbound network surface is introduced — the `nextcloudapp://` bot scheme is in-process.

## Impact

- **New:** a bot-registration listener, a `BotInvokeEvent` listener, a `TalkTurnJob`
  (`QueuedJob`) and its service, and a room↔conversation resolver.
- **Modified:** `Engine` (owner check at line 274 → participant check; author carried into
  history), `ChatController` (ownership guards → participant guards), `DeliveryService`
  (records `talkRoomToken` on the delivered-for conversation), the agent settings UI (per-agent
  opt-in), admin settings (bot visibility).
- **Data:** writes the four properties added by `talk-chat-bridge-schema`. No object backfill.
- **Security:** relaxes two authorization guards. This is the highest-risk part of the change
  and is treated as such in design.md and the test plan.
- **Behaviour when Talk is absent:** unchanged in every respect.

## Cross-Project Dependencies

- **spreed (Nextcloud Talk)** — consumed, not modified. This change depends on the stability of
  spreed's bot interface: the `nextcloudapp://` URL scheme, `BotInvokeEvent`
  (`getMessage()` / `addAnswer()` / `addReaction()`), the bot message OCS endpoint, and the
  `occ talk:bot:*` lifecycle commands. Pinned in contract.md.
- **OpenRegister** — consumed for `Conversation`/`Message` persistence and data-layer RBAC
  (ADR-023 Rule 1), as today.
- **`talk-chat-bridge-schema`** — must ship first; the resolver filters on `talkRoomToken`.

## Risks

### Risk 1: Calling the engine inside the bot listener stalls Talk for every sender

**Severity:** High — **Mitigation:** `BotService::afterChatMessageSent` is a plain synchronous
listener in the message-sender's request (spreed `Application.php:216`); the HTTP-webhook
variant caps at a 5s timeout. An LLM turn is 5–60s, so an inline call would hang the sender's
message send and, at the timeout, produce a half-broken send path. The listener therefore does
only cheap work and enqueues, mirroring `WebhookAgentRunJob`. A test must assert the listener
never reaches the engine.

### Risk 2: Relaxing the owner guards opens sessions wider than intended

**Severity:** High — **Mitigation:** two guards change at once (`Engine:274` and
`ChatController`), and getting either wrong is a cross-tenant data leak. The relaxation is
strictly *owner → owner-or-participant*, never "any authenticated user"; the roster is an
explicit list, never derived implicitly from room membership at read time; and data-layer RBAC
stays OpenRegister's job per ADR-023 Rule 1, with the participant check as defense-in-depth on
top. Both guards get direct negative tests (a non-participant is refused at both layers).

### Risk 3: Turn latency is bounded by the hand-off path, not by the model

**Severity:** Medium — **Mitigation:** a plain `QueuedJob` runs on the next cron tick, up to
five minutes away on a default Nextcloud, and a chat reply that arrives five minutes later reads
as broken even though every component worked. The bridge therefore hands off through
`OCP\TaskProcessing` when a **triggerable** hermiq runner is registered — core nudges it
in-request and `TaskSuccessfulEvent` drives the reply, giving seconds — and falls back to the
queued job when none is. The reaction ack makes the wait legible on either path, and the cadence
requirement is documented for queue-only deployments. Reduced from High by the triggered path;
not eliminated, because the fallback retains the original bound.

### Risk 4: The room→conversation resolve silently returns nothing

**Severity:** Medium — **Mitigation:** `talkRoomToken` is a top-level filterable property
precisely because a nested `metadata` lookup would return zero rows silently and open a blank
session on every message — green-but-dead, and invisible to unit tests using in-memory doubles.
Verification must include a live round-trip on a real instance, not only mocked resolves.

### Risk 5: The agent answers everything in a busy team room

**Severity:** Medium — **Mitigation:** mention gating. In a group room the agent replies only
when `@`-mentioned or when a message replies to one of its own; one-to-one rooms with the bot
are unrestricted. Without this the feature is actively unpleasant in exactly the rooms reports
are delivered to.

### Risk 6: Prompt injection arrives from a room participant

**Severity:** Medium — **Mitigation:** any room participant can now put text in front of an
agent that holds tools and credentials. Existing guardrail and approval machinery still applies
unchanged, and per-speaker credential scoping bounds the blast radius, but the exposure is
genuinely wider than the web UI's. Stated plainly rather than mitigated away.

### Risk 7: Two conversations claim the same room

**Severity:** Low — **Mitigation:** the register cannot express uniqueness on `talkRoomToken`,
so the resolver must be deterministic (most recent binding wins) and must not assume a single
row.

### Risk 8: A captured display name goes stale

**Severity:** Low — **Mitigation:** intended behaviour — a transcript is an audit record
(ADR-004) and should read as it did at the time. Documented in the schema change's property
description so it is not mistaken for a bug.

## Rollback Strategy

Uninstall the bot (`occ talk:bot:uninstall`), which stops all inbound dispatch instantly and
independently of any code rollback — spreed simply stops invoking Hermiq. Disabling the bot in a
single room scopes that to one room. Reverting the code restores today's behaviour completely:
the four schema properties are optional, so objects written while the feature was live remain
valid and are simply ignored; no data migration is needed in either direction. The only
non-reverting effect is the relaxed guards, which return to owner-only on revert — a session
shared with a participant becomes owner-only again rather than breaking.

## Open Questions

- **Should a room's Talk participants auto-populate the roster?** Provisionally no — the roster
  is explicit, and room membership is checked separately at turn time. Auto-population is
  friendlier but makes "who can use this agent" change silently when someone is added to a room.
- **Does a triggerable runner already exist to register, or must one be built?** The hybrid
  hand-off degrades safely either way — with no triggerable provider registered every turn takes
  the queued path — but the low-latency half of the design only materialises once such a runner
  ships. Whether that is an extension of the existing `exapp/llm-runner` (today a synchronous
  `POST /run` transport, not a task worker) or a separate provider is deliberately left open here.
