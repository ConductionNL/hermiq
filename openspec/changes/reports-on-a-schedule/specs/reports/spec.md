# reports

## ADDED Requirements

### Requirement: A person SHALL receive a report on a schedule they choose

A person SHALL be able to subscribe to any report on the Reports page, weekly or monthly, and
cancel the subscription again. A background job SHALL send each due report as a Nextcloud
notification that opens the report for the period it covers. The report SHALL show only what
that person may read, as when they open it themselves.

Rows: `ob-reports` (hermiq matrix).

#### Scenario: A weekly subscription sends the report every week
- **GIVEN** a person subscribed to the AI oversight report weekly
- **WHEN** a week has passed since the last one was sent
- **THEN** they get a notification that opens the AI oversight report for that week

#### Scenario: A cancelled subscription sends nothing
- **GIVEN** a person cancelled their subscription to a report
- **WHEN** the next period ends
- **THEN** no notification for that report reaches them
