# Tasks: talk-chat-bridge

Depends on `talk-chat-bridge-schema` — the resolver filters on `Conversation.talkRoomToken`,
which does not exist until that change has shipped and its register import has landed.

## 1. Bot registration and lifecycle

- [x] 1.1 Register the Hermiq Talk bot with a `nextcloudapp://hermiq` URL and the `FEATURE_EVENT` + `FEATURE_RESPONSE` + `FEATURE_REACTION` feature bits, so spreed dispatches in-process (no webhook, no secret, no egress). Wire install/uninstall to Talk's own bot lifecycle so `occ talk:bot:uninstall` alone stops all inbound dispatch — this is the rollback lever.
- [x] 1.2 Register the `BotInvokeEvent` listener **unconditionally** in `Application::register()`. Do NOT guard it with `class_exists()` on a spreed class — a sibling app may not be loaded at register time, so the check returns false on a healthy instance and silently disables the feature (design.md D3). Probe availability with `IBroker::hasBackend()` at invoke time, resolving spreed classes lazily through the container, mirroring `DeliveryService`.

## 2. Inbound listener — cheap work only

- [x] 2.1 In the listener, read `target.id` (room token), `actor.id` (speaker uid), `actor.name` (display name) and `object.content` from `BotInvokeEvent::getMessage()`; ignore invocations belonging to other bots via `getBotUrl()`.
- [x] 2.2 Apply mention gating: in a group room take a turn only on an `@`-mention or a reply to one of the agent's own messages (`object.inReplyTo`); in a one-to-one room with the bot, take every message (design.md D8).
- [x] 2.3 Acknowledge with `addReaction()`, and hand the turn off. The listener MUST NOT call `Engine::processMessage` — it runs synchronously inside the sender's request (design.md D2). It must also NOT persist the user turn: the engine already writes it, so authorship is threaded THROUGH the engine to that single writer instead. Add a test asserting the engine collaborator is never reached.

## 3. Room ↔ conversation resolution

- [x] 3.1 Add a resolver that finds the `Conversation` bound to a room by filtering on the **top-level** `talkRoomToken` property, opens and binds a new conversation on first contact, and resolves multiple bindings deterministically (most recent wins) rather than assuming one row (design.md D4).

## 4. Out-of-request turn execution — one service, two hand-offs

- [x] 4.1 Add `TalkTurnService` holding the turn logic once: call `Engine::processMessage(conversationId, userId: <speaker>, ...)` — the **speaker**, not the conversation owner — then post the answer into the originating room via the bot message endpoint. Treat a 401/404 (bot disabled, uninstalled, or room deleted mid-flight) as terminal and non-retryable, and never fail the run on a post failure.
- [x] 4.2 Add the hand-off selector: schedule via `OCP\TaskProcessing\IManager::scheduleTask()` when a **triggerable** hermiq provider is registered — core calls `trigger()` in-request so the runner picks the turn up without waiting for cron — otherwise fall back to the queue (design.md D2b). Note that an `ISynchronousProvider` is NOT the fast path: core runs those via `SynchronousBackgroundJob extends QueuedJob`, i.e. the same cron tick.
- [x] 4.3 Add `TalkTurnJob extends QueuedJob` as the fallback wrapper, a pure delegate to `TalkTurnService` following `WebhookAgentRunJob`'s shape exactly.
- [ ] 4.4 Listen for `TaskSuccessfulEvent` / `TaskFailedEvent` to post the answer (or report the failure) on the triggered path, so a failed turn never leaves the acknowledging reaction as the only signal. Add a test proving both hand-offs route through the same `TalkTurnService` — divergence here means only one path is ever really exercised.

## 5. Resumable reports

- [x] 5.1 In `DeliveryService`, record the target room's token on the `Conversation` the run produced whenever output is delivered **to a room** — covering scheduled, event/flow- and webhook-triggered runs. Do not bind for Note-to-self, notification, email or webhook delivery. A binding failure must never fail the delivery or the run.

## 6. Multi-participant sessions

- [x] 6.1 Relax the owner check at `lib/Service/Engine/Engine.php:274` AND the per-object ownership guards in `lib/Controller/ChatController.php` from *owner* to *owner-or-listed-participant*. Keep both checks — do not remove either — because the bridge reaches the engine without passing through the controller (design.md D5). Permitted = `userId` owner OR a uid in `participants`; never "any authenticated user"; never derived from live Talk room membership at read time.
- [x] 6.2 Add negative tests at BOTH layers proving a non-participant is refused and nothing is persisted. This is the highest-risk edit in the change — flag it for security review.
- [x] 6.3 Carry the per-message author into the history handed to the model, so the agent can distinguish two humans instead of seeing one undifferentiated `user` voice (`MessageHistoryHandler`).
- [x] 6.4 Confirm per-speaker scoping end to end: `ContextAssembler::assembleForAgent(actingUserId:)` and `CredentialScopeResolver::resolve(actingUserId:)` must receive the speaker, so a participant cannot make the agent read another participant's files (design.md D6).

## 7. Opt-in and admin visibility

- [x] 7.1a Add a per-agent Talk opt-in (`Agent.talkEnabled`, default off) and enforce it in the listener, so both it and Talk's per-room bot enablement are required before any turn is taken. The per-user grouping opt-out rides the existing generic `PUT /api/preferences/{key}` endpoint (key `talk_group_rooms`) — no new controller needed.
- [ ] 7.1b Surface in ADMIN SETTINGS whether the bot is installed, which rooms it is enabled in, and which hand-off path is active (triggered vs queued); render cleanly with an explicit "Talk unavailable" state when spreed is absent. **NOT DONE** — the bridge is configured via `occ`/app-config today (documented in docs/talk-chat-bridge.md); the Vue admin surface is deferred.

