# Test Plan: talk-chat-bridge

Two things drive this plan. First, the bridge's central failure mode — a room→conversation
resolve that silently returns nothing — is **invisible to mocked tests**: an in-memory double
happily returns the conversation a real filter query would not. Second, the change relaxes two
authorization guards, so the negative cases matter more than the positive ones. Live
verification against the real instance (spreed 24.0.1 is installed) is therefore mandatory
evidence, not a nice-to-have.

## Test Cases

### TC-1: A reply in a bound room continues that session

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room`
- **type**: functional
- **preconditions**: spreed installed, Hermiq bot installed with a `nextcloudapp://` URL and enabled in a room; an agent opted in to Talk; a `Conversation` bound to that room carrying prior history
- **steps**: send a message in the room that addresses the agent; wait for the queued turn to run
- **expected result**: an answer is posted into the same room; the turn is appended to the bound conversation, not a new one; the conversation's history now contains both the new user turn and the answer
- **test command**: `/test-functional`

### TC-2: Room binding resolves through a real filter query

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room`
- **type**: regression
- **preconditions**: a `Conversation` persisted with a `talkRoomToken` on a live instance
- **steps**: query conversations filtering on `talkRoomToken` equal to the stored token, against the real register — not a double
- **expected result**: exactly the bound conversation is returned; a zero-row result is a failure, and is the specific green-but-dead outcome this case exists to catch
- **test command**: `/test-api`

### TC-3: The listener never runs the turn inline

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-bot-listener-never-runs-an-agent-turn-inline`
- **type**: regression
- **preconditions**: the `BotInvokeEvent` listener with the engine supplied as a test double
- **steps**: dispatch a `BotInvokeEvent` carrying an addressed message
- **expected result**: the engine double is never called; a background job is enqueued; a reaction is added. This guards a property a later refactor could quietly undo
- **test command**: `/test-functional`

### TC-4: A non-participant is refused at both layers

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant`
- **type**: security
- **preconditions**: a conversation whose owner and `participants` roster both exclude a third user
- **steps**: (a) call the chat API as that user against the conversation; (b) drive a turn for that user directly into the engine, bypassing the controller
- **expected result**: both are refused; no message is persisted in either case. The engine-layer refusal is the one that matters — the bridge reaches the engine without passing the controller
- **test command**: `/test-security`

### TC-5: A participant cannot reach the owner's files

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-files-and-credentials-resolve-as-the-speaker-not-the-owner`
- **type**: security
- **preconditions**: a shared conversation; an agent with an attached context file that exists only in the owner's user folder; a second listed participant
- **steps**: take a turn as the non-owner participant
- **expected result**: context resolves from the participant's own user folder; the owner-only file is not present in the turn's context; credentials resolve for the participant, not the owner
- **test command**: `/test-security`

### TC-6: An unaddressed group message is ignored

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room`
- **type**: functional
- **preconditions**: a group room with the bot enabled and an opted-in agent bound
- **steps**: post a message that neither mentions the agent nor replies to one of its messages; then post an `@`-mention; then reply to one of the agent's own messages
- **expected result**: no reply to the first; a reply to the second and third. Un-gated behaviour would make the agent answer every message in exactly the team rooms reports land in
- **test command**: `/test-functional`

### TC-7: A delivered report can be replied to

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-delivery/spec.md#requirement-talk-delivery-binds-the-delivered-for-conversation-to-the-room`
- **type**: functional
- **preconditions**: a schedule with `deliver = talk` and a target room whose bot is enabled; an opted-in agent
- **steps**: trigger the schedule; confirm the report posts; reply in the room addressing the agent
- **expected result**: the run's conversation carries the room token; the reply appends to that same conversation and the answer has the run's history available. This is the change's headline user story end-to-end
- **test command**: `/test-functional`

### TC-8: A shared turn is attributed and distinguishable

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-each-human-turn-records-its-author`
- **type**: functional
- **preconditions**: a conversation with an owner and one listed participant
- **steps**: send a turn as each user; then assemble history for the model
- **expected result**: each user message carries the speaker's `authorId` and their display name at send time; the assistant's messages carry no author; assembled history attributes each human turn to its author
- **test command**: `/test-api`

### TC-9: Talk absent changes nothing

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-listener-registration-is-unconditional-and-availability-is-probed-at-invoke-time`
- **type**: regression
- **preconditions**: an instance without spreed
- **steps**: boot Hermiq; run a scheduled agent with each delivery channel; open Hermiq's admin settings
- **expected result**: boot is clean; delivery behaves exactly as before this change; admin settings render and indicate Talk is unavailable; registration does not consult `class_exists()` for a spreed class
- **test command**: `/test-regression`

