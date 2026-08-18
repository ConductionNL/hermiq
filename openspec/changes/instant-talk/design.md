# Design: instant-talk

## Context

Ground truth in the code today:

- `TalkBotInvokeListener` handles spreed's in-process `BotInvokeEvent`: fast ack,
  hand off, "MUST NOT reach the engine" — because the event fires inside the
  *sender's* request, and holding it for a model call would freeze their Talk client
  (the existing "a reply never blocks the person who sent it" requirement).
- `TalkTurnDispatcher::dispatch()` enqueues `TalkTurnJob` (a `QueuedJob`) as "the
  durable hand-off and today… the ONLY one that actually executes a turn";
  `triggerFastPath()` is "deliberately a nudge only" and returns false on every
  instance because no `ITriggerableProvider` runner ships. The spec requirement it
  implements says so out loud: "it is NOT a latency improvement that is already
  realised."
- `TalkTurnService::runTurn()` is the single execution path both mechanisms must go
  through, and a failed turn must not leave the room silent.
- `TalkBridge` posts whole messages (`postToRoom`/`postToRoomReturningId` — the id
  return was added so a reaction can find a message, which is also what editing
  needs); `botActorId(?string $agentId)` already anticipates per-agent bot actors.
- `TalkAgentBinding` resolves `talk_room_agents` (room → ONE agent uuid) with a
  `talk_default_agent` fallback; `TalkMentionMatcher` decides addressing in group
  rooms; `docs/talk-chat-bridge.md` documents minutes-scale latency, no streaming,
  and one room = one session.
- The `talk-shared-sessions` spec (in-progress) fixes the identity rules this change
  must not weaken: owner-or-listed-participant may take a turn; each turn runs AS
  its speaker; the engine and controller both enforce it.

## Decisions

**Claim-based exactly-once, with the queued job demoted to safety net.** Every
inbound addressed message yields a deterministic **turn id** = `roomToken` + Talk
message id. Both executors — the immediate path and `TalkTurnJob` — call
`TalkTurnService::runTurn()` only after atomically claiming the turn id (insert-only
claim store with a TTL for crash recovery); a lost claim race means "already being
handled, exit silently". This keeps the existing invariant "the queued job stays the
durable record… a turn can never be lost between the two mechanisms" while inverting
the default executor. The turn id doubles as the **dedup** key: spreed redelivery,
double enqueue, or an admin re-running a job can never produce two answers to one
message.

**Immediate path A — the triggerable runner (deployment-grade).** The
`ITriggerableProvider` seam in `triggerFastPath()` was built for exactly this; what
never shipped is the runner. This change defines the runner contract (pull the
oldest unclaimed Talk turn, claim, execute via `TalkTurnService`, repeat) and leaves
its packaging to the ExApp/CLI-runner track — an out-of-scope-but-specified seam,
the same posture `agent-context-system` took for `viewRefs`. When the runner is
registered, `dispatch()` behaves exactly as the existing spec scenario "A registered
triggerable runner is nudged in-request" describes, and answers arrive at
runner speed.

**Immediate path B — post-flush in-process execution (works everywhere).** Where no
runner is registered, the dispatcher registers a **post-response hook**: after the
bot-invoke handling completes and the sender's HTTP response is flushed
(`fastcgi_finish_request()` on FPM; on SAPIs without it, a shutdown-phase execution
guarded by a time budget), the same PHP process claims and runs the turn. The
listener's contract is formally preserved — the *sender-visible request* is never
held; the engine runs in the same process but after the response boundary. This is
the pragmatic tier that turns default installs from minutes to seconds without new
infrastructure. Risks are owned explicitly: PHP-FPM worker occupancy during long
turns (bounded by a configurable concurrent-instant-turns cap, overflow turns
falling back to the queue) and process death mid-turn (the TTL'd claim expires and
the still-enqueued `TalkTurnJob` re-executes — at-least-once via the queue,
exactly-once-visible via the claim).

