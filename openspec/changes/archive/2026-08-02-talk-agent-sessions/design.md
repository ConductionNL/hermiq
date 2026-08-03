# Design: talk-agent-sessions

## Architecture Overview

Three seams change; nothing new is introduced between them.

```
Agent (talkEnabled, name)                     Conversation (session)
        |                                     talkRoomToken, talkRoomOrigin, participants
        | lifecycle                                   ^
        v                                             | writes
  TalkBotInstaller  --BotInstallEvent-->  spreed  <-- TalkSessionRoom (NEW)
        |  one bot per agent:                            creates room, renames it,
        |  nextcloudapp://hermiq-<agentId>               adds owner + bot, syncs roster
        v
  oc_talk_bots_server  (name, url, url_hash=sha1(url), secret, features=14, state=1)
        ^
        | url_hash
  MessageParser renders "<Agent Name> (Bot)" at read time
        |
  BotInvokeEvent --> TalkBotInvokeListener      (message -> turn)
                 --> TalkApprovalReactionListener (Like/Undo -> approval decision)
        both guards: "is this ANY hermiq bot URL" -> resolve agentId from the URL
```

Today the two listeners share one constant (`TalkBridge::BOT_URL`) and one actor id. After this
change they share a *predicate* and a *parser*: `TalkBridge::isHermiqBotUrl($url)` and
`TalkBridge::agentIdFromBotUrl($url)`. That is the whole structural delta on the inbound side.

On the outbound side `TalkBridge::postToRoomReturningId()` gains an agent id so it can pass the
right `botActorId($agentId)` to `ChatManager::sendMessage()`. Every current caller has an agent in
hand at the call site.

## Decisions

### D1 — One Talk bot per Talk-enabled agent, because it is the only lever on the displayed name

`ChatManager::sendMessage($room, null, Attendee::ACTOR_BOTS, $actorId, $message, $date)` takes no
display-name parameter (verified, spreed 24.0.1). `MessageParser` derives the shown name from the
actor id: it strips `Attendee::ACTOR_BOT_PREFIX`, takes the remainder as a `url_hash`, and looks
the bot record up **scoped to the conversation**. So the bot record is the single source of the
name, and per-message naming is structurally impossible.

Therefore: URL `nextcloudapp://hermiq-<agentId>`, name = the agent's name, description naming
Hermiq. `botActorId($agentId) = 'bot-' . sha1('nextcloudapp://hermiq-' . $agentId)`, which matches
the `url_hash` spreed stores.

*Alternatives considered:* (a) one bot, prefix every message with the agent name in the body —
rejected, the signature still reads "Hermiq (Bot)" and the prefix is noise on mobile; (b) a
Nextcloud *user* per agent — see D3.

### D2 — Install and rename both go through `BotInstallEvent`; no `occ`, no `BotService` method

spreed's `BotListener::handleBotInstallEvent()` is an **upsert keyed on (url, secret)**: it calls
`findByUrlAndSecret()`, and on a hit does `setName()` / `setDescription()` / `update()`. On a miss
it inserts, stripping `FEATURE_WEBHOOK` for `nextcloudapp://` URLs. A *different* secret on the
same URL throws `InvalidArgumentException`.

Two consequences that decide the implementation:

- **Rename is just a re-dispatch of `BotInstallEvent` with the new name.** No separate rename API
  is needed, and `BotService` has none — its public surface is event handlers plus
  `getBotsForToken()`, `validateBotParameters()` and `isAppForBotEnabled()`.
- **The per-agent secret must be deterministic**, or a rename becomes an install-with-a-different-
  secret and throws. `TalkBotInstaller::secret()` already derives the secret; it must derive it
  from the *per-agent* URL and stay stable for the life of the agent.

**The bot record is the only carrier of the displayed name, and that reaches backwards.**
`MessageParser` resolves a bot's display name at *render* time from the bot record, looked up by
`url_hash` and **scoped to the conversation**. Two consequences follow, and they are the reason
uninstall-on-delete has a cost worth stating:

- **Renaming an agent re-signs its already-posted messages.** Past messages render under the new
  name. Correct-but-surprising; spreed's model, not a Hermiq bug.
- **Uninstalling an agent's bot degrades its history.** Once the record is gone from the room, the
  conversation-scoped lookup misses and those messages render as `<actorId>-bot` instead of the
  agent's name. This is accepted as the standing price of uninstall-on-delete (see Open Questions)
  and is documented for users rather than engineered around.

