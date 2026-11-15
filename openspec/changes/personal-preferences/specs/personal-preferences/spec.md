# personal-preferences

## ADDED Requirements

### Requirement: A person's agent preferences are kept per person and set in hermiq's settings dialog (REQ-PREF-001)

hermiq SHALL store each agent preference per Nextcloud user through the preferences endpoint
(`GET`/`PUT /api/preferences/{key}`, `PreferencesController`), so one person's choice never changes
another person's. The preferences SHALL be set in a section of hermiq's user settings dialog (the
`NcAppSettingsDialog` that `CnAppRoot` hosts), opened from the app navigation. hermiq SHALL NOT
register a personal settings page under `/settings/user/hermiq` and SHALL NOT add an in-app route
for these preferences.

Rows: `op-preferences`. Board: `hermiq/HmMijnInstellingen`.

#### Scenario: A preference is stored for the person who sets it
- **GIVEN** Pieter and Anouk both use hermiq
- **WHEN** Pieter sets "Taal van antwoorden" to "Nederlands, B1" in the settings dialog
- **THEN** reading the preference as Pieter returns "Nederlands, B1"
- **AND** reading the same preference as Anouk returns her own value, or none

@e2e exclude needs a second Nextcloud user; covered by a unit test of PreferencesController.

#### Scenario: The preferences open in the settings dialog
- **GIVEN** a signed-in user in hermiq
- **WHEN** they open the settings from the app navigation
- **THEN** the settings dialog shows the "Voorkeuren" section
- **AND** no personal settings page for hermiq exists in Nextcloud's personal settings

### Requirement: The Voorkeuren section offers the drawn preferences (REQ-PREF-002)

The "Voorkeuren" section SHALL offer the four preferences drawn on `hermiq/HmMijnInstellingen`:
the language agents answer in ("Taal van antwoorden"), the agent the chat starts with
("Standaardagent in de chat"), asking before an agent mails or sends something on the person's
behalf ("Vragen voordat een agent namens mij mailt of iets verstuurt"), and a notification when a
run is done ("Melding als een run klaar is"). Each change SHALL be saved at once and shown again
when the dialog reopens. The ask-before-sending preference SHALL only add an approval step for runs
started by that person; it SHALL NOT remove an approval that an agent, a tool or an administrator
requires.

Rows: `op-preferences`. Board: `hermiq/HmMijnInstellingen`.

#### Scenario: A chosen default agent opens the chat
- **GIVEN** a person who set "Standaardagent in de chat" to "Hulp bij Woo-besluit"
- **WHEN** they start a new chat
- **THEN** the chat starts with the agent "Hulp bij Woo-besluit"

#### Scenario: Asking before sending adds an approval
- **GIVEN** a person who switched on "Vragen voordat een agent namens mij mailt of iets verstuurt"
- **AND** an agent with a mail tool that needs no approval
- **WHEN** that person starts a run in which the agent sends a mail
- **THEN** the run asks the person for approval before the mail is sent

#### Scenario: Switching the preference off keeps required approvals
- **GIVEN** a tool that an administrator set to always need approval
- **AND** a person who switched "Vragen voordat een agent namens mij mailt of iets verstuurt" off
- **WHEN** that person starts a run that calls the tool
- **THEN** the run still asks for approval

@e2e exclude needs an administrator-configured approval policy on a tool; covered by a unit test of the approval check.

#### Scenario: A notification when a run is done
- **GIVEN** a person who switched on "Melding als een run klaar is"
- **WHEN** a run they started finishes
- **THEN** they receive a Nextcloud notification naming the agent and the result
