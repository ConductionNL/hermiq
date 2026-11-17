# Talking to an agent from Nextcloud Talk

Hermiq agents can be conversed with from a Nextcloud Talk conversation — including the Talk
**mobile apps**, with no app update needed, because the agent replies as a server-side bot like
any other participant.

The same wiring makes a scheduled report answerable: when an agent posts its nightly digest into
a Talk room, replying in that room continues **that** session, with the run's history, instead of
starting an empty one.

## Turning it on

Two switches are required and both default to off. Neither alone does anything.

1. **Enable the Hermiq bot in the conversation.** Hermiq registers a bot called `Hermiq` when the
   app is installed or upgraded. A Talk moderator enables it per conversation:

   ```bash
   occ talk:bot:list                 # find the Hermiq bot's id
   occ talk:bot:setup <botId> <conversation-token>
   ```

2. **Bind an agent to the room and opt that agent in.** Set `talkEnabled` to true on the agent,
   and map the room to it:

   ```bash
   occ config:app:set hermiq talk_room_agents --value '{"<room-token>":"<agent-uuid>"}'
   ```

   A `talk_default_agent` may be set instead to answer in any room where the bot is enabled and
   no explicit binding exists.

## How it behaves

- **In a one-to-one conversation with the bot**, every message is a turn.
- **In a group conversation**, the agent answers only when it is `@`-mentioned or when you reply
  to one of its own messages. Without this it would answer every message in the room — and group
  rooms are exactly where reports get delivered.
- **Receipt is acknowledged with a ⏳ reaction** as soon as your message lands. The reaction is
  removed once the answer, or a notice that the turn failed, is in the room.

## How the answer arrives so fast

The agent turn never runs inside your message-send request — doing so would hold your Talk client
waiting for the whole model call. Instead every turn is handed off three ways at once:

1. **It is queued as a background job.** This is the durable record: whatever happens to the other
   paths, the job is there.
2. **A registered turn runner is nudged**, when one is installed (it ships separately as an ExApp).
   It picks the turn up within seconds.
3. **Otherwise the turn runs right after your request has been answered**, in the same PHP process.
   Your Talk client is already released by then, so you do not wait; the answer starts within
   seconds instead of at the next background-job tick.

Each turn has an id (room, message, agent) that an executor must **claim** before running it, so
the instant run and the background job never both answer, and a message Talk delivers twice is
answered once. If the instant run dies halfway, its claim expires and the background job runs the
turn instead.

Two limits apply:

- **The instant path needs a distributed memcache** (Redis, Memcached or APCu) for the claims. On
  an instance without one, turns run on the background job only, as before — the answer then
  arrives when the job runs, so a fast cron cadence (every minute) matters there.
- **At most `talk.instantTurnsMax` instant turns run at once** (default 2), so long turns cannot
  tie up every PHP worker. Turns over the cap wait for the background job. `talk.instantTurns` set
  to false turns the instant path off. The claim lives `talk.turnClaimTtlSeconds` (default: the
  turn budget `talk.turnTimeoutSeconds`, 300, plus a minute).

## Answers grow while the agent writes

Where Talk can edit a bot's message, the answer is posted as soon as the agent starts writing and
then edited as it grows, at most once every `talk.editThrottleSeconds` (default 2) — every edit is
pushed to every participant, so editing more often would flood phones. The last edit is always the
exact, complete answer. Where Talk cannot edit, or an edit fails, the complete answer is posted as
an ordinary message at the end: progressive delivery can never cut an answer short.

## Several agents in one room

Every Talk-enabled agent has its own bot, named after the agent. A moderator adds an agent to a
room the way any bot is added — Talk's bot settings for the conversation, or
`occ talk:bot:setup <botId> <token>` — and removing the bot removes that agent from the room at
once. There is no separate Hermiq list of who is in which room.

In a room with several agents, **only the agent you address answers**: `@`-mention it by name, or
reply to one of its messages. Mention two agents and each takes its own turn. A message that
mentions nobody is answered by nobody. Rooms bound to a single agent before per-agent bots keep
working through the shared bot.

## Sharing a session with colleagues

A conversation can name `participants` beyond its owner; anyone listed may take a turn. An empty
roster means owner-only, which is how every conversation created before this feature behaves.

**Each turn runs as the person who typed it.** Attached context files are read from *that*
person's Files, and credentials are scoped to them — so one participant can never make the agent
read another's documents.

The visible consequence is worth knowing up front: **the same agent in the same room can answer
differently depending on who asks**, because the files it can see differ per speaker. That is the
intended security behaviour, not a bug.

Each human turn records who sent it, capturing the display name **as it read at the time**, so a
transcript stays readable after someone is renamed or their account is removed.

## Sidebar grouping

Agent rooms are filed under a personal **Hermiq** tag in each participant's own conversation list,
so they stop competing with the conversations you actually talk in. The tag is yours: Hermiq only
adds itself alongside whatever tags you already use, and never renames, reorders or removes them.

To turn it off for yourself, set the `talk_group_rooms` preference to `no`. Rooms already filed
stay filed — they are ordinary Talk tags, and you can clear them from Talk's own interface.

## Turning it off

Removing the bot stops all inbound dispatch immediately, with no code change and no Hermiq
setting to find:

```bash
occ talk:bot:uninstall <botId>          # instance-wide
occ talk:bot:setup <botId> <token>      # or toggle a single conversation
```

Setting `talkEnabled` to false on the agent has the same effect for that agent.

## What it does not do

- **No token-by-token streaming.** Answers grow by throttled edits (see above), not per token.
- **Agents do not yet ask each other.** An agent's answer that mentions another agent does not
  start that agent's turn yet.
- **Threads are not sessions.** One room maps to one session; Talk threads are not yet used.
- **Approvals are not resolved from Talk.** Approving a gated run still happens in Hermiq.
- **No non-Nextcloud chat platforms.** Bridging Slack/Telegram/Teams is OpenConnector's job
  (ADR-005), reached through the outbound webhook channel.

## If Talk is not installed

Nothing changes. Talk is an optional runtime dependency: Hermiq boots, runs agents and delivers
output exactly as before, and the Talk-specific settings simply have no effect.

## Approving from Talk

When a run needs a person's approval and its agent is bound to a conversation, Hermiq posts the
request into that conversation as the bot. The reviewer resolves it with a reaction:

- **👍 approves** — the run is released.
- **👎 denies** — the run does not execute.
- Any other emoji does nothing. An emoji is a lossy signal and this is a governance decision, so
  an unrecognised one is ignored rather than guessed at.

**Only the reviewer's reaction counts.** A reaction is visible to everyone in the room and anyone
can add one, so Hermiq checks the reacting user against the approval's reviewer — the named person,
or a member of the named group — using the same rule the approvals inbox uses. A reaction from
anyone else is ignored.

**A decision cannot be undone.** Removing your 👍 does not take it back; the run has already been
released. Hermiq says so in the conversation rather than staying silent, because silence would
read as "it worked".

The approvals inbox remains available and authoritative — the reaction is a shortcut for the
common case, not a replacement. Which surface was used is recorded on the approval, so an audit
reader can tell a one-tap approval from one made with the full context in front of them.

If Talk is absent, or the agent has no bound conversation, approvals behave exactly as before:
notification plus the inbox.
