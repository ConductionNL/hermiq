# Tasks: instant-talk

## 1. Claims and dedup

- [x] 1.1 Turn-id derivation (`roomToken` + Talk message id + agent uuid) computed in
      `TalkBotInvokeListener` at hand-off and carried in the `TalkTurnJob` argument.
- [x] 1.2 Claim store: insert-only atomic claim with TTL (TTL > turn timeout budget;
      both via `IAppConfig` with safe defaults); persistence via `ObjectService`
      (single write-path) unless a lighter core primitive proves atomic enough —
      decide in-implementation and record it.
      **Decided:** the distributed memcache (`IMemcache::add()`, atomic set-if-absent
      with TTL on Redis, Memcached and APCu), not `ObjectService`: an object create is
      a read-then-write and cannot refuse a concurrent duplicate. Without a memcache
      claims are not atomic, so the instant path stays off and the queued job is the
      only executor, as before (`TalkTurnClaims`).
- [x] 1.3 `TalkTurnJob::run()` claims before executing; an existing live claim ⇒
      exit silently; an expired claim ⇒ re-claim and execute (queue as safety net).

## 2. Immediate execution paths

- [x] 2.1 `TalkTurnDispatcher::dispatch()`: keep the durable enqueue first; then
      immediate path selection — registered `ITriggerableProvider` nudge (existing
      `triggerFastPath()`), else post-flush in-process execution.
- [x] 2.2 Post-flush execution: run the claimed turn after the sender's response is
      flushed (`fastcgi_finish_request()` where available; guarded shutdown-phase
      execution elsewhere), under a configurable concurrent-instant-turns cap —
      overflow turns stay queued. The sender's request latency MUST be unaffected
      (assert in tests via the dispatch return path).
- [x] 2.3 Define the triggerable-runner contract (pull oldest unclaimed turn, claim,
      execute via `TalkTurnService`, repeat) as a documented interface; the runner
      itself ships on the `llm-cli-runner-exapp` track — do not stub it here.

## 3. Progressive replies

- [x] 3.1 `TalkBridge`: add `editMessage(roomToken, messageId, newText, ?agentId)`
      with the same lazy spreed guards as `postToRoom*`; add an edit-capability
      probe.
- [x] 3.2 `TalkTurnService`: delivery sink — first chunk posts via
      `postToRoomReturningId()`, later chunks edit (throttle default ≥2s via
      `IAppConfig`), final edit is the exact complete answer; edit failure ⇒ stop
      editing, deliver the complete answer as a message at the end; no edit support
      ⇒ today's single-message behaviour.
- [x] 3.3 Resolve the ⏳ acknowledgement when the answer or failure notice lands.

## 4. Multi-agent rooms

- [x] 4.1 `TalkBotInstaller`: register one bot per Talk-enabled agent (name = agent
      name, actor via `botActorId($agentId)`); install/refresh on app upgrade and
      agent save; uninstall on `talkEnabled` false / agent deletion.
      The upgrade refresh is the `RefreshAgentTalkBots` repair step (install and
      post-migration, after `InstallTalkBot`): it re-installs the bot of every
      Talk-enabled, not trashed agent through `installForAgent()`, an upsert in
      spreed; one failing agent is logged and the others still refresh.
- [x] 4.2 `TalkAgentBinding`: resolve the set of enabled agents for a room from
      Talk's bot state; keep `talk_room_agents`/`talk_default_agent` working for
      single-agent rooms; with >1 enabled agent, explicit addressing only.
      `TalkAgentBinding::agentsForRoom()` reads the enabled per-agent bots from
      spreed (`TalkBotInstaller::enabledAgentIds()`, enabled rows only), keeps the
      opted-in agents, and falls back to the single-agent binding when none is
      enabled. `TalkBotInvokeListener::isAddressed()` treats a Hermiq-created room
      with more than one agent like any group room: mention or reply only.
- [x] 4.3 `TalkMentionMatcher`: per-agent matching — `@agent-name` mention or
      reply-to-that-agent's-message; multi-mention yields one hand-off per
      addressed agent (each with its own turn id); unaddressed group messages yield
      none; one-to-one rooms keep every-message-is-a-turn.
      Done in `TalkBotInvokeListener`: spreed invokes each enabled bot on its own, so a
      per-agent bot matches its own agent's name and replies (`TalkMentionMatcher` is
      unchanged), and each agent's hand-off gets its own turn id.
- [x] 4.4 Sessions become per (room, agent): extend `TalkSessionRoom` mapping;
      migration for existing single-agent room sessions (they keep their session,
      now keyed with the bound agent).
      The mapping is `TalkRoomBinding::findByRoomAndAgent()`, which the listener
      binds a turn with: the newest session on the room for that agent, never
      another agent's. The migration is lazy and in place: a room session that
      carries no agent is adopted by the agent taking the next turn there (its
      `agentId` written, every other field carried forward), so a single-agent room
      keeps its session. Sessions already carrying their agent need nothing. A
      second agent's new session copies the room's `talkRoomOrigin`, so a room
      Hermiq created stays created. Serial order within one session is the
      existing claim and queue order; no ordering change was made here.

## 5. Agent-to-agent chains

