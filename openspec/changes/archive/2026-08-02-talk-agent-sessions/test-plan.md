# Test Plan: talk-agent-sessions

The load-bearing point of this plan: **the approval-reaction path cannot be proved by a green
suite.** It was 100% inert until PR #75 and this change edits its guard. TC-9, TC-10 and TC-11 are
live regression checks and are mandatory, not optional confirmation.

## Test Cases

### TC-1: A Talk-enabled agent gets a bot under its own name
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity`
- **type**: functional
- **preconditions**: Two seeded agents with distinct names, spreed 24.0.1 installed
- **steps**: Talk-enable both agents; open Hermiq admin settings → Talk bridge
- **expected result**: Two bot records are reported, one per agent, each named after its agent; each URL is `nextcloudapp://hermiq-<agentId>`
- **test command**: `/test-functional`

### TC-2: Renaming an agent renames its bot and creates no second one
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle`
- **type**: functional
- **preconditions**: A Talk-enabled agent with a registered bot
- **steps**: Rename the agent; re-read the admin Talk-bridge surface
- **expected result**: Exactly one bot for that agent, under the new name. The rename must reuse the same derived secret — a changed secret on the same URL is rejected by spreed
- **test command**: `/test-functional`

### TC-3: Disabling an agent uninstalls its bot
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle`
- **type**: functional
- **preconditions**: A Talk-enabled agent with a registered bot
- **steps**: Turn the Talk opt-in off; re-read the admin surface
- **expected result**: No bot is reported for that agent, and messages in its former room produce no turn
- **test command**: `/test-functional`

### TC-4: A new session creates, names and owns its room
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room`
- **type**: functional
- **preconditions**: A Talk-enabled agent
- **steps**: Create a chat session with it from Hermiq's UI
- **expected result**: The session carries a `talkRoomToken` and `talkRoomOrigin: "created"`; the room is named after the session; the owner is a participant; the agent's bot is enabled in it
- **test command**: `/test-functional`

### TC-5: A session for a Talk-disabled agent gets no room, and a room failure does not fail the session
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room`
- **type**: regression
- **preconditions**: One Talk-disabled agent; a second run with room creation forced to fail
- **steps**: Create a session in each case; then chat in it from Hermiq's own UI
- **expected result**: No room token in either case, no error surfaced to the user, and the session is fully usable in Hermiq's UI
- **test command**: `/test-functional`

### TC-6: Renaming a session renames its own room but not a borrowed one
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-renaming-a-session-renames-its-room`
- **type**: functional
- **preconditions**: One session with `talkRoomOrigin: "created"`, one seeded with `"bound"`
- **steps**: Rename both sessions
- **expected result**: The created room's name follows; the bound room's name is unchanged
- **test command**: `/test-functional`

### TC-7: The addressing rule follows the room's recorded origin
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room`
- **type**: regression
- **preconditions**: One room Hermiq created for a session; one team room the agent was invited into; both with the agent's bot enabled
- **steps**: In each room, send (a) an unaddressed message, (b) a message naming the agent, (c) a reply to one of the agent's messages
- **expected result**: In the created room all three are answered. In the invited-into room only (b) and (c) are answered and (a) produces no turn. Origin is read from stored data — inviting a second user into the created room must not change any of this
- **test command**: `/test-regression`

### TC-8: Mention matching tolerates real agent names
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room`
- **type**: functional
- **preconditions**: An agent whose name is more than one word
- **steps**: Unit-drive the matcher with the exact name, a lower-cased name, a name followed by a comma, a name followed by a question mark, and an unrelated word that merely shares a prefix
- **expected result**: The first four match; the last does not. A non-match yields no turn and raises nothing. Matching runs on the DECODED text, since `object.content` is a JSON envelope
- **test command**: `/test-functional`

### TC-9: An approval posted to a room still records its room token and message id
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-hermiq-recognises-any-of-its-own-bot-urls-and-resolves-the-agent-from-it`
- **type**: regression
- **preconditions**: A gated run whose reviewer receives requests in a Talk room, after the guard rework
- **steps**: Trigger the gated run; read the resulting `Approval` object
- **expected result**: The approval carries the room's token and the posted message's id
- **test command**: `/test-regression` — LIVE against spreed 24.0.1; a unit test does not close this

### TC-10: The reviewer's 👍 approves and 👎 denies with `decidedVia=reaction`
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-hermiq-recognises-any-of-its-own-bot-urls-and-resolves-the-agent-from-it`
- **type**: regression
- **preconditions**: Two pending approvals bound to messages posted by a per-agent bot
- **steps**: As the resolved reviewer, react 👍 to one and 👎 to the other in Talk
- **expected result**: One becomes approved, one denied; both record the reviewer as deciding user and `reaction` as the surface
- **test command**: `/test-regression` — LIVE; this is the path the guard rework crosses

### TC-11: A non-reviewer's 👍 leaves the approval pending
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-hermiq-recognises-any-of-its-own-bot-urls-and-resolves-the-agent-from-it`
- **type**: security
- **preconditions**: A pending approval whose reviewer is another user
- **steps**: As a different room participant, react 👍 to the bound message
- **expected result**: The approval remains pending and no decision is recorded. Loosening the bot-URL guard must not have loosened the reviewer check
- **test command**: `/test-security` — LIVE

