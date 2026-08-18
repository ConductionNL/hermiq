# Tasks: instant-talk

## 1. Claims and dedup

- [ ] 1.1 Turn-id derivation (`roomToken` + Talk message id + agent uuid) computed in
      `TalkBotInvokeListener` at hand-off and carried in the `TalkTurnJob` argument.
- [ ] 1.2 Claim store: insert-only atomic claim with TTL (TTL > turn timeout budget;
      both via `IAppConfig` with safe defaults); persistence via `ObjectService`
      (single write-path) unless a lighter core primitive proves atomic enough —
      decide in-implementation and record it.
- [ ] 1.3 `TalkTurnJob::run()` claims before executing; an existing live claim ⇒
      exit silently; an expired claim ⇒ re-claim and execute (queue as safety net).

## 2. Immediate execution paths

- [ ] 2.1 `TalkTurnDispatcher::dispatch()`: keep the durable enqueue first; then
      immediate path selection — registered `ITriggerableProvider` nudge (existing
      `triggerFastPath()`), else post-flush in-process execution.
- [ ] 2.2 Post-flush execution: run the claimed turn after the sender's response is
      flushed (`fastcgi_finish_request()` where available; guarded shutdown-phase
      execution elsewhere), under a configurable concurrent-instant-turns cap —
      overflow turns stay queued. The sender's request latency MUST be unaffected
      (assert in tests via the dispatch return path).
- [ ] 2.3 Define the triggerable-runner contract (pull oldest unclaimed turn, claim,
      execute via `TalkTurnService`, repeat) as a documented interface; the runner
      itself ships on the `llm-cli-runner-exapp` track — do not stub it here.

## 3. Progressive replies

- [ ] 3.1 `TalkBridge`: add `editMessage(roomToken, messageId, newText, ?agentId)`
      with the same lazy spreed guards as `postToRoom*`; add an edit-capability
      probe.
- [ ] 3.2 `TalkTurnService`: delivery sink — first chunk posts via
      `postToRoomReturningId()`, later chunks edit (throttle default ≥2s via
      `IAppConfig`), final edit is the exact complete answer; edit failure ⇒ stop
      editing, deliver the complete answer as a message at the end; no edit support
      ⇒ today's single-message behaviour.
- [ ] 3.3 Resolve the ⏳ acknowledgement when the answer or failure notice lands.

## 4. Multi-agent rooms

- [ ] 4.1 `TalkBotInstaller`: register one bot per Talk-enabled agent (name = agent
      name, actor via `botActorId($agentId)`); install/refresh on app upgrade and
      agent save; uninstall on `talkEnabled` false / agent deletion.
- [ ] 4.2 `TalkAgentBinding`: resolve the set of enabled agents for a room from
      Talk's bot state; keep `talk_room_agents`/`talk_default_agent` working for
      single-agent rooms; with >1 enabled agent, explicit addressing only.
- [ ] 4.3 `TalkMentionMatcher`: per-agent matching — `@agent-name` mention or
      reply-to-that-agent's-message; multi-mention yields one hand-off per
      addressed agent (each with its own turn id); unaddressed group messages yield
      none; one-to-one rooms keep every-message-is-a-turn.
- [ ] 4.4 Sessions become per (room, agent): extend `TalkSessionRoom` mapping;
      migration for existing single-agent room sessions (they keep their session,
      now keyed with the bound agent).

## 5. Agent-to-agent chains

- [ ] 5.1 Chain descriptor on agent-triggered turns: originating human speaker uid,
      hop count, chain id; carried through hand-off and persisted on the turn.
- [ ] 5.2 Enforcement in the hand-off path: mention-only, no self-trigger, hop
      budget (`talk.agentChainMaxHops`, default 3), per-room rate cap on
      agent-triggered turns; exhausted budget posts a brief chain-ended notice.
- [ ] 5.3 Identity: agent-triggered turns execute as the rooting human speaker
      (impersonate-and-restore), passing through the owner-or-participant check —
      a rooting user who left the roster fails closed.

## 6. Docs

- [ ] 6.1 Rewrite `docs/talk-chat-bridge.md`: "Why the answer is not instant"
      becomes the instant-execution + fallback story; "No streaming" becomes the
      progressive-replies story with the edit-throttle note; add multi-agent rooms
      (moderator bot management as membership, addressing rules, chain bounds).

## 7. Verify

- [ ] 7.1 Unit tests (php:8.3-cli, the CI way): claim atomicity + double-delivery
      dedup + expired-claim recovery; dispatcher path selection (runner present /
      post-flush / neither); sender-request non-blocking; progressive sink (post →
      throttled edits → exact final; edit-failure fallback; no-edit-support
      fallback); per-agent mention matching incl. multi-mention and unaddressed
      silence; per-(room, agent) session isolation and serial ordering; chain rules
      (mention-only, self-inert, hop exhaustion notice, rate cap, rooting-speaker
      identity, roster-left fail-closed).
- [ ] 7.2 Fresh containerized PHPUnit run vs. the current baseline — report both
      counts.
- [ ] 7.3 Live-verify on the dev instance (Talk installed): send an addressed
      message and measure ack time and answer time WITHOUT running cron manually —
      record both numbers; enable two agents in one room and verify addressing,
      a two-agent chain, and the hop-budget notice.
- [ ] 7.4 `openspec validate instant-talk --strict`; phpcs/psalm/phpstan clean;
      hydra gates diff-scoped vs `origin/development` — report results.

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