**Progressive replies = post early, edit often, finish exact.** Talk has no
token-stream transport for bots, but spreed's bot API supports editing a bot's own
message; `postToRoomReturningId()` already returns the handle editing needs.
`TalkTurnService` gains a delivery sink: first content chunk → post the message;
subsequent chunks → edit, throttled (default ≥2s between edits — Talk pushes each
edit to every participant, so unthrottled edits would spam mobile clients); final →
one last edit to the exact complete answer, then the ⏳ reaction is resolved.
Capability-probed: no edit support ⇒ single whole message (today's behaviour) — the
progressive form is an enhancement tier, never a requirement on the instance. Edits
never change the message's author/actor, and an edit failure downgrades to
continuing without further edits plus a final complete post, so a flaky edit
endpoint cannot eat an answer.

**Multi-agent rooms ride Talk's own moderation.** One Hermiq bot per Talk-enabled
agent (name = the agent's name, actor via `botActorId($agentId)`), installed by
`TalkBotInstaller` on app upgrade and on agent save. A moderator's `occ
talk:bot:setup <botId> <token>` (or the Talk UI's bot toggle) IS the membership
operation — no parallel Hermiq-side room-membership store to drift out of sync, and
removal has the same instant-off property the bridge doc promises today. The legacy
single `talk_room_agents` map keeps working as a default binding for rooms with
exactly one enabled agent (backwards compatible); with several enabled agents it is
ignored in favour of explicit addressing.

**Addressing: only the mentioned agent speaks.** Group-room rules extend the
existing mention-gate per agent: agent X answers iff X's bot identity is mentioned
(`@agent-name`) or the message replies to one of X's messages. Mentioning two agents
in one message triggers two turns (each exactly-once under its own turn id —
turn id gains the agent uuid as a component for this reason: `roomToken + messageId
+ agentUuid`). An unaddressed group message triggers nobody. One-to-one rooms keep
every-message-is-a-turn (there is only one counterpart to address).

**Agent-to-agent turns: allowed, bounded, human-rooted.** An agent's posted reply is
itself a room message; if it mentions another enabled agent, that agent takes a
turn. Loop prevention is layered, all hard limits:
1. **mention-only** — an agent never reacts to an agent message that does not
   mention it (mirrors the human group-room rule);
2. **no self-trigger** — an agent's mention of itself is inert;
3. **hop budget** — every agent-triggered turn carries a chain descriptor rooted at
   the originating human turn; default max 3 hops (`IAppConfig
   ('hermiq', 'talk.agentChainMaxHops')`), exhaustion ends the chain with a brief
   visible notice (silence would read as failure — same reasoning as the
   undo-refusal message in the approvals flow);
4. **per-room rate cap** — a ceiling on agent-triggered turns per room per minute
   as the backstop against two agents ping-ponging within budget resets.

**Identity in agent-to-agent turns: the originating human speaker.** A chain turn
executes as the human whose message rooted the chain — never as "the agent", which
has no Files or credentials, and never as some other participant. This keeps every
`talk-shared-sessions` guarantee intact (files/credentials resolve per speaker; a
participant cannot reach another's data by baiting an agent chain, because the chain
never escalates beyond what its rooting human could do). A chain rooted by a user
who leaves the roster mid-chain dies at the next turn's owner-or-participant check —
fail closed.

**Sessions: one per room per agent; ordering serial within each.** The existing
room→session mapping becomes (room, agent)→session, so each agent keeps a coherent
history of what *it* said. Within one (room, agent) session, turns execute serially
in Talk message-id order — a second addressed message while a turn runs waits (its
claim queues behind the running one) rather than interleaving replies out of order.
Cross-agent turns in the same room may run concurrently; Talk's room timeline is the
shared ordering the humans see.

## What lives where (ADR-001 seam map)

| Concern | Owner | Status in this change |
|---|---|---|
| Turn execution, claims, chains, addressing, delivery/editing | **Hermiq** | Delivered here |
| Bot registration/moderation, mentions, message editing transport | **Nextcloud Talk** | Consumed via existing lazy-guarded `TalkBridge` |
| Triggerable runner packaging (ExApp/CLI runner) | **Hermiq ExApp track** | Contract specified here; runner ships on `llm-cli-runner-exapp` — explicit out of scope |
| Conversation/message/claim persistence, audit | **OpenRegister** (generic objects) | Used as-is via `ObjectService`; no OR change required |

## Risks / Trade-offs

- **FPM worker occupancy on path B.** Long turns hold a worker post-flush. Mitigated
  by the concurrency cap + queue overflow; instances that care run the runner
  (path A). Measured target, not vibes: the live-verify records ack and first-token
  timings on a default install.
- **Edit-based streaming is chatty for push notifications.** Throttle default chosen
  conservatively (≥2s) and configurable; final content is always a complete message
  so a participant who saw only the last state saw the answer.
- **Multiple bots per room increase moderator surface.** Accepted deliberately: it
  reuses Talk's existing, understood control instead of inventing a Hermiq-side ACL;
  the bridge doc gains a section listing per-room enabled agents.
- **Agent chains can still be wasteful within budget.** Hops and rate caps bound
  cost; run analytics already record every turn, so a noisy pair of agents is
  visible and killable (`talkEnabled` off, or bot disabled in the room).
- **Claim store TTL tuning.** Too short re-executes slow turns; too long delays
  crash recovery to the next cron tick. Default TTL > the turn timeout budget;
  both configurable.

## Open Questions

- **Resolved — run the turn inline in the listener?** No: the sender's request must
  never be held (existing spec requirement); post-flush execution gets the same
  latency without breaking it.
- **Resolved — auto-derive multi-party permission from Talk room membership?** No:
  `talk-shared-sessions` explicitly rejects implicit roster derivation; the roster
  stays explicit. Room membership decides only *delivery*, never *authorization*.
- **Open — should the hop-budget notice be a reaction instead of a message?** A
  message is visible in history (auditable); a reaction is quieter. Shipping as a
  short message; revisit with user feedback.
