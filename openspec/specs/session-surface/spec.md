# session-surface Specification

## Purpose
The session surface is hermiq's Chat page: the list of a user's sessions with agents, the thread of the open session, and the start-a-session screen. This spec fixes its vocabulary (one word, session), how sessions are listed and grouped, what each row shows and offers, and how a UI claim about the page is verified.

## Requirements

### Requirement: The application MUST use one word for a session

Every user-visible string, component name, store, route name and API call on the session surface (the Chat page and its rename and delete modals) MUST say "session". "Conversation" survives in the frontend only where it names something else or something deprecated: the Talk bridge, where it is Nextcloud Talk's own word for a room; the wire keys the chat and stream endpoints read; the `hermiq-skill-conversational-authoring` spec name; and the comments that explain the deprecated `/api/conversations/*` aliases.

#### Scenario: A user reads the interface
- **WHEN** a user opens the session surface in English or in Dutch
- **THEN** no rendered string says "conversation", including strings that come from the Dutch catalogue rather than the English source

#### Scenario: A developer greps the frontend
- **WHEN** `grep -ri conversation src/` is run after the change
- **THEN** the only hits are the Talk bridge, the chat and stream wire keys, the conversational-authoring spec name, and the comments explaining the deprecated API aliases

### Requirement: Starting a new session MUST produce a visible result

The new-session control MUST change something the user can see, whether or not a session is open. With a session open it closes the thread and shows the start-a-session surface; with none open it moves focus to the first agent card's start button, so the click is never silently ignored.

#### Scenario: A session is open
- **WHEN** the user has a session open and activates the new-session control
- **THEN** the thread closes and the start-a-session surface is shown

#### Scenario: No session is open
- **WHEN** no session is open and the user activates the new-session control
- **THEN** the interface still changes visibly rather than appearing to ignore the click

### Requirement: The empty state MUST invite starting a session

When no session is selected, the thread column MUST show a "Start a session" empty state above the agent cards, and the cards MUST NOT be clipped by their scroll container.

#### Scenario: No session selected
- **WHEN** the user has no session selected
- **THEN** the thread column shows a "Start a session" empty state rather than a bare grid of agent cards

#### Scenario: Agent cards are fully visible
- **WHEN** the start-a-session surface renders its agent cards
- **THEN** the first row is fully visible, not clipped above the top of its scroll container

### Requirement: A session row MUST identify its agent and its time

Each row in the session list MUST show an icon for how the session started (by a person, cron, event or flow), the session title, and a line with the agent's name and the time the session was last updated. When the agent is no longer known, the line MUST show the time alone rather than an id the user cannot read.

#### Scenario: Reading the session list
- **WHEN** the session list renders a row for a session whose agent is known
- **THEN** the row shows the origin icon, the title, and the agent's name followed by the session's time, not a bare date

#### Scenario: The agent has been deleted
- **WHEN** the session list renders a row whose agent is not in the loaded agent list
- **THEN** the row's meta line shows only the session's time

### Requirement: Session row actions MUST live in an action menu

Each session row MUST carry a "Session actions" menu instead of a single archive button. The menu MUST offer Continue (disabled for the session already open), Archive session (Restore session on the Archive tab) and Delete session. Archive, Restore and Delete MUST NOT be offered to a user who only takes part in the session; Invite colleagues MUST appear only when the user may invite.

#### Scenario: Acting on an own session
- **WHEN** the owner of a session opens its row's action menu on the Active tab
- **THEN** Continue, Archive session and Delete session are offered, replacing the single archive button

#### Scenario: Acting on an archived session
- **WHEN** the owner opens the action menu of a row on the Archive tab
- **THEN** Restore session is offered in place of Archive session

#### Scenario: A participant opens the menu
- **WHEN** a user whose role in the session is participant opens the row's action menu
- **THEN** Continue is offered and Archive session, Restore session and Delete session are not

### Requirement: Human and automated sessions MUST be listed separately

The session list MUST group the user's own sessions by their trigger origin: "Started by you" for origin `human`, "Started automatically" for `cron`, `event` or `flow`. Sessions the user only takes part in sit in a third group, "Shared with me". When only one group has sessions, its heading is left out because the tab label already says what it holds. A person's chat and a session a schedule started are not the same thing to a user deciding what needs attention.

#### Scenario: An automated session exists
- **WHEN** a session carries a trigger origin of `cron`, `event`, or `flow`
- **THEN** it appears in the automated group and NOT in the human group

#### Scenario: A human session exists
- **WHEN** a session carries trigger origin `human`
- **THEN** it appears in the human group and NOT in the automated group

#### Scenario: Verifying the split
- **WHEN** the split is tested
- **THEN** it is tested against a session that actually carries a non-`human` origin, because every migrated session is `human`, so an empty automated group renders identically whether the split works or is broken, and proves nothing

### Requirement: UI verification MUST run against a confirmed-fresh bundle

A UI claim about this surface MUST be made against a bundle confirmed to be the one just built. Nextcloud's `?v=` cache-buster is keyed on the app version, so rebuilding the frontend without bumping it leaves the browser running the previous bundle: the UI can look unchanged after a correct fix, or correct after a broken one.

#### Scenario: Verifying any of the above in a browser
- **WHEN** a UI claim is about to be made
- **THEN** the app version has been bumped and the served bundle has been confirmed to contain a string unique to the change, before the observation is trusted