Spreed does not invoke bots for a bot's own message, so a chain is followed by
Hermiq from the answer it just posted: `TalkTurnService::answerTurn()` hands the
posted answer to `TalkAgentChain::follow()`.

- [x] 5.1 Chain descriptor on agent-triggered turns: originating human speaker uid,
      hop count, chain id; carried through hand-off and persisted on the turn.
      `TalkAgentChain::nextHop()` gives `{chainId, hop, rootSpeakerUid}`; the chain id
      is derived at the first hop and kept after. It rides the existing `chain`
      argument through `TalkTurnDispatcher`, the queued `TalkTurnJob` argument and
      `TalkTurnExecutor` onto `TalkTurn::$chain`.
- [x] 5.2 Enforcement in the hand-off path: mention-only, no self-trigger, hop
      budget (`talk.agentChainMaxHops`, default 3), per-room rate cap on
      agent-triggered turns; exhausted budget posts a brief chain-ended notice.
      Mentions are matched by agent name among the room's agents
      (`agentsForRoom()`), the answering agent excluded. The rate cap is
      `talk.agentChainRoomRatePerMinute` (default 6), counted in the distributed
      memcache; without one no agent-triggered turn is taken. Both an exhausted
      hop budget and the rate cap post a one-line notice under the answering
      agent's bot.
- [x] 5.3 Identity: agent-triggered turns execute as the rooting human speaker
      (impersonate-and-restore), passing through the owner-or-participant check —
      a rooting user who left the roster fails closed.
      The chained turn's speaker is `rootSpeakerUid`, so `runAsSpeaker()`
      impersonates and restores that human. Before hand-off the target agent's
      session (found, or opened for the rooting human) has its roster synced with
      the room, and the rooting human must be in the room and pass
      `ConversationParticipation::mayTakeTurn()`; otherwise no turn is taken.

## 6. Docs

- [x] 6.1 Rewrite `docs/talk-chat-bridge.md`: "Why the answer is not instant"
      becomes the instant-execution + fallback story; "No streaming" becomes the
      progressive-replies story with the edit-throttle note; add multi-agent rooms
      (moderator bot management as membership, addressing rules, chain bounds).
      "How the answer arrives so fast", "Answers grow while the agent writes" and
      "Several agents in one room" (addressing in created rooms, a session per
      agent, the upgrade refresh, and "Agents asking each other" with its four
      limits); "What it does not do" no longer says agents cannot ask each other.

## 7. Verify

- [x] 7.1 Unit tests (php:8.3-cli, the CI way): claim atomicity + double-delivery
      dedup + expired-claim recovery; dispatcher path selection (runner present /
      post-flush / neither); sender-request non-blocking; progressive sink (post →
      throttled edits → exact final; edit-failure fallback; no-edit-support
      fallback); per-agent mention matching incl. multi-mention and unaddressed
      silence; per-(room, agent) session isolation and serial ordering; chain rules
      (mention-only, self-inert, hop exhaustion notice, rate cap, rooting-speaker
      identity, roster-left fail-closed).
- [x] 7.2 Fresh containerized PHPUnit run vs. the current baseline — report both
      counts.
  - Build round 2, c3 (PHP 8.3 CLI, phpunit-unit.xml, openregister main beside):
    PR head 06c2bb55 5785 tests, 0 failures; development 704bb923 5736 tests,
    0 failures. 7.1's cases are in TalkTurnClaimsTest, TalkTurnDispatcherInstantTest,
    TalkTurnHandOffTest, TalkProgressiveReplyTest, TalkBotInvokeListenerAgentsTest,
    TalkRoomBindingPerAgentTest and TalkAgentChainTest.
- [ ] 7.3 Live-verify on the dev instance (Talk installed): send an addressed
      message and measure ack time and answer time WITHOUT running cron manually —
      record both numbers; enable two agents in one room and verify addressing,
      a two-agent chain, and the hop-budget notice.
- [ ] 7.4 `openspec validate instant-talk --strict`; phpcs/psalm/phpstan clean;
      hydra gates diff-scoped vs `origin/development` — report results.
  - Build round 2, c3: phpcs, phpstan and psalm report no errors on the 23 changed
    lib files. `openspec validate --strict` (latest CLI) has no errors, only four
    warnings: text above the first section in both deltas, and two long requirement
    texts. Open: those warnings and the diff-scoped hydra gates run.

## Acceptance criteria

- On a default install (no runner, no manual cron), an addressed Talk message is
  acknowledged within a few seconds and answered without waiting for a
  background-job tick; the queued job remains as recovery and never double-posts.
- Where Talk supports bot message editing, long answers arrive progressively and
  always end exact and complete; where it does not, behaviour is today's.
- A room can host several agents, each addressable only by its own mention/reply,
  with membership controlled by Talk moderators through bot management.
- Agent-to-agent turns work, are strictly mention-triggered, bounded by hop and
  rate limits with a visible chain-end, and never exceed the rooting human's
  access.

## Quality reminders

- SPDX tags in each PHP docblock; `@spec` tags referencing this change.
- No sed/awk/scripts on code — Edit tool only.
- The sender's request is never held; impersonation is always restored, whatever
  the outcome; every fallback is logged, never silent.
- The ExApp triggerable runner and Talk-threads-as-sessions are explicit deferred
  seams — do not stub them.