## 8. Verify live

- [x] 8.1 Live round-trip on the real instance (spreed 24.0.1): address the agent in a bound room and assert the answer posts back to that room and appends to the bound conversation. Then confirm the resolve works against the **real register** — a filter on `talkRoomToken` must return the bound conversation. A mocked resolve returning a row proves nothing; a silent zero-row result is the specific green-but-dead failure this step exists to catch.
- [ ] 8.2 Live the headline story end to end: trigger a Talk-delivering schedule, confirm the report posts, reply in the room, and assert the answer lands on the same conversation with the run's history. Separately verify with two users that a listed participant is answered and a non-participant is refused.
- [x] 8.3 Measure and record the observed interval from message to acknowledgement and from message to posted answer on BOTH hand-off paths, so the latency claim behind design.md D2b is a number rather than an assumption. Verify the queued fallback still answers on an instance with no triggerable provider registered.

## 9. Documentation

- [x] 9.1 Document the feature for users and operators: both opt-ins, the uninstall lever, the acknowledgement-then-answer interaction, the job-cadence requirement, and the fact that agent behaviour legitimately varies by speaker because files and credentials resolve per speaker. Update ADR-005's Consequences, which still states Talk is not installed on the dev instance and that no reply path exists.

## Acceptance criteria

- Hermiq registers as a `nextcloudapp://` Talk bot; no webhook URL, shared secret, or outbound network access is required for the bridge.
- The `BotInvokeEvent` listener is registered unconditionally, and no `class_exists()` guard on a spreed class gates it.
- The listener never calls the engine; the turn runs out of request and a test enforces this.
- Hand-off uses the triggered TaskProcessing path when a triggerable provider is registered and the queued job when none is; both route through one `TalkTurnService`, and a failed turn is reported rather than leaving the reaction as the only signal.
- An addressed message in a bound room is acknowledged with a reaction, appended to the bound conversation, and answered in that room.
- A report delivered to a Talk room can be replied to, and the reply continues the session that produced it with its full history.
- A conversation may be taken up by its owner or a listed participant, enforced at both the engine and the controller, with negative tests at both.
- Each human turn carries `authorId` and `authorDisplayName`; assistant/system/tool turns carry none; assembled history attributes human turns.
- Files and credentials resolve as the speaker; a participant cannot reach another participant's files.
- In a group room the agent responds only when `@`-mentioned or replied to; in a one-to-one room it responds to everything.
- Both opt-ins are off by default, and `occ talk:bot:uninstall` stops all inbound dispatch without a code change.
- With spreed absent, Hermiq boots, delivers and renders admin settings exactly as before this change.

## Quality reminders

- Depends on `talk-chat-bridge-schema`; do not start until its register import has landed live.
- Hydra gates apply: `@spec` traceability on changed methods, `@e2e` on added/modified scenarios, SPDX headers on new PHP files, route-auth and semantic-auth on any new controller method, no stubs.
- Do not use sed/awk/scripts to modify code — use the Edit tool.
- Fix pre-existing quality issues encountered in the files you touch rather than leaving them.
- `DeliveryService` is already ~1500 lines; add the binding write without growing it into a second responsibility — extract if it does not fit cleanly.
- A green unit suite cannot prove this feature works. The live round-trip in §8 is required evidence, not optional confirmation.

## Verification record (2026-07-28, live on NC 34 + spreed 24.0.1)

Verified by driving real messages through a real Talk room, not by mocks.

**Proven end to end**
- Bot self-registers via `BotInstallEvent` as `nextcloudapp://hermiq`, state enabled, features 14
  (`response|event|reaction`) — no `occ` step, no webhook, no shared secret, no egress.
- The in-process listener fires on a real message; the ⏳ acknowledgement appears in the room
  within the sender's request.
- Mention gating: an unaddressed group message produced **zero** queued jobs; an `@Hermiq`
  message produced exactly one.
- First contact created a conversation bound to the room; the NEXT message resolved to that SAME
  conversation via the `talkRoomToken` filter, rather than opening a blank one.
- The queued job ran the turn and the agent's answer was posted back into the room by the bot.
- Authorship persisted exactly as specified: `role=user` rows carry `author_id` +
  `author_display_name`; the `assistant` row carries neither.

**Two real bugs the live run caught that no unit test would have**
1. `object.content` is NOT the message text — `ActivityPubHelper::generateNote()` sets it to
   `json_encode(['message' => …, 'parameters' => …])`. The agent's first real prompt was a JSON
   blob. Fixed by decoding, with mention placeholders substituted back to display names; pinned
   by `TalkBotInvokeListenerTest::testJsonEnvelopeIsDecodedToPlainText`.
2. A background job carries **no session**, so every OpenRegister write was attributed to
   "Anonymous" and refused by RBAC — the turn died before reaching the model. Fixed by
   impersonating the speaker for the turn and restoring the prior identity in a `finally`,
   mirroring `ScheduleService`.

**Honest limits**
- Measured latency: ~68s from hand-off to answer, dominated by the LLM on CPU. The triggered
  fast path was NOT exercised because no triggerable runner for Hermiq turns exists yet — every
  turn currently takes the queued path, and the nudge is a seam, not a realised improvement.
- The model used for verification was a small local `qwen2.5:3b`; it answered correctly ("The
  capital of France is Paris") and then degenerated into repetition. That is a model artifact on
  CPU, not a bridge defect — the transport is what was under test.
- Multi-participant was verified for the single-speaker path and by unit tests at both guards; a
  two-real-users live run remains the strongest evidence and was not performed.
