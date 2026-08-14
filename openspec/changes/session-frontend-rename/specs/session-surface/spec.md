# Spec: session-surface

## ADDED Requirements

### Requirement: The application MUST use one word for a session

Every user-visible string, component name, store, route name and API call on this surface says "session". "Conversation" survives only in the deprecated `/api/conversations/*` aliases and the comments explaining them.

#### Scenario: A user reads the interface
- **WHEN** a user opens the session surface in English or in Dutch
- **THEN** no rendered string says "conversation", including strings that come from the Dutch catalogue rather than the English source

#### Scenario: A developer greps the frontend
- **WHEN** `grep -ri conversation src/` is run after the change
- **THEN** the only hits are deliberate references to the deprecated API aliases, each explained by a comment

### Requirement: Starting a new session MUST produce a visible result

The control currently clears the active thread so the agent grid shows. When no thread is open there is nothing to clear, so it does nothing observable and reads as broken.

#### Scenario: A session is open
- **WHEN** the user has a session open and activates the new-session control
- **THEN** the thread closes and the start-a-session surface is shown

#### Scenario: No session is open
- **WHEN** no session is open and the user activates the new-session control
- **THEN** the interface still changes visibly rather than appearing to ignore the click

### Requirement: The empty state MUST invite starting a session

#### Scenario: No session selected
- **WHEN** the user has no session selected
- **THEN** the thread column shows a "Start a session" empty state rather than a bare grid of agent cards

#### Scenario: Agent cards are fully visible
- **WHEN** the start-a-session surface renders its agent cards
- **THEN** the first row is fully visible, not clipped above the top of its scroll container

### Requirement: A session row MUST identify its agent and its time

#### Scenario: Reading the session list
- **WHEN** the session list renders a row
- **THEN** the row shows the agent's icon and the session's time, not a bare date

### Requirement: Session row actions MUST live in an action menu

#### Scenario: Acting on a session
- **WHEN** the user opens a session row's action menu
- **THEN** Archive, Delete and Continue are offered, replacing the single archive button

### Requirement: Human and automated sessions MUST be listed separately

"Active" currently contains both a human's chat and a session a cron, event, or flow started. They are not the same thing to a user deciding what needs attention.

#### Scenario: An automated session exists
- **WHEN** a session carries a trigger origin of `cron`, `event`, or `flow`
- **THEN** it appears in the automated group and NOT in the human group

#### Scenario: A human session exists
- **WHEN** a session carries trigger origin `human`
- **THEN** it appears in the human group and NOT in the automated group

#### Scenario: Verifying the split
- **WHEN** the split is tested
- **THEN** it is tested against a session that actually carries a non-`human` origin — because every migrated session is `human`, so an empty automated group renders identically whether the split works or is broken, and proves nothing

### Requirement: UI verification MUST run against a confirmed-fresh bundle

Nextcloud's `?v=` cache-buster is keyed on the app version. Rebuilding the frontend without bumping it leaves the browser running the previous bundle, so the UI can appear unchanged after a correct fix — or appear correct after a broken one.

#### Scenario: Verifying any of the above in a browser
- **WHEN** a UI claim is about to be made
- **THEN** the app version has been bumped and the served bundle has been confirmed to contain a string unique to the change, before the observation is trusted