### TC-12: A foreign bot invocation is ignored by both listeners
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-hermiq-recognises-any-of-its-own-bot-urls-and-resolves-the-agent-from-it`
- **type**: security
- **preconditions**: Unit harness dispatching `BotInvokeEvent` with foreign and malformed bot URLs
- **steps**: Dispatch a non-Hermiq URL, a bare `nextcloudapp://hermiq`, and `nextcloudapp://hermiq-` with an empty id
- **expected result**: `agentIdFromBotUrl()` returns null for each; neither listener acts; no turn and no approval decision
- **test command**: `/test-security`

### TC-13: Room participants become session participants, and authorization reads the stored roster
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-room-participants-become-session-participants`
- **type**: security
- **preconditions**: A session that owns a room; a second and a third user
- **steps**: Add the second user to the room; leave the third out; have each attempt a turn. Separately, place a user in the Talk room but strip them from the stored roster
- **expected result**: The second user is in the roster and answered; the third is refused with nothing persisted; the stripped-from-roster user is refused even though they are in the room. No bot and no duplicate owner in the roster
- **test command**: `/test-security`

### TC-14: Created rooms and late joiners are filed under the Hermiq tag
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-room-grouping/spec.md#requirement-a-participant-who-joins-later-is-filed-too`
- **type**: functional
- **preconditions**: Two users, one with grouping enabled and one who has opted out
- **steps**: Create a session room; add the second user later; repeat with the opted-out user; pre-assign a user tag to the room first
- **expected result**: The room is grouped at creation without any message being sent; the late joiner sees it grouped; the opted-out user gets no tag and no assignment; pre-existing user tags survive
- **test command**: `/test-functional`

### TC-15: The register change lands and is readable
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room`
- **type**: api
- **preconditions**: Register bumped to `info.version` 0.22.0 and imported with `force: true`
- **steps**: Read the `conversation` schema from the live register; save a `Conversation` with and without `talkRoomOrigin`
- **expected result**: `talkRoomOrigin` is present on the live schema, persists when set, and is optional when absent. A version bump without the property means the import silently no-opped — the specific `force: false` failure
- **test command**: `/test-api`

### TC-16: The two surviving Hydra agents keep working across the bot switch
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle`
- **type**: regression
- **preconditions**: The cleaned instance — agents `Hydra Triage` and `Hydra Applier — Axel Pliér`, and their three conversations
- **steps**: Talk-enable each agent; confirm a per-agent bot record appears; post from each; then read the three existing conversations back
- **expected result**: Each agent posts under its own name. The three pre-existing conversations still load, still carry no `talkRoomOrigin`, and therefore still apply the mention gate — the pre-change behaviour, unchanged
- **test command**: `/test-regression` — LIVE

### TC-17: Hermiq behaves unchanged with Talk absent
- **spec_ref**: `openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle`
- **type**: regression
- **preconditions**: An instance without spreed
- **steps**: Boot Hermiq; create and rename a session; enable and rename an agent; open admin settings
- **expected result**: Everything succeeds, no room is created, no bot is touched, and admin settings render with an explicit unavailable state
- **test command**: `/test-regression`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| Each Talk-enabled agent has its own Talk bot identity | TC-1 |
| An agent's bot follows the agent's lifecycle | TC-2, TC-3, TC-16, TC-17 |
| Hermiq recognises any of its own bot URLs and resolves the agent from it | TC-9, TC-10, TC-11, TC-12 |
| Creating a chat session creates and owns its Talk room | TC-4, TC-5, TC-15 |
| Renaming a session renames its room | TC-6 |
| Room participants become session participants | TC-13 |
| The agent responds only when addressed in a group room (MODIFIED) | TC-7, TC-8 |
| A room Hermiq creates for a session is filed at creation | TC-14 |
| A participant who joins later is filed too | TC-14 |

Every requirement in this change is covered. TC-9 through TC-11 and TC-16 must be executed live
against spreed 24.0.1; the rest may be automated.

## Out of Scope

- **The agent's avatar/icon.** Not a requirement — spreed's bot table has no avatar column, so
  there is nothing to test. See design.md D3.
- **Deep links for actions.** Explicitly dropped; approvals stay reaction-decided.
- **Retro-creating rooms for sessions that already exist without one.** Not in scope, so no test.
- **Talk client rendering itself.** Several scenarios carry `@e2e exclude` because they require
  driving Talk's own web or mobile client, which this Playwright suite does not do. They are
  covered by the live checks above instead of being silently uncovered.
