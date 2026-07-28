# Talk Chat Bridge Specification

**Status**: in-progress
**Standards**: Nextcloud Talk (spreed ≥ 24) bot API — `nextcloudapp://` in-process dispatch, `BotInvokeEvent`, bot message OCS endpoint; Nextcloud TaskProcessing
**Feature tier**: MVP

**OpenSpec changes:**
- `openspec/changes/talk-chat-bridge-schema/` — the `Conversation.talkRoomToken` binding and `participants` roster, plus `Message.authorId` / `authorDisplayName` (kind: config)
- `openspec/changes/talk-chat-bridge/` — bot registration, the inbound listener, hybrid turn hand-off, room↔session resolution, mention gating and opt-in (kind: code, depends_on talk-chat-bridge-schema)

## Purpose

Make Nextcloud Talk an optional, **bidirectional** chat surface for Hermiq agent sessions — the
inbound half of the two-way channel ADR-005 chose Talk for and only ever half-built.

Hermiq registers as an in-process Talk bot. Where the bot is enabled, a room message becomes a
turn on a Hermiq session and the agent's answer is posted back into the room. Because Talk bot
messages are server-side, this makes an agent reachable from the Talk **iOS and Android apps**
with no mobile-client work at all.

It also closes the loop on scheduled runs. A cron report already lands in a Talk room via
`talk-delivery`; binding that room to the conversation the run produced means a reply — sent from
a phone, in the room where the report arrived — continues **that** session with its full history,
instead of dead-ending in a room nobody can answer.

The bridge is inert until deliberately switched on: an agent must be opted in, and a Talk
moderator must enable the bot in the room. With Talk absent, Hermiq behaves exactly as before.

## Requirements

### Requirement: An agent is reachable from Talk without any client-side work

The system MUST make an opted-in agent conversable from Nextcloud Talk using Talk's own clients,
including its mobile apps, without shipping or modifying client code.

#### Scenario: A user converses with an agent from Talk

- GIVEN an agent opted in to Talk and a room where the Hermiq bot is enabled
- WHEN a user addresses the agent in that room
- THEN the system MUST answer in that room using Talk's normal message rendering

### Requirement: Agent output delivered to Talk can be replied to

The system MUST bind a run's session to the Talk room its output was delivered into, so that a
reply in that room continues that session rather than starting an empty one.

#### Scenario: A cron report is answered from a phone

- GIVEN a scheduled agent run whose report was delivered to a Talk room
- WHEN a user replies in that room asking a follow-up
- THEN the system MUST answer with the originating run's history available

### Requirement: The bridge is optional and inert by default

The system MUST require explicit opt-in on both the Hermiq side and the Talk side before any
message produces a turn, and MUST behave exactly as it did before this feature when Talk is
absent or the bot is not enabled.

#### Scenario: Talk is not installed

- GIVEN an instance without Talk
- WHEN Hermiq boots, runs scheduled agents and renders its settings
- THEN the system MUST behave exactly as it did before this feature existed

### Requirement: A reply never blocks the person who sent it

The system MUST execute the agent turn outside the request that delivered the inbound message, and
MUST acknowledge receipt within that request so the sender knows their message landed.

#### Scenario: A message send is not held open by an agent turn

- GIVEN an agent turn that takes tens of seconds
- WHEN a user sends the message that triggers it
- THEN their message send MUST complete promptly
- AND the system MUST acknowledge receipt before the answer is ready

## User Stories

- As a team member, I want to ask our triage agent a follow-up straight from Talk on my phone, so that I do not have to open a browser to understand a report I just received.
- As a schedule owner, I want the report my agent posts to be the start of a conversation rather than a dead end, so that context from the run is not lost.
- As an administrator, I want the integration off until I turn it on, and removable from Talk alone, so that installing Hermiq does not change how Talk behaves.

## Acceptance Criteria

- [ ] An opted-in agent can be conversed with from Talk's web and mobile clients with no client changes.
- [ ] A reply in a room where a report was delivered continues that run's session with its history.
- [ ] Both opt-ins are off by default, and uninstalling the bot in Talk stops all inbound dispatch.
- [ ] With Talk absent, boot, delivery and settings are unchanged.
- [ ] The sender's message send is never held open by an agent turn, and receipt is acknowledged promptly.

## Notes

- **ADR-005** (delivery via Nextcloud Talk) — the decision this feature completes; its two-way
  channel and reply path were the stated justification for choosing Talk over a multi-platform gateway.
- **ADR-023** — Rule 1 keeps data RBAC in OpenRegister; the bridge's participant check is an
  action-level guard layered on top, not a replacement.
- **ADR-031** — the inbound listener and out-of-request turn are named imperative exceptions
  (external integration, queued/triggered work); the binding, roster and authorship are declarative data.
- Related features: `talk-delivery` (the outbound half), `talk-shared-sessions` (multi-participant),
  `talk-room-grouping` (sidebar grouping of agent rooms).
- Reply latency depends on the hand-off path: seconds where a triggerable runner is registered,
  otherwise bounded by the instance's background-job cadence. Not a property of Talk.
- Deliberately deferred: streaming into Talk, Talk threads as sessions, approvals via reaction,
  calls/voice, federation, and bridging to non-Nextcloud chat platforms (rejected by ADR-005).
