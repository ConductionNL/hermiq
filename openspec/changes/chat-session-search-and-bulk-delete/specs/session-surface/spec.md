# session-surface Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- chat-session-search-and-bulk-delete

## Purpose

A person finds a past session by what was said in it, and archives, restores or deletes several sessions at once. Rows `hermiq:ch-search-history` and `hermiq:dm-bulk-delete-chats`.

## ADDED Requirements

### Requirement: A person can search their own sessions by title and content (REQ-SSRCH-001)

Hermiq MUST accept a `search` term on `GET /api/sessions` and MUST return only the caller's own sessions whose title or turn text matches it, within the tab asked for. Each result MUST carry an excerpt and the uuid of the first matching turn. Without a `search` term the list MUST behave as before.

#### Scenario: A tax officer finds the session about a WOZ objection
- GIVEN a municipal tax officer with 40 sessions, one of which contains the turn "Hoe beoordeel ik een WOZ-bezwaar over een monument?"
- WHEN they type "monument" in the search field on `/chat`
- THEN the list shows that one session with the excerpt, and opening it scrolls to the matching turn
- e2e: `tests/e2e/spec-coverage/session-search-and-bulk.spec.ts`

#### Scenario: Another person's words never appear
- GIVEN a shared session owned by a colleague in which the officer took part
- WHEN the officer searches for a word only that session contains
- THEN the search returns no result
- @e2e exclude needs two identities and a shared session; covered by PHPUnit and a Newman call

### Requirement: A person can archive, restore or delete several sessions in one action (REQ-SSRCH-002)

Hermiq MUST offer `POST /api/sessions/bulk` with an action of `archive`, `restore` or `delete` and 1 to 100 session uuids. It MUST check each uuid as the caller's own and MUST answer per uuid. A uuid that is not the caller's MUST be reported the same way as a missing one.

#### Scenario: A team lead clears out last year's test sessions
- GIVEN a team lead on the Archive tab of `/chat` with 12 archived test sessions
- WHEN they choose "Select", tick all 12 and choose Delete, then confirm "Delete 12 sessions? This cannot be undone."
- THEN all 12 sessions leave the list, and a reload does not bring them back
- e2e: `tests/e2e/spec-coverage/session-search-and-bulk.spec.ts`

#### Scenario: A foreign uuid in the batch changes nothing
- GIVEN a bulk request that names two of the caller's sessions and one of a colleague's
- WHEN the caller sends it with action `delete`
- THEN the two own sessions are deleted, the colleague's session is untouched, and its entry in the answer says `not_found`
- @e2e exclude a crafted request body; covered by PHPUnit and Newman

### Requirement: Deleting a session removes everything that belongs to it (REQ-SSRCH-003)

Every permanent delete of a session, single or bulk, MUST remove its feedback, then its turns, then the session itself.

#### Scenario: No feedback is left behind
- GIVEN a session with two turns that carry thumbs-up feedback
- WHEN its owner deletes it permanently
- THEN no feedback object with that session's id remains
- @e2e exclude orphan objects are not visible in the page; covered by PHPUnit on the shared routine
