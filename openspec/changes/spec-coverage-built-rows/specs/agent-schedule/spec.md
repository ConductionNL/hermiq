# agent-schedule

## ADDED Requirements

### Requirement: A schedule can repeat on an interval or run once (REQ-SPCOV-003)

Besides a cron rule, a person SHALL be able to schedule an agent every given number of
minutes, or once at a set date and time. An interval schedule SHALL fire again that many
minutes after its last due time. A one-time schedule SHALL fire once and SHALL NOT fire
again.

Rows: `sc-interval`, `sc-once`. Board: `hermiq/HmPlanning`.

#### Scenario: An agent runs every thirty minutes
- **GIVEN** a schedule of kind "Interval" with 30 minutes, last due at 09:00
- **WHEN** the dispatcher runs at 09:30
- **THEN** the agent runs, and the next run reads 10:00

#### Scenario: An agent runs once
- **GIVEN** a schedule of kind "Once" set to 14 November 2026 at 16:00
- **WHEN** that time has passed and the dispatcher has run
- **THEN** the agent has run once, and the schedule shows no next run

### Requirement: A schedule keeps its local time across daylight saving changes (REQ-SPCOV-004)

The system SHALL compute a schedule's next run in the time zone of the schedule owner, as
set in their Nextcloud profile, falling back to the instance time zone and then UTC. A
schedule set for 08:00 SHALL keep firing at 08:00 local time when daylight saving time
starts or ends.

Rows: `sc-timezone`. No screen: computed on the server at every run.

#### Scenario: Summer time ends
- **GIVEN** an owner in `Europe/Amsterdam` with the cron rule `0 8 * * *`
- **WHEN** the next run is computed on Saturday 24 October 2026, the day before summer time ends
- **THEN** Sunday's run is at 08:00 Amsterdam time, which is 07:00 UTC

#### Scenario: The owner has no time zone set
- **GIVEN** an owner without a time zone and an instance default of `Europe/Amsterdam`
- **WHEN** the next run is computed
- **THEN** it is computed in `Europe/Amsterdam`

### Requirement: A schedule does not start a new run while its last run is still going (REQ-SPCOV-014)

The dispatcher SHALL run due schedules one tick at a time and SHALL NOT start a tick while the
previous tick is still running. A schedule whose run is still going SHALL therefore not start a
second run, and its next due run SHALL start on the first tick after the running one has ended.

Row: `sc-overlap`. Board: `hermiq/HmPlanning`.

#### Scenario: A long run is not doubled
- **GIVEN** a schedule that runs every five minutes, and a run that started at 08:00 and takes twelve minutes
- **WHEN** the dispatcher's next ticks come due at 08:05 and 08:10
- **THEN** no second run of that schedule starts before 08:12
- **AND** run history shows one run starting at 08:00 and the next one after 08:12
