# session-channel-continuity Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- chat-carry-a-conversation-across-channels

## Purpose

A person carries one conversation with an agent between a Nextcloud Talk room and the Chat page, in both directions, without losing the thread. Row `hermiq:dm-cross-channel`.

## ADDED Requirements

### Requirement: A session begun in Talk can be continued on the Chat page (REQ-SCHAN-001)

Hermiq MUST list a session bound to a Talk room on `/chat` for its owner and its listed participants, with the room's name as title until the owner renames it. Hermiq MUST let the owner or a listed participant send a turn in that session from `/chat`, with the full history of the turns typed in Talk.

#### Scenario: A buyer picks up on the desktop what she began in Talk
- GIVEN Anne de Vries asked the agent "Inkoopassistent" two questions in the Talk room "Inkoop overleg" on her phone
- WHEN she opens `/chat` on her desktop
- THEN the list shows "Inkoop overleg" with a Talk icon, and opening it shows both questions and both answers
- AND a third question she types there is answered with the first two turns as context
- e2e: `tests/e2e/spec-coverage/chat-carry-a-conversation-across-channels.spec.ts`

#### Scenario: A room member who is not on the roster cannot continue
- GIVEN a member of "Inkoop overleg" who is not the session's owner and not in its participants
- WHEN they send a turn for that session through `/api/chat/send`
- THEN the request MUST be refused and no turn is stored
- @e2e exclude a crafted request as a second user; covered by PHPUnit and Newman

### Requirement: A turn typed on the Chat page reaches the bound room (REQ-SCHAN-002)

When a session has a Talk room, Hermiq MUST post a question typed on `/chat` and the agent's answer into that room through the agent's bot. Hermiq MUST NOT turn a mirrored message into a new turn. A failure to post into the room MUST NOT fail the turn on `/chat`.

#### Scenario: The room sees the question asked on the desktop
- GIVEN the session "Inkoop overleg" bound to its Talk room
- WHEN Anne types "Welke offertes lopen nog?" on `/chat`
- THEN the room shows "Anne de Vries via Hermiq: Welke offertes lopen nog?" followed by the agent's answer
- AND the session holds exactly one new question and one new answer
- e2e: `tests/e2e/spec-coverage/chat-carry-a-conversation-across-channels.spec.ts`

#### Scenario: Talk being down does not stop the chat
- GIVEN Talk refuses the bot message
- WHEN Anne sends a turn on `/chat`
- THEN the answer shows on `/chat` and the failure is logged
- @e2e exclude needs an injected Talk failure; covered by PHPUnit on the mirror job

### Requirement: Each door points at the other (REQ-SCHAN-003)

Hermiq MUST show an "Open in Talk" action on `/chat` for a session with a room. Hermiq MUST end the agent's first answer in a room with a link to that session on `/chat`.

#### Scenario: From the Chat page to the room
- GIVEN a session with a Talk room
- WHEN the owner chooses "Open in Talk" in the session's menu
- THEN the Talk room opens
- e2e: `tests/e2e/spec-coverage/chat-carry-a-conversation-across-channels.spec.ts`

#### Scenario: From the room to the Chat page
- GIVEN a room where the agent has not answered before
- WHEN the agent answers there for the first time
- THEN the answer ends with "Continue in Hermiq:" and a link to `/chat?session=<uuid>`
- AND the agent's second answer in that room carries no such link
- @e2e exclude needs a live Talk round-trip; covered by PHPUnit on the answer formatter and a live check

### Requirement: Each turn records where it was typed (REQ-SCHAN-004)

Hermiq MUST record on every human turn whether it was typed in Talk or on the Chat page, and MUST show "via Talk" under a turn typed in Talk.

#### Scenario: A mixed thread shows its origins
- GIVEN a session with two turns typed in Talk and one on `/chat`
- WHEN the owner opens it on `/chat`
- THEN the two Talk turns show "via Talk" and the third shows no channel line
- e2e: `tests/e2e/spec-coverage/chat-carry-a-conversation-across-channels.spec.ts`
