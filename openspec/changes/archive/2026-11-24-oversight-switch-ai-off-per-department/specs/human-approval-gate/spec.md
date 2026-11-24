# human-approval-gate

## ADDED Requirements

### Requirement: An admin SHALL be able to switch AI off for one department

The system SHALL let an admin mark Nextcloud groups as departments and let each agent name the
department it runs for. The set of departments SHALL be configuration, never a fixed list in code.
An admin SHALL be able to switch AI off for one department. While a department is off, the system
SHALL refuse every new run of that department's agents and record each refusal in the audit trail.
Runs of other departments SHALL continue. The organisation-wide kill-switch SHALL still stop
everything, whatever the department switches say.

Rows: `co-disable-dept` (hermiq matrix).

#### Scenario: A department that is off refuses its agents' runs
- **GIVEN** the departments Burgerzaken and Sociaal domein, each with an agent
- **WHEN** an admin switches AI off for Sociaal domein and both agents are due to run
- **THEN** the Sociaal domein agent's run is refused and the refusal is recorded
- **AND** the Burgerzaken agent's run goes ahead

#### Scenario: The organisation kill-switch wins over a department that is on
- **GIVEN** a department whose AI switch is on
- **WHEN** an admin engages the organisation-wide kill-switch
- **THEN** that department's agents do not run either

#### Scenario: Only an admin switches a department
- **WHEN** a user who is not an admin tries to switch a department off
- **THEN** the system refuses the change and the department's state stays as it was

### Requirement: An admin SHALL be able to download evidence that AI was off for a department

For each department the system SHALL show whether AI is on or off, who switched it off and when,
and how many runs it refused since. An admin SHALL be able to download an evidence file for a
department. The file SHALL list each switch event with who and when, and every run refused while
the department was off, taken from the audit trail.

Rows: `co-disable-dept` (hermiq matrix).

#### Scenario: The evidence file lists the switch and the refused runs
- **GIVEN** an admin switched Sociaal domein off at 14:00 and one run was refused at 15:10
- **WHEN** the admin downloads the evidence for Sociaal domein
- **THEN** the file names the admin, the time 14:00, and the run refused at 15:10

#### Scenario: The department list shows who switched it off
- **WHEN** an admin opens the approvals page while Sociaal domein is off
- **THEN** the department shows "off", the person who switched it off, the time, and the count of refused runs
