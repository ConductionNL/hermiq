## ADDED Requirements

### Requirement: A session MUST be able to record what it is about

A chat session MUST support zero or more links to entities it concerns. A link MUST
carry a `type`, an `id`, a human-readable `label`, and — where the type needs it to be
resolvable — a `schema`.

The set of types MUST be open: adding "contact" or "calendar event" MUST NOT require a
new property. A property per kind makes every new kind a schema migration and makes
"sessions about X" a different query per kind.

#### Scenario: A session links to a file

- **GIVEN** a session started from a page showing file `24753`
- **WHEN** the user sends a turn
- **THEN** the session MUST carry a link of type `file` with id `24753`
- **AND** the link MUST carry a label naming the file

#### Scenario: A session links to entities of different kinds at once

- **GIVEN** a session already linked to a file
- **WHEN** a turn is sent from a page showing an OpenRegister object
- **THEN** the session MUST carry both links
- **AND** neither MUST replace the other

#### Scenario: A link survives its target

- **GIVEN** a session linked to an object that is later deleted
- **WHEN** the session list is rendered
- **THEN** the session MUST still display what it was about, from the stored label
- **AND** rendering MUST NOT require resolving the target

### Requirement: A link MUST be recorded on use, not on view

A link MUST be created when a turn is sent from a context, and MUST NOT be created by
opening the companion alone.

Opening a document and glancing at the assistant is not a statement that the session
is about that document. Recording on open fills every list with pages someone passed
through, and turns an assistant into a record of what a user looked at.

#### Scenario: Opening the panel records nothing

- **GIVEN** a user opens the companion on a page showing a document
- **WHEN** they close it without sending anything
- **THEN** no link MUST be recorded

#### Scenario: Re-sending in the same context does not duplicate

- **GIVEN** a session already linked to file `24753`
- **WHEN** a second turn is sent from the same page
- **THEN** the session MUST still carry exactly one link to `24753`
- **AND** that link's last-used timestamp MUST be updated

### Requirement: A context MUST narrow the session list, and the narrowing MUST be visible

When the companion is opened with a context, the recent-session list MUST be filtered
to sessions linked to that entity, MUST be headed with the entity it is scoped to, and
MUST offer an unscoped "view all sessions" path.

An unlabelled filtered list is worse than no filter: a user who cannot see that a list
is scoped reads an empty one as "I have no sessions", not as "none about this".

#### Scenario: The scoped list names its scope

- **GIVEN** the companion opened on `subsidiebesluit.docx`
- **WHEN** the sessions menu is shown
- **THEN** the recent list MUST be headed with that document's label
- **AND** an unscoped "view all sessions" entry MUST be present

#### Scenario: The filter is applied server-side

- **GIVEN** 60 sessions exist, of which the only one linked to file `24753` is the 55th most recent
- **WHEN** the scoped list is requested
- **THEN** that session MUST appear
- **AND** the filter MUST NOT be a client-side pass over a fixed-size page

#### Scenario: No context means no scoping

- **GIVEN** the companion opened on a page with no file or object reference
- **WHEN** the sessions menu is shown
- **THEN** the unscoped recent list MUST be shown, with no scope heading

### Requirement: A recorded link MUST be visible and removable by the user it describes

The session's settings surface MUST list its links and MUST allow removing one.

A link recorded automatically, and only removable automatically, is a tracker. The
record is of which documents a person consulted an assistant about, which is sensitive
independently of the documents themselves.

#### Scenario: A user removes a link

- **GIVEN** a session with a link recorded automatically
- **WHEN** the user removes it from session settings
- **THEN** the session MUST no longer carry that link
- **AND** the session MUST NOT reappear in that entity's scoped list

### Requirement: A stored label MUST NOT disclose more than the session already does

The denormalised label MUST carry only what a reader of the session could obtain
anyway. For a file it MUST be the display name, never the full path.

The label is written from what the LINKING user could see and is then shown to every
reader of the session; a path leaks folder structure that a reader may have no access
to.

#### Scenario: A file label carries no path

- **GIVEN** a session linked to a file in a nested folder
- **WHEN** its link label is stored
- **THEN** the label MUST be the file's display name
- **AND** MUST NOT contain the containing folder path

### Requirement: New user-facing text MUST say "session"

Every user-facing string introduced by this change MUST use "session". Existing
identifiers, routes and stored properties MUST be left alone.

"Session" is the settled term, but `Session` is already a DIFFERENT schema in this
register (the FTS5 recall port, alongside `SessionTurn`), so renaming `Conversation`
is a migration with a name collision in it — see `sessions-terminology`. Converging
the vocabulary in new strings costs nothing and blocks on nothing; renaming stored
properties in the same change would bury this capability under a migration.

#### Scenario: A new string says session

- **GIVEN** a string added by this change
- **WHEN** it is shown to a user
- **THEN** it MUST say "session" rather than "conversation"

### Requirement: The composer MUST offer speech input beside attachment

The message composer MUST present a speech-input control immediately to the left of
the attachment control.

Both act on the message being composed, so both belong on the input row rather than in
the titlebar, which names the session and switches context.

⚠️ This is an ADDITION, not a restoration. hermiq ships `SpeechClient`,
`AudioToTextProvider` and `TextToSpeechProvider`, and a speech container runs — but no
speech control has ever existed in this panel. It was reported as a regression and is
not one.

#### Scenario: The speech control sits left of the attachment control

- **GIVEN** the chat window is open
- **WHEN** the composer is rendered
- **THEN** a speech-input control MUST be present
- **AND** it MUST precede the attachment control in the row
