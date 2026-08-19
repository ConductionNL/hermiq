---
kind: code
depends_on: []
---

# Proposal: instant-talk

## Why

Talking to an agent from Talk works, but at background-job cadence. The pipeline
today: spreed dispatches `BotInvokeEvent` in-process → `TalkBotInvokeListener`
acknowledges (⏳ reaction) and hands off — it "MUST NOT reach the engine" — →
`TalkTurnDispatcher::dispatch()` enqueues a durable `TalkTurnJob` (`QueuedJob`) and
nudges a fast-path seam that, by its own comment, never fires: "no such runner ships
yet… every turn currently takes the queued path". `docs/talk-chat-bridge.md` states
the consequence plainly: "reply latency depends on how often the instance runs
background jobs. On a default Nextcloud that can be several minutes." The ⏳ is
immediate; the answer is not. The doc also records two more ceilings this change
lifts: "No streaming. Bots post whole messages", and a one-room-one-agent binding
(`talk_room_agents` maps a room token to ONE agent uuid).

This change makes Talk answers effectively instant — event-driven turn execution
with sub-few-seconds acknowledgement and progressive (edit-streamed) replies — and
makes rooms multi-party: several agents and several colleagues in one room, agents
individually addressable, agent-to-agent turns bounded against loops, each turn
running as its human speaker per the in-progress `talk-shared-sessions` spec, and
per-room agent membership managed by Talk moderators.

## What Changes

**Instant execution (replace the cadence, keep the durability):**
- `lib/Service/Talk/TalkTurnDispatcher.php`: the durable `TalkTurnJob` enqueue stays
  the record of every turn; a new **immediate execution path** runs the turn without
  waiting for a cron tick. Each turn gets a **turn id** (roomToken + Talk message id)
  and a claim: whichever path claims it first executes it; the other finds the claim
  and exits — the queued job becomes the safety net instead of the only executor.
- The immediate path, in preference order (design.md): a registered
  `ITriggerableProvider` runner (the seam `triggerFastPath()` already nudges — this
  change ships the runner half via the CLI-runner/ExApp track); where none is
  registered, a post-response in-process execution (run the turn after the bot
  invocation response is flushed, so the sender's Talk request is never held — the
  existing "a reply never blocks the person who sent it" requirement is preserved).
- `docs/talk-chat-bridge.md`'s "Why the answer is not instant" section is rewritten
  once this lands (doc task).

**Progressive replies:**
- `lib/Service/Talk/TalkBridge.php` gains message **editing** (spreed bot API);
  capability-probed like everything else in that class. When editing is available,
  the agent posts an initial reply early and edits it as generation progresses
  (throttled), finishing with the complete answer; when not, today's single whole
  message. The ⏳ ack remains, now typically resolving within seconds.

**Multi-party rooms:**
- Room binding becomes **many agents per room**: each Talk-enabled agent registers
  its own bot identity (`TalkBridge::botActorId(?string $agentId)` already
  parameterises the actor), so **Talk's own moderator bot management is the per-room
  agent membership surface** — a moderator enables/disables an agent's bot per room,
  exactly the `occ talk:bot:setup` flow the bridge doc describes, now per agent.
- Addressing: in a group room an agent answers only when **it** is @-mentioned by
  its own name or its own message is replied to (`TalkMentionMatcher` extended to
  per-agent identities); an unaddressed message triggers no agent. One-to-one rooms
  keep every-message-is-a-turn.
- Sessions: one session **per room per agent** (extends today's one-room-one-session
  mapping); every human turn runs as its speaker with speaker-scoped files and
  credentials (the `talk-shared-sessions` rules, unchanged).
- Agent-to-agent: an agent's reply may @-mention another room agent, which takes a
  turn — governed by hard loop bounds: mention-only triggering, no self-triggering,
  a per-chain hop budget inherited from the originating human turn, and a per-room
  rate cap. Agent-triggered turns act as the originating human speaker (never as an
  agent identity, which has no Files/credentials of its own).
- Ordering & dedup: per room+agent, turns execute serially in Talk message order;
  the turn-id claim makes processing exactly-once per inbound message on every path.

### MCP coverage

No new MCP surface — inbound Talk handling and delivery are engine/bridge behaviour,
not user-invoked tools.

## Capabilities

### New Capabilities
- `instant-talk`: event-driven turn execution with claim-based exactly-once
  semantics, immediate acknowledgement targets, edit-streamed progressive replies,
  and multi-party rooms (multiple agents + multiple humans, per-agent addressing,
  bounded agent-to-agent turns, moderator-managed per-room agent membership).

### Modified Capabilities
- `talk-chat-bridge`: the "Turn hand-off is event-driven when possible and queued
  otherwise" requirement is amended — the queued job becomes the fallback/record
  while an immediate path executes the turn, with a claim preventing double
  execution. (The listener's fast-ack "never runs an agent turn inline within the
  sender's request" posture is unchanged.)

## Impact

- **Code:** `lib/Service/Talk/TalkTurnDispatcher.php` (claim + immediate path),
  `lib/Cron/TalkTurnJob.php` (claim check), `lib/Service/Talk/TalkTurnService.php`
  (progressive delivery hooks, agent-to-agent chain budget),
  `lib/Service/Talk/TalkBridge.php` (message editing, per-agent bot registration),
  `lib/Service/Talk/TalkBotInstaller.php` (one bot per Talk-enabled agent),
  `lib/Service/Talk/TalkMentionMatcher.php` + `TalkAgentBinding.php` (multi-agent
  rooms, per-agent addressing), `lib/Listener/TalkBotInvokeListener.php` (dedup key,
  immediate-path kickoff), `docs/talk-chat-bridge.md`, unit tests.
- **Specs:** builds on the in-progress `talk-shared-sessions` spec (each turn as its
  speaker) — that spec's rules are consumed, not modified.
- **Out of scope (explicit):** the ExApp triggerable runner itself ships on the
  `llm-cli-runner-exapp`/ExApp track — this change defines the runner contract and
  works without it via the post-flush path; Talk *threads* as sub-sessions stay
  deferred (one session per room per agent); non-Nextcloud chat platforms remain
  OpenConnector's job (ADR-005). No OpenRegister-side change is required.
