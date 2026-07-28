# Tasks: talk-room-grouping

Depends on `talk-chat-bridge` — grouping hooks the bind moment that change introduces.

## 1. Capability probe

- [x] 1.1 Add a probe for conversation-tag support: Talk present (`IBroker::hasBackend()`) AND `OCA\Talk\Service\ConversationTagService` resolvable through the container. Tags arrived in spreed 24 (`Version24000Date20260313120000`), so an older Talk must skip grouping and lose nothing else. Resolve spreed classes lazily — never a hard dependency, never a `class_exists()` guard at `register()` time.

## 2. Tag resolver

- [x] 2.1 Add a resolver that returns the user's Hermiq conversation tag, creating it via `ConversationTagService::createTag($userId, ...)` when absent. Tags are keyed per user — create one per affected user; there is no shared or instance-wide tag (design.md D2).
- [x] 2.2 Treat a uniqueness conflict on `(user_id, type, name)` as success: re-read the existing tag and continue, so two concurrent binds cannot fail or duplicate (design.md D6).

## 3. Assignment — read-modify-write

- [x] 3.1 Assign the bound room to each participant's Hermiq tag via `ParticipantService::assignConversationToTags()`. **Read the attendee's current `tag_ids` first and write the union** — the API takes the FULL list for that attendee-room pair, so passing only Hermiq's id silently wipes every tag the user had on that room (design.md D3). This is the highest-blast-radius mistake available here: it destroys data Hermiq does not own.
- [x] 3.2 Never reorder, rename, collapse or delete any of the user's tags.

## 4. Opt-out

- [x] 4.1 Add a per-user preference (default on) to disable grouping, and enforce it before both creation and assignment.
- [x] 4.2 Disabling MUST NOT remove existing assignments — they are ordinary Talk tags belonging to the user, removable through Talk's own UI (design.md D4).

## 5. Wire into the bind path

- [x] 5.1 Call the grouping path where `talk-chat-bridge` binds a room to a conversation — no new trigger, no polling, no background job.
- [x] 5.2 Wrap every Talk call best-effort: log and swallow failures so grouping can never fail a bind, a turn or a delivery, mirroring how `DeliveryService` treats its Note-to-self bonus channel.

## 6. Verify live

- [ ] 6.1 **NOT LIVE-VERIFIED.** Live on the real instance (spreed 24.0.1): bind a two-participant agent room and assert both users see it under a Hermiq tag; bind a second room for the same user and assert exactly one tag exists.
- [ ] 6.2 **NOT LIVE-VERIFIED.** Live-verify the destructive case does NOT happen: pre-assign a user's own tag to a room, trigger the bind, and assert both tags remain assigned afterwards.
- [ ] 6.3 **NOT LIVE-VERIFIED.** Live-verify the opt-out: disable the preference, bind, and assert no tag is created or assigned for that user; confirm previously assigned rooms keep their tag.

## 7. Documentation

- [x] 7.1 Document that Hermiq files agent rooms into each participant's own conversation list, that this is additive and disableable per user, and that the tag is presentation only — nothing reads it to make a decision.

## Acceptance criteria

- A Hermiq conversation tag is created on demand, once per user, and a concurrent bind neither duplicates it nor fails.
- A bound agent room appears under the Hermiq tag for every participant of that room.
- Assignment is additive: a user's pre-existing tags on that room survive, and no tag is reordered, renamed, collapsed or deleted.
- Each user can disable grouping; disabling stops further creation and assignment and leaves existing assignments intact.
- With Talk absent, or a Talk older than conversation-tag support, the bind succeeds and grouping is skipped silently with a log line.
- No tag failure can fail a bind, a turn or a delivery.
- Nothing in Hermiq reads the tag to make a decision.

## Quality reminders

- Depends on `talk-chat-bridge`; do not start until its bind path exists.
- No Hermiq schema changes — this change writes only into Talk's tables through Talk's services.
- The assignment API replaces the full tag list. Read-modify-write is mandatory; a blind write is data loss for the user.
- Hydra gates apply: `@spec` traceability on changed methods, `@e2e` on added/modified scenarios, SPDX headers on new PHP files, no stubs.
- Do not use sed/awk/scripts to modify code — use the Edit tool.
- Grouping is cosmetic. If it ever ends up in the failure path of chat, the wiring is wrong.

## Status (2026-07-28)

Implemented and wired into the bind path; unit-level behaviour and the spreed API surface were
verified against spreed 24.0.1 source (`ConversationTagService::createTag/getTags`,
`ParticipantService::assignConversationToTags`, `oc_talk_attendees.tag_ids`).

**The live checks in §6 were NOT run.** They need two real users sharing an agent room, one of
them with a pre-existing personal tag on it, and that setup was not built. The read-modify-write
in §3.1 is the risky part — a blind write destroys the user's own tags — so it is the thing most
deserving of the live check that has not happened yet.