### TC-10: Both opt-ins are required, and uninstall stops everything

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides`
- **type**: functional
- **preconditions**: a room with the bot enabled and an agent bound
- **steps**: with the agent's Talk opt-in off, address it; enable the opt-in and address it again; then uninstall the bot and address it once more
- **expected result**: no turn in the first case; a turn in the second; no turn after uninstall. Uninstall is the rollback lever and must work without a code change
- **test command**: `/test-functional`

### TC-11: A queued answer survives its room disappearing

- **spec_ref**: `openspec/changes/talk-chat-bridge/contract.md#error-codes`
- **type**: functional
- **preconditions**: an enqueued turn for a room
- **steps**: disable or uninstall the bot, or delete the room, before the queued job runs; then run the job
- **expected result**: the post fails with 401/404 and is treated as terminal and non-retryable; the run is not marked failed; nothing is left retrying indefinitely
- **test command**: `/test-functional`

### TC-12: Both hand-off paths answer, and their latency is measured

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise`
- **type**: performance
- **preconditions**: a bound room; one run with a triggerable hermiq provider registered, one run with none
- **steps**: on each configuration send an addressed message; measure the interval to the acknowledgement reaction and separately to the posted answer; confirm both runs route through the same `TalkTurnService`
- **expected result**: the acknowledgement lands inside the originating request on both. The triggered path answers without waiting for a cron tick; the queued path still answers, bounded by the instance's job cadence. Both numbers are recorded — this case exists to make the D2b latency claim answerable with data rather than to assert a threshold
- **test command**: `/test-performance`

### TC-13: A failed turn is reported, not silent

- **spec_ref**: `openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise`
- **type**: functional
- **preconditions**: a bound room; a turn that fails during execution
- **steps**: induce a turn failure on the triggered path (`TaskFailedEvent`) and again on the queued path
- **expected result**: on both paths the failure is reported rather than leaving the acknowledging reaction as the only trace. A user who sees ⏳ and nothing else cannot distinguish "still working" from "died"
- **test command**: `/test-functional`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| Hermiq registers as an in-process Talk bot | TC-10 (uninstall path), TC-1 (dispatch works) |
| Listener registration unconditional, availability probed at invoke time | TC-9 |
| Listener never runs a turn inline | TC-3 |
| Hand-off is event-driven when possible, queued otherwise | TC-12, TC-13 |
| Room message becomes a turn on the bound session | TC-1, TC-2 |
| Agent responds only when addressed in a group room | TC-6 |
| Integration is opt-in on both sides | TC-10 |
| Administrators can see the bridge's configuration | TC-9 (Talk-absent render) — **partially covered**, see Out of Scope |
| Owner-or-participant may take a turn | TC-4 |
| Each human turn records its author | TC-8 |
| The model can tell speakers apart | TC-8 |
| Files and credentials resolve as the speaker | TC-5 |
| Talk delivery binds the conversation to the room | TC-7 |
| Binding never breaks delivery | **not covered** by a listed case — unit-tested only, see Out of Scope |
| Delivery without a room does not bind | **not covered** by a listed case — unit-tested only, see Out of Scope |

## Out of Scope

- **Duplicate `talkRoomToken` bindings** (deterministic most-recent-wins resolve) — reachable
  only by constructing state the UI cannot produce. Unit test on the resolver only.
- **Binding-failure and non-room-delivery paths** — require injected persistence failures and
  exercise the existing, already-covered fallback chain. Unit tests on `DeliveryService` only.
- **Admin settings happy path with Talk present** — covered by a Playwright scenario in the spec
  delta rather than a case here; only the Talk-absent render is listed above.
- **Talk mobile clients themselves.** The claim "this works on iOS and Android" rests on bot
  messages being server-side, so a room verified in the web client is verified everywhere. No
  device testing is planned, and that assumption is stated here so it is reviewable rather than
  implicit.
- **Streaming, threads, approvals-via-reaction, calls, federation** — out of scope for the change.