`BotServerMapper` is used read-only (`findByUrl`) for the admin diagnostic, exactly as
`TalkBridgeStatus` does today. Writing through the mapper directly is rejected: it bypasses
`validateBotParameters()` and the `FEATURE_WEBHOOK` stripping.

The apply phase must still confirm this live rather than trusting the source read — see tasks.

### D3 — An agent icon is out of scope, and this is a rejection, not an omission

`oc_talk_bots_server` is `(id, name, url, url_hash, description, secret, error_count,
last_error_date, last_error_message, state, features)`. There is **no avatar column**, and the bot
install path exposes no avatar option. spreed also appends ` (Bot)` to every bot display name in
`MessageParser`, so the best achievable signature is `<Agent Name> (Bot)` beside the generic `>_`
bot glyph.

*Options considered and rejected:*

| Option | Why not |
|---|---|
| A Nextcloud **user** per agent | Gains both a name and an avatar, but the actor stops being a Talk bot. Every reaction-driven and message-driven path in this app is dispatched by `BotInvokeEvent`, which fires only for bots — the approval-reaction feature would need full re-verification, and provisioning a user per agent is a much larger governance surface (licences, group membership, RBAC, ADR-023). Not worth an icon. |
| Upstream feature request to nextcloud/spreed for a bot avatar | The right long-term fix, but it cannot gate this change. Worth filing; not a dependency. |
| Ship a glyph in the message body | Noise in every message, and does not change the actor rendering at all. |

This is recorded so the question is not re-opened as a defect report.

### D4 — The session owns the room; origin is recorded, not inferred

Creating a Hermiq chat session creates a Talk room titled after the session, adds the session
owner as a participant, enables the agent's bot in it, writes `talkRoomToken` back onto the
`Conversation`, and marks `talkRoomOrigin = "created"`. Renaming the session renames the room.

