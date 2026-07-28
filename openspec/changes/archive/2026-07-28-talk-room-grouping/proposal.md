---
kind: code
depends_on: [talk-chat-bridge]
---

# Proposal: talk-room-grouping

## Summary

Once agents are reachable in Talk (`talk-chat-bridge`), a user who works with several agents
accumulates several agent rooms, scattered through a conversation list that also holds their
human conversations. Talk 24 added **conversation tags** — a personal, collapsible grouping in
the conversation list — so this change files a user's agent rooms under one automatically-created
"Hermiq" tag. It is presentation only: no authorization, no routing, and no behaviour depends on
it.

## Motivation

The bridge's success case is also its clutter case. An agent room looks exactly like a person's
room in the conversation list, and a team that runs a nightly triage agent, a release agent and a
docs agent ends up with three more entries competing with their human conversations. The user
then has to remember which of the twelve rooms are agents.

Talk already solved this generically in version 24: `oc_talk_conversation_tags` plus a `tag_ids`
column on the attendee row gives every user a personal, ordered, collapsible grouping of their own
conversation list. Hermiq knows exactly which of a user's rooms are agent rooms — it bound them —
so it can file them without the user doing anything.

The alternative is asking each user to create a tag by hand and drag their agent rooms into it,
which they will not do, and which Hermiq would then have no way to keep current as agents come and
go.

## Affected Projects

- [ ] Project: `hermiq` — a Talk tag resolver that ensures a per-user "Hermiq" tag exists and
  assigns an agent room to it at bind time, plus a per-user preference to turn the behaviour off.

## Scope

### In Scope

- Ensure a per-user Hermiq conversation tag exists, created on demand the first time that user is
  bound into an agent room.
- Assign a bound agent room to that user's tag, for each participant of the room, at the moment
  the room is bound to a conversation.
- Leave a user's existing tags on a room untouched — assignment is additive, never a replacement.
- A per-user preference to disable the grouping, and to leave existing assignments alone once
  disabled.
- Degrade silently and completely when Talk is absent, when Talk predates tag support, or when the
  tag API rejects the call.

### Out of Scope

- **Any behaviour that depends on the tag.** Grouping is presentation; authorization stays with
  the participant roster (`talk-shared-sessions`) and routing stays with `talkRoomToken`.
- **A tag per agent.** One "Hermiq" tag for all agent rooms; per-agent tags multiply the clutter
  they are meant to remove.
- **Instance-wide or admin-managed grouping.** Talk tags are per user by design; there is no
  shared tag to create.
- **Renaming, reordering or collapsing on the user's behalf** beyond creating the tag. Sort order
  and collapsed state are the user's.
- **Retro-filing rooms** bound before this change ships.

## Approach

Talk stores tags in `oc_talk_conversation_tags`, keyed by `user_id` with a unique
`(user_id, type, name)`, and records assignment in `oc_talk_attendees.tag_ids` — so a tag
assignment is per user *per room*, which is exactly the granularity needed. Hermiq resolves the
user's Hermiq tag through `ConversationTagService` (creating it if absent) and assigns the room
via `ParticipantService::assignConversationToTags()`, both resolved lazily through the container
like every other spreed call in Hermiq.

The work hangs off the same moment the bridge already binds a room to a conversation, so there is
no new trigger and no polling.

## New Dependencies

None as a package. This does raise the *effective* Talk version for one optional feature: tags
arrived in spreed 24 (migration `Version24000Date20260313120000`). On an older Talk everything
else in the bridge works and only the grouping is skipped.

## Impact

- **New:** a tag resolver/assigner service and a per-user preference.
- **Modified:** the bridge's bind path calls the assigner; user settings gain one toggle.
- **Data:** writes only into Talk's own tables through Talk's services. No Hermiq schema change.
- **Behaviour when Talk is absent or older than 24:** no grouping, nothing else affected.

## Cross-Project Dependencies

- **spreed ≥ 24** for `ConversationTagService`, `ParticipantService::assignConversationToTags()`
  and the `tag_ids` attendee column. Optional — absence disables only this feature.
- **`talk-chat-bridge`** — must ship first; grouping hooks the bind path it introduces.

## Risks

### Risk 1: Writing another user's tags is a privacy-visible action

**Severity:** Medium — **Mitigation:** tags are personal. Filing a room into *someone else's*
sidebar is a change to their UI that they did not ask for, and doing it for every participant of a
group room means Hermiq is editing many people's conversation lists at once. Assignment is
therefore additive only, never removes or reorders their tags, and is individually disableable.
The alternative — filing only the binding user's own room — leaves every other participant with
the clutter this change exists to remove.

### Risk 2: Tag creation races when several rooms bind at once

**Severity:** Low — **Mitigation:** `(user_id, type, name)` is unique, so a concurrent create
fails rather than duplicating. The resolver treats "already exists" as success and re-reads.

### Risk 3: A tag-API failure breaks a working bind

**Severity:** Low — **Mitigation:** grouping is cosmetic and must never fail a bind, a turn or a
delivery. Every call is best-effort with the failure logged and swallowed, mirroring how
`DeliveryService` treats its Note-to-self bonus channel.

## Rollback Strategy

Disable the per-user preference, or revert the change. Existing tag assignments remain — they are
ordinary Talk tags the user can rename, empty or delete themselves through Talk's own UI, and they
carry no meaning for Hermiq, so leaving them behind breaks nothing. Nothing needs unwinding on
Hermiq's side because no Hermiq state was written.

## Open Questions

- **Should the tag be named after the app or the agent's purpose?** Provisionally "Hermiq", since
  it is one tag for all agent rooms and app-name grouping is what a user scanning a sidebar
  expects. A user-configurable name is deliberately deferred.
