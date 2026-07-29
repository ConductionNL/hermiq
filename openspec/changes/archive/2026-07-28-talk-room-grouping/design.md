# Design: talk-room-grouping

## Context

`talk-chat-bridge` puts agents into Talk rooms. Those rooms look exactly like human conversations
in the sidebar, so the more agents a user works with, the more their conversation list is diluted
by rooms nobody is actually talking in between reports.

Talk 24 shipped conversation tags, verified in the live instance:

| Surface | Fact |
|---|---|
| `oc_talk_conversation_tags` | Columns `user_id`, `name`, `sort_order`, `collapsed`, `type` (default `custom`); unique on `(user_id, type, name)` |
| `oc_talk_attendees.tag_ids` | Assignment lives on the **attendee** row — i.e. per user, per room |
| `ConversationTagService` | `createTag(userId, name)`, `getTags(userId)`, `getTag(tagId, userId)`, `validateTagIdsForUser(userId, tagIds)`, `reorderTags`, `setCollapsed`, `deleteTag` |
| `ParticipantService::assignConversationToTags(Participant, tagIds)` | The assignment call |
| `RoomController::assignTags(array $tagIds)` | The OCS surface; `[]` unassigns all |
| `Version24000Date20260313120000` | The migration that introduced the feature — so this is spreed ≥ 24 |

The `collapsed` column confirms the intended UX: a collapsible section in the conversation list.
That is exactly the shape wanted here.

## Goals / Non-Goals

**Goals**

- A user's agent rooms grouped under one tag, created and assigned without the user doing anything.
- Additive and reversible: never disturb the user's own tags, always disableable.
- Invisible failure: no bind, turn or delivery may break because a cosmetic grouping call failed.

**Non-Goals**

- Any semantics attached to the tag. Authorization is the participant roster; routing is
  `talkRoomToken`. If anything ever reads the tag to make a decision, this design has been misused.
- A tag per agent, instance-wide grouping, retro-filing of pre-existing rooms, or managing the
  user's sort order and collapsed state.

## Decisions

### D1: One "Hermiq" tag, not one per agent

A tag per agent reproduces the clutter one level up — five agents become five sidebar sections.
The problem being solved is "which of these rooms are agents", and one tag answers it. Per-agent
grouping can be layered later if anyone actually wants it; un-layering would be harder.

### D2: Tags are per user, so grouping is per user — including other people's sidebars

`oc_talk_conversation_tags.user_id` and the per-attendee `tag_ids` mean there is no such thing as a
shared tag. Filing a group agent room therefore means writing into the conversation list of every
participant, not just the person who caused the bind.

This is a real privacy-visible consequence and is why the preference in D4 exists: Hermiq is
changing other people's UI. The alternative — filing only for the binding user — leaves every other
participant with exactly the clutter the change exists to remove, which makes the feature
pointless in the group rooms that matter most. Additive-only assignment (D3) keeps the intrusion to
the minimum that still achieves the goal.

### D3: Assignment is additive and never destructive

`assignConversationToTags()` takes the full tag list for that attendee-room pair, and
`RoomController::assignTags([])` unassigns everything — so a careless implementation that passes
only Hermiq's tag id would silently wipe every tag the user had put on that room. The resolver
therefore reads the current assignment, adds the Hermiq tag if missing, and writes the union.
Sort order and collapsed state are never touched.

### D4: Per-user opt-out, and opting out does not unwind

Because D2 means Hermiq writes into people's sidebars, each user can turn it off. Disabling stops
future creation and assignment; it does not remove what is already assigned, because those are
ordinary Talk tags belonging to the user — Hermiq deleting them would be a second uninvited edit,
and the user can clear them through Talk's own UI.

### D5: Best-effort, hung off the existing bind moment

Grouping runs where the bridge already binds a room to a conversation — no new trigger, no polling,
no background job. Every Talk call is lazily resolved through the container and wrapped so that a
failure logs and returns, exactly as `DeliveryService` treats its Note-to-self bonus channel. A
capability probe skips the whole path when Talk is absent or predates tag support, so an older
Talk loses grouping and nothing else.

### D6: Conflicts resolve to success

`(user_id, type, name)` is unique, so two concurrent binds for one user cannot duplicate the tag —
one insert fails. The resolver treats that failure as "already exists", re-reads, and continues.
Getting this wrong turns a harmless race into a failed bind.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Ensure tag exists / assign room to tag | **Imperative** | External-integration seam — calls into spreed's own services and writes spreed's tables. An explicit ADR-031 exception; not expressible as a derived field on a Hermiq object. |
| Per-user opt-out preference | **Declarative (data)** | A stored user preference, read at bind time. |

No lifecycle, aggregation, calculation, notification, relation or widget behaviour is introduced,
so nothing belongs in `x-openregister-*`. No Hermiq schema changes at all — this change writes only
into Talk's tables through Talk's services.

## Seed Data (ADR-001)

No schemas are introduced or modified, so there is no register seed data.

Live verification needs environment rather than data: two NC users sharing one agent room, one of
whom already has a personal tag assigned to that room (to prove D3's additive behaviour), and one
user with the preference disabled (to prove D4).

## Risks / Trade-offs

- **Hermiq edits other users' conversation lists** (D2) → intrinsic to per-user tags; bounded by
  additive-only writes and a per-user opt-out. The honest framing is that this is a small
  uninvited UI change made on the user's behalf, not a neutral operation.
- **A naive assignment call wipes the user's tags** (D3) → the assignment API takes the full list,
  so read-modify-write is mandatory. This is the single most likely implementation mistake and the
  one with the worst blast radius, since it destroys data Hermiq does not own.
- **Raises the effective Talk floor to 24 for this feature** → contained by the capability probe;
  an older Talk loses grouping only.
- **A cosmetic call could break a working bind** (D5) → best-effort wrapping; grouping must never
  be in the failure path of chat.
- **Tag could accrete meaning later** → if a future change reads the tag to decide anything, the
  presentation-only boundary is gone and authorization becomes editable from Talk's UI. Stated
  here as a boundary to defend, not a risk to accept.