The origin is **stored, not inferred**. Inferring it (for example "the room has exactly the owner
and one bot") would be a heuristic that silently changes an authorization-adjacent behaviour —
whether the agent answers unaddressed messages — the moment someone invites a second person. An
absent value means `bound`, which is the safe, pre-change behaviour and needs no backfill.

`DeliveryService::resolveDefaultRoom()` already creates a room via `RoomService::createConversation()`;
`TalkSessionRoom` reuses that resolution path rather than adding a second one.

### D5 — Addressing: the room's origin decides the rule, not the room's type

Today: one-to-one → always a turn; group → literal `@Hermiq`, a rendered mention parameter, or a
reply to the bot.

After: **origin `created`** → every human message is a turn (the room exists for this session).
**Otherwise** → the mention gate stays, unchanged in intent, matched against the *agent's* name.
The one-to-one and reply-to-the-bot rules survive untouched inside the gate.

The matcher changes because the target changed from the single word `Hermiq` to arbitrary agent
names. Bots are **not** a source in spreed's collaborator search, so `@` does not autocomplete a
bot and the rendered-mention-parameter path fires only when a human name happens to collide.
Matching is therefore on literal typed text and MUST tolerate:

- multi-word names — `@Release Notes Agent`;
- case differences — `@release notes agent`;
- trailing punctuation — `@Release Notes Agent, please summarise`.

Prefix matching on the name with a word boundary or punctuation terminator, case-insensitive,
after the existing JSON-envelope decode (`object.content` is a JSON envelope, not plain text — the
lesson `talk-chat-bridge` paid for live). A non-match is a silent no-turn; it must never raise.

### D6 — The roster syncs from the room, but authorization still reads the roster

Room participants are copied into `Conversation.participants` at creation and whenever a
participant is added. Authorization continues to read the **stored roster**, per
`talk-shared-sessions`' explicit rule that permission MUST NOT be derived from live Talk room
membership at read time. This change supplies the list; it does not move the check.

### D7 — Grouping is reused, and extended only for late joiners

`TalkRoomGrouping` already creates a per-user `Hermiq` tag on demand, files a room additively with
a mandatory read-modify-write, honours the `talk_group_rooms` opt-out, and fails soft. Two new
call sites, no new mechanism: at room creation, and when a participant is added later. spreed's
tag rows are per user (`oc_talk_conversation_tags.user_id`) and a room joins a tag through the
*attendee* row (`oc_talk_attendees.tag_ids`), so there is no instance-wide group to create.

## Mixed-spec rationale (ADR-032)

**Call: `kind: code`, one change, no chain.** This diverges from the brief's expectation of a
`mixed` shape, and from this repo's own `*-schema` → `*-code` precedent
(`talk-chat-bridge-schema` → `talk-chat-bridge`, `talk-approval-reactions-schema` →
`talk-approval-reactions`). The reason is a ground-truth correction:

**The two properties this change was expected to add already exist.** `talk-chat-bridge-schema`
shipped `Conversation.talkRoomToken` (top-level, filterable) and `Conversation.participants`
(array of uid, default `[]`) on 2026-07-28, and both are live in
`lib/Settings/hermiq_register.json` at register `info.version` `0.21.0`. There is no `Session`
schema in play — the Talk bridge's session object is `Conversation` (`CONVERSATION_SCHEMA =
'conversation'`).

What remains of the declarative surface is **one optional string property** (`talkRoomOrigin`) plus
an `info.version` bump `0.21.0 → 0.22.0` and a `Conversation` schema-version bump `0.1.0 → 0.2.0`
— roughly 8 added lines in one file. ADR-032 defines `code` as work that "may incidentally touch
declarative JSON, but the centre of mass is code", and that is a precise description of this
change: per-agent bot lifecycle, two listener guards, room creation and rename, an addressing
rule and roster sync, against eight lines of JSON.

**Why not chain anyway.** The chain exists to let schema land inert and first so that consumer
work is never wasted if the schema engine cannot express the declaration. There is no engine
question here — `talkRoomOrigin` is a plain optional string with no `x-openregister-*` semantics,
of exactly the shape already proven twice on this same schema. A two-change chain would add a
merge, a review cycle and a second forced import for eight lines.

**The coupling, stated explicitly, because it is the thing that can go wrong.** `talkRoomOrigin`
is not independently useful: it exists solely so D5 can pick a rule, and the code that writes it
(D4) and the code that reads it (D5) ship in this same change. The operational hazard is therefore
ordering, not review scope — the register import MUST land before the reader runs, and
`importFromApp(force: false)` advances the register version *without applying the schema* while
still reporting success. Task 1 is the schema plus a **forced** import plus a read-back of the new
property, and every later task depends on it.

## Declarative-vs-imperative decision (ADR-031)

Each behaviour in this change, and where it lands:

| Behaviour | Declarative candidate | Decision |
|---|---|---|
| `talkRoomOrigin` on `Conversation` | plain property | **Declarative** — a register property, no service. |
| `participants` roster | plain property (already shipped) | **Declarative** — already a register property; this change only writes it. |
| Per-agent bot install / rename / uninstall on the agent lifecycle | `x-openregister-lifecycle` | **Imperative, by exception.** The transition's effect is an event dispatched into *another app's* service (`BotInstallEvent` → spreed). No `x-openregister-*` extension can call into spreed, and ADR-031's external-integration exception is exactly this case — the same exception `talk-room-grouping` was granted for `ConversationTagService`. Agent state itself (`talkEnabled`) stays declarative data. |
| Room creation / rename on session create / rename | `x-openregister-lifecycle` | **Imperative, same exception** — the effect is `RoomService::createConversation()` in spreed. |
| Addressing rule | — | **Imperative.** Not a lifecycle, aggregation, derived field, notification, relation or widget; it is inbound-event parsing. |
| Roster sync from room participants | `x-openregister-relations` | **Imperative.** The source of truth is spreed's attendee table, not an OpenRegister object, so there is no typed relation to declare. The *stored* result is declarative data. |
| Tag grouping | — | **Imperative, external integration** — already granted this exception by `talk-room-grouping`. |

No new Service class is introduced for anything a schema register could have expressed. The single
new service (`TalkSessionRoom`) exists only to hold spreed calls.

## Database Changes

No Nextcloud tables. Hermiq owns none (ADR-001) and this change adds none.

One OpenRegister schema change, in `lib/Settings/hermiq_register.json`, on
`components.schemas.Conversation`:

```json
"talkRoomOrigin": {
    "type": "string",
    "title": "Talk room origin",
    "description": "How this conversation came to have a Talk room. 'created' means Hermiq created the room for this session and the agent treats every human message in it as a turn. 'bound' means Hermiq was invited into, or delivered a report into, a room somebody else owns, and the mention gate applies. Unset is treated as 'bound'."
}
```

OPTIONAL, never added to `required`, and never bound by an `if`/`then`/`allOf` block — the
OpenRegister importer rejects conditional blocks. `Conversation.version` `0.1.0 → 0.2.0`;
`info.version` `0.21.0 → 0.22.0` with the changelog line appended to `info.description`.

Existing rows need no backfill: absent means `bound`, which is today's behaviour.

## Nextcloud Integration

- **Controllers:** no new routes. `ConversationController::create()` and `::update()` gain the
  room create / rename side effect; `ChatController::createNewConversation()` likewise.
- **Services:** `TalkBridge` (per-agent identity), `TalkBotInstaller` (per-agent lifecycle),
  `TalkSessionRoom` (new — room create / rename / participant add / roster write-back),
  `TalkRoomBinding`, `TalkAgentBinding`, `TalkRoomGrouping`, `TalkBridgeStatus`.
- **Mappers/Entities:** none of Hermiq's own. spreed classes stay lazily resolved by FQCN string
  through the container, never type-hinted, so Hermiq still boots with spreed absent:
  `OCA\Talk\Manager`, `OCA\Talk\Service\RoomService`, `OCA\Talk\Service\ParticipantService`,
  `OCA\Talk\Service\ConversationTagService`, `OCA\Talk\Model\BotServerMapper` (read-only),
  `OCA\Talk\Service\BotService` (read-only).
- **Events/Hooks:** dispatch `OCA\Talk\Events\BotInstallEvent` / `BotUninstallEvent`; consume
  `OCA\Talk\Events\BotInvokeEvent` in both existing listeners, still registered unconditionally by
  class-name string in `Application::register()`.
- **Repair:** none. Hermiq is unpublished and single-instance, and its agents and conversations
  have been cleared by hand down to the two Hydra agents and their three conversations, so there is
  no installed base to repair. Per-agent bots arrive through the agent lifecycle (D4), not through
  a repair step.

## Security Considerations

- **Authorization is unchanged.** `ConversationParticipation::mayTakeTurn()` still gates every turn
  on owner-or-listed-participant, still reads the stored roster, and is still enforced at both the
  engine and controller layers. Syncing room membership *into* the roster widens who may take a
  turn — that is the intended feature — but it happens through a deliberate write, and a user
  removed from the room is removed from the roster by the same path.
- **The approval-reaction guard is the sharp edge.** `TalkApprovalReactionListener::readPayload()`
  currently rejects everything that is not the one known bot URL. Loosening it to a prefix
  predicate must not loosen anything downstream: the reviewer check
  ("only the approval's resolved reviewer may decide") is a separate guard and stays exactly as
  it is. A too-broad URL predicate would let a *different* Hermiq agent's bot invocation reach an
  approval bound to another agent, so `agentIdFromBotUrl()` must return null for anything that is
  not `nextcloudapp://hermiq-<agentId>`, and the approval must still be resolved by message id.
- **Bot secrets.** Per-agent secrets are derived, never logged, and never leave the server. A
  `nextcloudapp://` bot needs no shared secret for dispatch; the secret exists only as spreed's
  upsert key.
- **No new endpoint, no new CSRF surface, no new public page.**

## File Structure

```
lib/
  Service/Talk/
    TalkBridge.php           (modified — per-agent URL/name/actor id, URL predicate + parser)
    TalkBotInstaller.php     (modified — per-agent install/rename/uninstall)
    TalkSessionRoom.php      (new      — create/rename room, add participant, write back)
    TalkRoomBinding.php      (modified — writes talkRoomOrigin)
    TalkAgentBinding.php     (modified — agent resolved from bot URL, config map is fallback)
    TalkRoomGrouping.php     (modified — late-joiner path)
    TalkBridgeStatus.php     (modified — per-agent bot rows in the admin diagnostic)
  Listener/
    TalkBotInvokeListener.php        (modified — guard, agent resolution, addressing rule)
    TalkApprovalReactionListener.php (modified — guard only)
  Settings/
    hermiq_register.json     (modified — Conversation.talkRoomOrigin + version bumps)
tests/e2e/spec-coverage/
  _fixtures.ts               (modified — hoist dismissTour)
  talk-agent-sessions.spec.ts (new)
```

## Seed Data

This change modifies the `Conversation` schema, so ADR-001 seed data applies. The property is
additive and optional, so the requirement is that seeded conversations *exercise both values* —
otherwise a fresh install can only ever demonstrate the mention-gated path and the created-room
rule ships untested on install.

### Schema: `conversation`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `seed-session-triage` | `seed-session-release-notes` | `seed-session-team-room` |
| title | Morning triage | Release notes draft | Team standup room |
| userId | `admin` | `admin` | `admin` |
| agentId | `00000000-0000-0000-0000-000000000000` | `00000000-0000-0000-0000-000000000000` | `00000000-0000-0000-0000-000000000000` |
| talkRoomToken | `<room-token>` | `<room-token>` | `<room-token>` |
| talkRoomOrigin | `created` | `created` | `bound` |
| participants | `[]` | `["admin"]` | `["admin"]` |

**Related items per object:**
- Files: none — a session's context comes from its agent, not from the session object.
- Notes: none.
- Tasks: none.
- Contacts: none.
- Messages: 2–3 `message` objects per conversation, one `role=user` carrying `authorId` +
  `authorDisplayName` and one `role=assistant` carrying neither, so the shared-transcript shape
  from `talk-shared-sessions` is visible on a fresh install.

The `@self` envelope for each is `{ "register": "hermiq", "schema": "conversation", "slug": <slug> }`.
Seeded `talkRoomToken` values are placeholders and resolve to no live room; that is intended —
seed data must not depend on spreed being installed.

## Risks / Trade-offs

- **[The reaction path is only provable live] →** re-run the three live approval checks after the
  guard change, as tasks, not as unit tests. See proposal Risk 1.
- **[Renaming an agent re-signs its history] →** accepted and documented. Display names resolve at
  render time from the bot record, so this is spreed's model, not a Hermiq bug.
- **[Uninstalling a bot orphans its past messages' names] →** the conversation-scoped `url_hash`
  lookup falls back to `<actorId>-bot`. Accepted: it is the standing cost of uninstall-on-delete
  (D2), paid whenever an agent is deleted. Documented in `docs/` rather than engineered around.
- **[One bot record per agent grows `oc_talk_bots_server`] →** bounded by the number of
  Talk-enabled agents, which is a handful; the table is tiny and indexed on `url_hash`.
- **[A per-agent bot must be enabled per room] →** the room-creation path enables it as part of
  creating the room, so the two-sided opt-in is preserved without asking the user to do it twice.

## Migration Plan

Full detail in `migration.md`, which is short: bump `info.version` to `0.22.0` and
`Conversation.version` to `0.2.0`, then force-import the register and read `talkRoomOrigin` back
from the live register. That is the whole migration.

There is **no data migration**. Hermiq is unpublished and single-instance; its agents and
conversations were cleared by hand to the two Hydra agents and their three conversations, so no
legacy room is bound to the shared bot. Existing conversations carry no `talkRoomOrigin`, which
reads as `bound` and is exactly today's behaviour — nothing to backfill.

The one ordering constraint that survives: the forced import must land before any code that reads
`talkRoomOrigin`. Against an unimported schema the property is absent on every object, which
silently means `bound` — the addressing rule would look correct while never firing. Rollback is
the three levers in the proposal.

## Trade-offs

The alternative shape considered and rejected for the whole change was **keeping one bot and
identifying the agent in the message body**. It is far less code and needs no bot lifecycle at
all. It was rejected because it does not solve the stated problem: the message is still signed
"Hermiq (Bot)", which is the thing a user reads first and the thing that makes two agents in one
Talk sidebar indistinguishable. Per-agent bots also give the per-room enablement Talk already
understands, so an operator can silence one agent in one room from Talk's own UI — which one
shared bot structurally cannot offer.

## Open Questions

None outstanding. The five questions raised while drafting were all answered before implementation
began:

- **Uninstall the bot on agent delete, or keep it so past messages still render under its name?**
  **Resolved: uninstall on delete**, accepting that the agent's past messages fall back to
  `<actorId>-bot`. The alternative — a soft "disable, do not delete" — would preserve history at
  the cost of orphan bot records living forever. See D2 for why the fallback happens at all.
- **Single change as `kind: code`, or a schema → code chain?** Resolved: single change, for the
  reasons under "Mixed-spec rationale" above.
- **Property name and value set.** Resolved: `talkRoomOrigin`, string, `created` | `bound`, absent
  meaning `bound`, no `enum` constraint declared.
- **Backfill `talkRoomOrigin` on existing rooms?** Resolved: no.
- **Migrate the rooms bound to the shared bot?** Resolved: there are none — the instance was
  cleared to the two Hydra agents and their three conversations, and the app is unpublished, so no
  repair step is written.
