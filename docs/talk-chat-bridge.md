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
- **Receipt is acknowledged with a ⏳ reaction** as soon as your message lands. The answer follows
  when the turn completes.

## Why the answer is not instant

The agent turn deliberately does **not** run inside your message-send request — doing so would
hold your Talk client waiting for the whole model call. It runs as a background job instead, so
the answer arrives when that job runs.

**This means reply latency depends on how often the instance runs background jobs.** On a default
Nextcloud that can be several minutes. If you want conversational latency, run background jobs on
a fast cadence (system cron every minute). The ⏳ reaction is what tells you the message landed in
the meantime.

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

- **No streaming.** Bots post whole messages; there is no token-by-token reply in Talk.
- **Threads are not sessions.** One room maps to one session; Talk threads are not yet used.
- **Approvals are not resolved from Talk.** Approving a gated run still happens in Hermiq.
- **No non-Nextcloud chat platforms.** Bridging Slack/Telegram/Teams is OpenConnector's job
  (ADR-005), reached through the outbound webhook channel.

## If Talk is not installed

Nothing changes. Talk is an optional runtime dependency: Hermiq boots, runs agents and delivers
output exactly as before, and the Talk-specific settings simply have no effect.
