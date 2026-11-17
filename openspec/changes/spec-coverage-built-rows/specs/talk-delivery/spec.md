# talk-delivery

## ADDED Requirements

### Requirement: A person chooses where their agents deliver by default (REQ-SPCOV-011)

A person SHALL be able to pick a Talk room as the default target for their agents' output.
A schedule with Talk delivery and no room of its own SHALL post to that room. Without a
default, delivery SHALL fall back to Note-to-self.

Rows: `dl-target-pref`. Board: `hermiq/HmMijnInstellingen`.

#### Scenario: A schedule without a room posts to the default
- **GIVEN** a person who set "Team vergunningen" as their default delivery room
- **WHEN** their schedule with Talk delivery and no room completes a run
- **THEN** the output is posted in "Team vergunningen"

#### Scenario: No default is set
- **GIVEN** a person without a default delivery room
- **WHEN** their schedule with Talk delivery and no room completes a run
- **THEN** the output is posted to their Note-to-self
