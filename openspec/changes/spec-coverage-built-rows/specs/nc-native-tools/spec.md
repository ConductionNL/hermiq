# nc-native-tools

## ADDED Requirements

### Requirement: An agent can read, create and move files in the acting user's Files (REQ-SPCOV-005)

The system SHALL offer agent tools to list and read files, and to create and move a file,
in the acting user's own Files folder only. Creating and moving SHALL be write tools,
ungranted by default. A path outside the acting user's folder SHALL be refused.

Rows: `tl-files`. Board: `hermiq/HmAgentBewerken`.

#### Scenario: An agent reads a file
- **GIVEN** an agent granted `hermiq.readFile`, acting for a user with `Beleid/nota.md`
- **WHEN** a person asks it to summarise `Beleid/nota.md`
- **THEN** the agent reads the file and the answer summarises it

#### Scenario: An agent creates and moves a file
- **GIVEN** an agent granted the file write tools
- **WHEN** a person asks it to write `concept.md` and then move it to `Archief/`
- **THEN** `Archief/concept.md` exists in that person's Files and `concept.md` does not

#### Scenario: A path outside the user's folder is refused
- **WHEN** the agent asks to read `../other-user/files/salaris.xlsx`
- **THEN** the call is refused and no content is returned

### Requirement: An agent can read the calendar and create events (REQ-SPCOV-006)

The system SHALL offer an agent tool that lists the acting user's calendar events in a
period, and a write tool, ungranted by default, that creates an event in a calendar that
user owns and can write.

Rows: `tl-calendar`. Board: `hermiq/HmAgentBewerken`.

#### Scenario: An agent lists tomorrow's events
- **GIVEN** an agent granted `hermiq.listCalendarEvents`
- **WHEN** a person asks "What is in my calendar tomorrow?"
- **THEN** the answer names tomorrow's events from that person's calendars

#### Scenario: An agent creates an event
- **GIVEN** an agent granted `hermiq.createCalendarEvent`
- **WHEN** a person asks it to plan "Overleg bestemmingsplan" on Friday at 10:00
- **THEN** the event appears on Friday at 10:00 in that person's calendar
